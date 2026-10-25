<?php

/**
 * MySQL/MariaDB: the schema, and what happens when processes collide.
 *
 *   php tests/mysql/contention.php
 *
 * Companion to verify.php, which proves the statements this application sends
 * are valid MySQL. This asks two further questions that neither it nor the
 * SQLite suite can:
 *
 *   DID THE MIGRATION LAND? Not "did it run without error" — whether the column
 *   widths, types and indexes it was supposed to produce are actually there. The
 *   first production deploy of this application failed on DDL that rendered
 *   differently under the deployment grammar than the test grammar, and no
 *   amount of SQLite could have caught it.
 *
 *   DOES THE CONCURRENCY CONTROL HOLD UNDER REAL CONCURRENCY? Every "only one
 *   worker can have this row" claim in the codebase rests on a conditional
 *   UPDATE. One process running that statement in a loop proves the SQL parses.
 *   Eight processes running it at the same instant is the thing being claimed.
 *
 * READS AND WRITES THE DATABASE IN .env. Point it at a scratch database. It
 * creates one organisation, campaign, contact and recipient, and removes them.
 */
declare(strict_types=1);

require __DIR__ . '/../../bootstrap/autoload.php';

use App\Core\Application;
use App\Database\Connection;
use App\Database\Schema\Schema;

$container  = Application::bootConsole(dirname(__DIR__, 2))->container();
$connection = $container->make(Connection::class);

if ($connection->driver() !== 'mysql') {
    fwrite(STDERR, 'This script needs DB_DRIVER=mysql. The driver in .env is '
        . $connection->driver() . '.' . PHP_EOL);
    exit(2);
}

$results = [];

$check = static function (string $what, callable $assertion) use (&$results): void {
    try {
        $outcome = $assertion();
    } catch (Throwable $e) {
        $outcome = get_class($e) . ': ' . $e->getMessage();
    }

    $results[] = $outcome === true
        ? '  PASS  ' . $what
        : '  FAIL  ' . $what . ' — ' . (is_string($outcome) ? $outcome : 'returned false');
};

echo 'Server: ' . $connection->scalar('SELECT VERSION()') . PHP_EOL . PHP_EOL;

// ------------------------------------------------------------------- schema

$check('send_status is wide enough for the whole lifecycle', static function () use ($connection) {
    $column = $connection->selectOne(
        "SELECT COLUMN_TYPE AS t FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'campaign_recipients'
            AND column_name = 'send_status'"
    );

    $type = strtolower((string) ($column['t'] ?? ''));

    if (!str_starts_with($type, 'varchar')) {
        return 'expected varchar, found ' . $type;
    }

    // The longest state is outcome_unknown, at fifteen characters. A column that
    // truncates it would store a value nothing matches, and every query would
    // quietly skip the row.
    preg_match('/varchar\((\d+)\)/', $type, $matches);

    return (int) ($matches[1] ?? 0) >= 15 ? true : 'too narrow: ' . $type;
});

$check('the new states survive a round trip', static function () use ($connection) {
    // MySQL in strict mode truncates an over-long value and warns; reading it
    // back is the only way to know the column really holds what we think.
    $connection->raw('CREATE TEMPORARY TABLE state_probe LIKE campaign_recipients');

    foreach (['sending', 'outcome_unknown'] as $index => $state) {
        $connection->execute(
            "INSERT INTO state_probe (organisation_id, campaign_id, contact_id, email,
                                      email_normalized, send_status, created_at)
             VALUES (1, 1, ?, 'probe@example.com', 'probe@example.com', ?, NOW())",
            [$index + 1, $state]
        );
    }

    $stored = array_map(
        static fn (array $row): string => (string) $row['s'],
        $connection->select('SELECT send_status AS s FROM state_probe ORDER BY s')
    );

    return $stored === ['outcome_unknown', 'sending'] ? true : 'stored as ' . implode(', ', $stored);
});

$check('every column the send path writes exists', static function () use ($connection) {
    $schema = new Schema($connection);

    foreach ([
        'lock_token', 'claimed_at', 'lease_expires_at',
        'last_attempt_at', 'provider_attempted_at', 'retry_after',
    ] as $column) {
        if (!$schema->hasColumn('campaign_recipients', $column)) {
            return 'missing ' . $column;
        }
    }

    return true;
});

$check('the lease reaper has its index', static function () use ($connection) {
    $indexes = array_map(
        static fn (array $row): string => (string) $row['n'],
        $connection->select(
            "SELECT DISTINCT INDEX_NAME AS n FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'campaign_recipients'"
        )
    );

    return in_array('idx_campaign_recipients_lease', $indexes, true)
        ? true
        : 'have: ' . implode(', ', $indexes);
});

$check('the lease sweep uses an index rather than scanning', static function () use ($connection) {
    // The one query that runs on a timer against the whole table. A scan here
    // is invisible until the table is large, and then it is the slow query that
    // takes the scheduler down with it.
    $plan = $connection->selectOne(
        "EXPLAIN SELECT id FROM campaign_recipients
          WHERE send_status = 'sending' AND lease_expires_at < NOW()"
    ) ?? [];

    return ($plan['key'] ?? '') !== '' ? true : 'no index chosen: ' . json_encode($plan);
});

$check('the dispatch query uses an index rather than scanning', static function () use ($connection) {
    $plan = $connection->selectOne(
        "EXPLAIN SELECT id FROM campaign_recipients
          WHERE organisation_id = 1 AND campaign_id = 1 AND eligibility_status = 'eligible'
            AND send_status = 'pending' AND (retry_after IS NULL OR retry_after <= NOW())
          ORDER BY id LIMIT 500"
    ) ?? [];

    return ($plan['key'] ?? '') !== '' ? true : 'no index chosen: ' . json_encode($plan);
});

// -------------------------------------------------------------- concurrency

$now = gmdate('Y-m-d H:i:s');

$connection->execute(
    "INSERT INTO organisations (id, uuid, name, slug, daily_send_limit)
     VALUES (424242, ?, 'Contention Co', 'contention-co-424242', 1000)
     ON DUPLICATE KEY UPDATE name = VALUES(name)",
    ['00000000-0000-4000-8000-000000424242']
);

$connection->execute(
    "INSERT INTO campaigns (id, organisation_id, uuid, name)
     VALUES (424242, 424242, ?, 'Contention campaign')
     ON DUPLICATE KEY UPDATE name = VALUES(name)",
    ['00000000-0000-4000-8000-000000424243']
);

$connection->execute(
    "INSERT INTO contacts (id, organisation_id, uuid, email, email_normalized)
     VALUES (424242, 424242, ?, 'contend@example.com', 'contend@example.com')
     ON DUPLICATE KEY UPDATE email = VALUES(email)",
    ['00000000-0000-4000-8000-000000424244']
);

$connection->execute(
    "INSERT INTO campaign_recipients (id, organisation_id, campaign_id, contact_id, email,
                                      email_normalized, eligibility_status, send_status,
                                      attempt_count, created_at)
     VALUES (424242, 424242, 424242, 424242, 'contend@example.com', 'contend@example.com',
             'eligible', 'pending', 0, ?)
     ON DUPLICATE KEY UPDATE send_status = 'pending', attempt_count = 0, lock_token = NULL",
    [$now]
);

/**
 * Run N copies of the contender at the same instant and collect their stdout.
 *
 * @param  array<int,string> $arguments
 * @return array<int,string>
 */
$race = static function (array $arguments, int $processes): array {
    $startAt     = microtime(true) + 0.75;
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $handles     = [];

    for ($i = 0; $i < $processes; $i++) {
        $pipes   = [];
        $process = proc_open(
            escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/contend.php')
                . ' ' . implode(' ', array_map('escapeshellarg', $arguments)),
            $descriptors,
            $pipes,
            dirname(__DIR__, 2),
            array_merge(getenv(), ['CONTEND_START_AT' => (string) $startAt])
        );

        if (is_resource($process)) {
            $handles[] = [$process, $pipes];
        }
    }

    $output = [];

    foreach ($handles as [$process, $pipes]) {
        $output[] = trim((string) stream_get_contents($pipes[1]));
        $stderr   = trim((string) stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($stderr !== '') {
            fwrite(STDERR, '    contender stderr: ' . $stderr . PHP_EOL);
        }
    }

    return $output;
};

$check('eight processes claim one recipient; exactly one wins', static function () use ($race, $connection) {
    $won = count(array_filter(
        $race(['claim', '424242', 'token'], 8),
        static fn (string $result): bool => $result === 'won'
    ));

    if ($won !== 1) {
        return $won . ' processes believed they held the claim';
    }

    // The increment rides on the same statement, so a losing claim must not have
    // moved it either.
    $attempts = (int) $connection->scalar(
        'SELECT attempt_count FROM campaign_recipients WHERE id = 424242'
    );

    return $attempts === 1 ? true : 'attempt_count reached ' . $attempts;
});

$check('concurrent rate limit hits are all counted', static function () use ($race, $connection) {
    $key = 'login:contention-' . bin2hex(random_bytes(4)) . '@example.com';

    // Six processes, twenty hits each. A lost increment here is a free login
    // attempt for whoever is guessing passwords.
    $race(['ratelimit', $key, '20'], 6);

    $stored = (int) $connection->scalar('SELECT hits FROM rate_limits WHERE limit_key = ?', [$key]);

    $connection->execute('DELETE FROM rate_limits WHERE limit_key = ?', [$key]);

    return $stored === 120 ? true : 'counted ' . $stored . ' of 120';
});

$check('only one scheduler can hold the tick lock', static function () use ($race, $connection) {
    // Worth proving on the real engine, because the daily send allowance is a
    // computed count rather than a reservation: two schedulers running at once
    // would both read the same total and both hand it out. This lock is what
    // stops that, so it carries more weight than it looks.
    $key = 'contention-tick-' . bin2hex(random_bytes(4));

    $won = count(array_filter(
        $race(['schedulerlock', $key], 6),
        static fn (string $result): bool => $result === 'won'
    ));

    $connection->execute('DELETE FROM scheduler_locks WHERE lock_key = ?', [$key]);

    return $won === 1 ? true : $won . ' schedulers acquired it';
});

// ------------------------------------------------------------------ cleanup

foreach ([
    'DELETE FROM campaign_recipients WHERE id = 424242',
    'DELETE FROM contacts WHERE id = 424242',
    'DELETE FROM campaigns WHERE id = 424242',
    'DELETE FROM organisations WHERE id = 424242',
] as $statement) {
    $connection->execute($statement);
}

echo implode(PHP_EOL, $results) . PHP_EOL;

$failed = count(array_filter($results, static fn (string $r): bool => str_starts_with($r, '  FAIL')));

echo PHP_EOL . ($failed === 0
    ? 'OK — ' . count($results) . ' checks passed on ' . $connection->driver()
    : $failed . ' OF ' . count($results) . ' CHECKS FAILED') . PHP_EOL;

exit($failed === 0 ? 0 : 1);
