<?php

/**
 * MySQL/MariaDB verification.
 *
 *   php tests/mysql/verify.php
 *
 * Deliberately outside tests/run.php, which boots on in-memory SQLite by
 * design — fast, dependency-free, and the right default. The cost of that
 * default is a handful of statements the suite can never execute:
 *
 *   - the ON DUPLICATE KEY upsert in DatabaseRateLimitStore (the sqlite branch
 *     is ON CONFLICT, and they are different statements)
 *   - DELETE ... LIMIT in the rate-limit prune
 *   - the raw, hand-written UPDATEs in CampaignRecipientRepository, where the
 *     concurrency control lives
 *   - the conditional UPDATE that makes a password-reset token single-use
 *   - send_status as VARCHAR rather than a CHECK constraint
 *
 * Every one of those is a place where a syntax or binding error would pass CI
 * and fail in production. This runs them.
 *
 * READS AND WRITES THE DATABASE IN .env. Point it at a scratch database, never
 * at production: it inserts a campaign and a contact, and it bumps a user's
 * session version. It cleans up the rows it creates.
 */
declare(strict_types=1);

require __DIR__ . '/../../bootstrap/autoload.php';

use App\Core\Application;
use App\Core\DatabaseRateLimitStore;
use App\Core\RateLimitStore;
use App\Database\Connection;

$container  = Application::bootConsole(dirname(__DIR__, 2))->container();
$connection = $container->make(Connection::class);

$ok      = static fn (string $what, bool $pass): string => ($pass ? '  PASS  ' : '  FAIL  ') . $what;
$results = [];

if ($connection->driver() !== 'mysql') {
    fwrite(STDERR, 'This script needs DB_DRIVER=mysql. The driver in .env is '
        . $connection->driver() . '.' . PHP_EOL);
    exit(2);
}

// ---- 1. the rate limit store is the database one, on the MySQL branch
$store = $container->make(RateLimitStore::class);
$results[] = $ok('RateLimitStore resolves to DatabaseRateLimitStore', $store instanceof DatabaseRateLimitStore);
$results[] = $ok('driver is mysql', $connection->driver() === 'mysql');

$key = 'verify:' . bin2hex(random_bytes(6));

// ---- 2. the atomic upsert counts, rather than inserting twice
$store->forget($key);
$first  = $store->increment($key, 60);
$second = $store->increment($key, 60);
$third  = $store->increment($key, 60);
$results[] = $ok("increment() counts 1,2,3 (got {$first},{$second},{$third})", [$first, $second, $third] === [1, 2, 3]);

$rows = (int) $connection->scalar('SELECT COUNT(*) FROM rate_limits WHERE limit_key = ?', [$key]);
$results[] = $ok("one row per key (got {$rows})", $rows === 1);
$results[] = $ok('get() agrees with increment()', $store->get($key) === 3);
$results[] = $ok('ttl() is in the window', $store->ttl($key) > 0 && $store->ttl($key) <= 60);

// ---- 3. an expired window resets rather than accumulating
$connection->execute(
    'UPDATE rate_limits SET expires_at = ? WHERE limit_key = ?',
    [gmdate('Y-m-d H:i:s', time() - 3600), $key]
);
$afterExpiry = $store->increment($key, 60);
$results[] = $ok("an expired window restarts at 1 (got {$afterExpiry})", $afterExpiry === 1);

// ---- 4. the prune removes expired rows and leaves live ones
$stale = 'verify-stale:' . bin2hex(random_bytes(6));
$connection->table('rate_limits')->insert([
    'limit_key'  => $stale,
    'hits'       => 5,
    'expires_at' => gmdate('Y-m-d H:i:s', time() - 7200),
    'created_at' => gmdate('Y-m-d H:i:s'),
]);
$pruned = $store->purgeExpired();
$results[] = $ok("purgeExpired() removed at least the stale row (removed {$pruned})", $pruned >= 1);
$results[] = $ok('the live row survived the prune', $store->get($key) === 1);
$store->forget($key);
$results[] = $ok('forget() clears the key', $store->get($key) === 0);

// ---- 5. session revocation moves the version forward in SQL
$users = $container->make(App\Repositories\UserRepository::class);
$userId = (int) $connection->scalar('SELECT id FROM users ORDER BY id LIMIT 1');

if ($userId > 0) {
    $before = $users->sessionVersion($userId);
    $after  = $users->revokeSessions($userId);
    $results[] = $ok("revokeSessions() increments ({$before} -> {$after})", $after === $before + 1);
    $results[] = $ok('sessions_revoked_at is stamped', $connection->scalar(
        'SELECT sessions_revoked_at FROM users WHERE id = ?',
        [$userId]
    ) !== null);
} else {
    $results[] = '  SKIP  no user row to revoke';
}

// ---- 6. the recipient repository's raw UPDATEs parse and bind on MySQL
$orgId = (int) $connection->scalar('SELECT id FROM organisations ORDER BY id LIMIT 1');

if ($orgId > 0) {
    $container->make(App\Support\TenantContext::class)->bind($orgId, null, ['id' => $orgId]);
    $recipients = $container->make(App\Repositories\CampaignRecipientRepository::class);

    $campaignId = (int) $connection->table('campaigns')->insert([
        'organisation_id'       => $orgId,
        'uuid'                  => uuid4(),
        'name'                  => 'MySQL path verification',
        'subject'               => 'MySQL path verification',
        'status'                => 'sending',
        'snapshot_completed_at' => gmdate('Y-m-d H:i:s'),
        'created_at'            => gmdate('Y-m-d H:i:s'),
        'updated_at'            => gmdate('Y-m-d H:i:s'),
    ]);

    $email     = 'verify-' . bin2hex(random_bytes(5)) . '@example.com';
    $contactId = (int) $connection->table('contacts')->insert([
        'organisation_id'  => $orgId,
        'uuid'             => uuid4(),
        'email'            => $email,
        'email_normalized' => $email,
        'created_at'       => gmdate('Y-m-d H:i:s'),
        'updated_at'       => gmdate('Y-m-d H:i:s'),
    ]);

    $recipientId = (int) $connection->table('campaign_recipients')->insert([
        'organisation_id'    => $orgId,
        'campaign_id'        => $campaignId,
        'contact_id'         => $contactId,
        'email'              => $email,
        'email_normalized'   => $email,
        'eligibility_status' => 'eligible',
        'send_status'        => 'pending',
        'attempt_count'      => 0,
        'created_at'         => gmdate('Y-m-d H:i:s'),
    ]);

    $token = uuid4();
    $results[] = $ok('claimForSending() claims a pending row', $recipients->claimForSending($recipientId, $token));
    $results[] = $ok('a second claim loses', !$recipients->claimForSending($recipientId, uuid4()));
    $results[] = $ok('markProviderAttempted() needs the token', !$recipients->markProviderAttempted($recipientId, uuid4()));
    $results[] = $ok('markProviderAttempted() works with it', $recipients->markProviderAttempted($recipientId, $token));

    $row = $connection->selectOne('SELECT * FROM campaign_recipients WHERE id = ?', [$recipientId]);
    $results[] = $ok('attempt_count incremented to 1', (int) $row['attempt_count'] === 1);
    $results[] = $ok('provider_attempted_at is set', $row['provider_attempted_at'] !== null);

    $results[] = $ok('markOutcomeUnknown() under the token', $recipients->markOutcomeUnknown($recipientId, $token, 'verification'));
    $results[] = $ok(
        "send_status is outcome_unknown on a varchar column",
        (string) $connection->scalar('SELECT send_status FROM campaign_recipients WHERE id = ?', [$recipientId])
            === 'outcome_unknown'
    );
    $results[] = $ok('awaitingReconciliation() sees it', $recipients->awaitingReconciliation($campaignId) === 1);
    $results[] = $ok('remaining() holds the campaign open', $recipients->remaining($campaignId) === 1);

    // The sweep: a lapsed lease with provider_attempted_at set must NOT be re-queued.
    $connection->execute(
        "UPDATE campaign_recipients SET send_status = 'sending', lock_token = ?, lease_expires_at = ? WHERE id = ?",
        [$token, gmdate('Y-m-d H:i:s', time() - 60), $recipientId]
    );
    $swept = $recipients->sweepExpiredLeases();
    $results[] = $ok(
        'sweepExpiredLeases() holds a maybe-sent row for a person (' . json_encode($swept) . ')',
        $swept['unknown'] === 1 && $swept['recovered'] === 0
    );

    // And one that provably never reached the provider goes back on the queue.
    $connection->execute(
        "UPDATE campaign_recipients SET send_status = 'sending', lock_token = ?, lease_expires_at = ?,
                provider_attempted_at = NULL WHERE id = ?",
        [$token, gmdate('Y-m-d H:i:s', time() - 60), $recipientId]
    );
    $swept = $recipients->sweepExpiredLeases();
    $results[] = $ok(
        'sweepExpiredLeases() re-queues a provably unsent row (' . json_encode($swept) . ')',
        $swept['recovered'] === 1 && $swept['unknown'] === 0
    );

    $results[] = $ok('deferUnclaimed() returns a row to the pool', $recipients->deferUnclaimed($recipientId, 900));
    $results[] = $ok('committedSends() counts queued rows only', $recipients->committedSends() === 0);

    $connection->execute('DELETE FROM campaign_recipients WHERE campaign_id = ?', [$campaignId]);
    $connection->execute('DELETE FROM campaigns WHERE id = ?', [$campaignId]);
    $connection->execute('DELETE FROM contacts WHERE id = ?', [$contactId]);
} else {
    $results[] = '  SKIP  no organisation to bind';
}

// ---- 7. the password-reset redemption UPDATE is a single atomic gate on MySQL
if ($userId > 0) {
    $connection->execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
    $plain = bin2hex(random_bytes(16));
    $connection->table('password_resets')->insert([
        'user_id'    => $userId,
        'token_hash' => hash('sha256', $plain),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);

    $burn = static fn (): int => $connection->execute(
        'UPDATE password_resets SET used_at = ?, updated_at = ? '
        . 'WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
        [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), hash('sha256', $plain), gmdate('Y-m-d H:i:s')]
    );

    $firstBurn  = $burn();
    $secondBurn = $burn();
    $results[] = $ok("the redemption UPDATE affects one row then none ({$firstBurn}, {$secondBurn})",
        $firstBurn === 1 && $secondBurn === 0);
    $connection->execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
}

echo implode(PHP_EOL, $results) . PHP_EOL;

$failed  = count(array_filter($results, static fn (string $r): bool => str_starts_with($r, '  FAIL')));
// Counted apart from the passes. "12 checks passed" when two of them never ran
// is the kind of small overstatement that turns into real confidence in
// something nobody actually verified.
$skipped = count(array_filter($results, static fn (string $r): bool => str_starts_with($r, '  SKIP')));
$passed  = count($results) - $failed - $skipped;

echo PHP_EOL . ($failed === 0
    ? 'OK — ' . $passed . ' checks passed on ' . $connection->driver()
        . ($skipped > 0 ? ', ' . $skipped . ' skipped (seed the database to cover them)' : '')
    : $failed . ' OF ' . count($results) . ' CHECKS FAILED') . PHP_EOL;

exit($failed === 0 ? 0 : 1);
