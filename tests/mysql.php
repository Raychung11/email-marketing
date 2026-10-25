<?php

declare(strict_types=1);

/**
 * MySQL / MariaDB integration checks.
 *
 *   php tests/mysql.php
 *
 * Separate from `tests/run.php` on purpose. The main suite runs on SQLite and
 * must keep working on a fresh clone with nothing installed; these need a
 * server, and a suite that silently skips when its dependency is missing is a
 * suite that reports green for work it never did.
 *
 * What they are here to catch is the class of bug SQLite cannot see:
 *
 *  - DDL that the deployment grammar renders differently from the test grammar.
 *    The first production deploy of this application failed on exactly that —
 *    a collation MariaDB has never had — and nothing in a SQLite suite could
 *    have found it.
 *  - Conditional UPDATEs under genuine concurrency. Two real connections racing
 *    on one row is what production does; a loop in one process is not.
 *
 * Connection details come from MYSQL_TEST_* environment variables.
 */

require __DIR__ . '/Integration/support.php';

use App\Core\Clock;
use App\Database\Migrator;
use App\Database\Schema\Schema;

$connection = integration_connection();
$failures   = [];
$checks     = 0;

$check = static function (string $label, callable $assertion) use (&$failures, &$checks): void {
    $checks++;

    try {
        $result = $assertion();

        if ($result === true) {
            echo "  \u{2713} {$label}\n";

            return;
        }

        $reason     = is_string($result) ? $result : 'returned false';
        $failures[] = $label . ' — ' . $reason;
        echo "  \u{2717} {$label}\n      {$reason}\n";
    } catch (Throwable $e) {
        $reason     = get_class($e) . ': ' . $e->getMessage();
        $failures[] = $label . ' — ' . $reason;
        echo "  \u{2717} {$label}\n      {$reason}\n";
    }
};

echo "MySQL / MariaDB integration\n";
echo str_repeat('-', 64) . "\n";

try {
    $version = (string) $connection->scalar('SELECT VERSION()');
} catch (Throwable $e) {
    fwrite(STDERR, "Could not reach the test database: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Set MYSQL_TEST_HOST / _PORT / _DATABASE / _USERNAME / _PASSWORD.\n");

    exit(2);
}

echo "Server: {$version}\n\n";

// --------------------------------------------------------------- migrations

echo "Schema\n";

// A genuinely empty database, so the migrations run in order from nothing —
// including the one that widens the enum, which is the whole point.
foreach ($connection->select(
    'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'
) as $row) {
    $connection->raw('SET FOREIGN_KEY_CHECKS = 0');
    $connection->raw('DROP TABLE IF EXISTS `' . $row['t'] . '`');
    $connection->raw('SET FOREIGN_KEY_CHECKS = 1');
}

$migrator = new Migrator($connection, dirname(__DIR__) . '/database/migrations');
$applied  = $migrator->run();

$check('every migration applies to an empty database', static fn (): bool|string
    => count($applied) > 0 ? true : 'nothing ran');

$check('send_status carries the full lifecycle vocabulary', static function () use ($connection): bool|string {
    $column = $connection->selectOne(
        "SELECT COLUMN_TYPE AS t FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'campaign_recipients'
           AND column_name = 'send_status'"
    );

    $type = (string) ($column['t'] ?? '');

    foreach (['pending', 'queued', 'sending', 'sent', 'failed', 'skipped', 'outcome_unknown'] as $state) {
        if (!str_contains($type, "'" . $state . "'")) {
            return "missing '{$state}' in {$type}";
        }
    }

    return true;
});

$check('the new states are actually writable', static function () use ($connection): bool|string {
    // MariaDB in strict mode truncates an out-of-range ENUM to '' and warns;
    // reading it back is the only way to be sure the widening took.
    $connection->raw("CREATE TEMPORARY TABLE enum_probe LIKE campaign_recipients");

    // The LIKE copies the unique index too, so each probe row needs its own
    // contact id.
    foreach (['sending', 'outcome_unknown'] as $index => $state) {
        $connection->execute(
            "INSERT INTO enum_probe (organisation_id, campaign_id, contact_id, email, email_normalized,
                                     send_status, created_at)
             VALUES (1, 1, ?, 'probe@example.com', 'probe@example.com', ?, NOW())",
            [$index + 1, $state]
        );
    }

    $stored = $connection->select('SELECT send_status AS s FROM enum_probe');
    $values = array_map(static fn (array $r): string => (string) $r['s'], $stored);

    sort($values);

    return $values === ['outcome_unknown', 'sending'] ? true : 'stored as ' . implode(', ', $values);
});

$check('the dispatch and claim indexes exist', static function () use ($connection): bool|string {
    $indexes = array_map(
        static fn (array $r): string => (string) $r['n'],
        $connection->select(
            "SELECT DISTINCT INDEX_NAME AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'campaign_recipients'"
        )
    );

    foreach (['campaign_recipients_dispatch_idx', 'campaign_recipients_claim_idx'] as $name) {
        if (!in_array($name, $indexes, true)) {
            return 'missing ' . $name . ' (have: ' . implode(', ', $indexes) . ')';
        }
    }

    return true;
});

$check('the dispatch query uses an index rather than scanning', static function () use ($connection): bool|string {
    $plan = $connection->selectOne(
        "EXPLAIN SELECT id FROM campaign_recipients
         WHERE organisation_id = 1 AND campaign_id = 1 AND eligibility_status = 'eligible'
           AND send_status = 'pending' AND (available_at IS NULL OR available_at <= NOW())
         ORDER BY id LIMIT 500"
    ) ?? [];

    $key = (string) ($plan['key'] ?? '');

    return $key !== '' ? true : 'no index chosen: ' . json_encode($plan);
});

$check('users carry an auth version', static function () use ($connection): bool|string {
    $schema = new Schema($connection);

    return $schema->hasColumn('users', 'auth_version') ? true : 'column missing';
});

$check('the rate limit and reservation tables exist', static function () use ($connection): bool|string {
    $schema = new Schema($connection);

    foreach (['rate_limits', 'send_reservations'] as $table) {
        if (!$schema->hasTable($table)) {
            return 'missing ' . $table;
        }
    }

    return true;
});

// -------------------------------------------------------------- concurrency

echo "\nConcurrency (separate processes, one row)\n";

$clock = new Clock();
$now   = $clock->nowString();

$connection->execute(
    "INSERT INTO organisations (id, uuid, name, slug, daily_send_limit)
     VALUES (1, ?, 'Contention Co', 'contention-co', 1000)
     ON DUPLICATE KEY UPDATE name = VALUES(name)",
    ['00000000-0000-4000-8000-000000000001']
);

$connection->execute(
    "INSERT INTO campaigns (id, organisation_id, uuid, name)
     VALUES (1, 1, ?, 'Contention campaign')
     ON DUPLICATE KEY UPDATE name = VALUES(name)",
    ['00000000-0000-4000-8000-000000000002']
);

$connection->execute(
    "INSERT INTO contacts (id, organisation_id, uuid, email, email_normalized)
     VALUES (1, 1, ?, 'contend@example.com', 'contend@example.com')
     ON DUPLICATE KEY UPDATE email = VALUES(email)",
    ['00000000-0000-4000-8000-000000000003']
);

$connection->execute(
    "INSERT INTO campaign_recipients (id, organisation_id, campaign_id, contact_id, email,
                                      email_normalized, eligibility_status, send_status,
                                      attempt_count, created_at)
     VALUES (1, 1, 1, 1, 'contend@example.com', 'contend@example.com', 'eligible', 'pending', 0, ?)
     ON DUPLICATE KEY UPDATE send_status = 'pending', attempt_count = 0, claim_token = NULL",
    [$now]
);

/**
 * Run N copies of the contender and collect their stdout.
 *
 * @param  array<int,string> $arguments
 * @return array<int,string>
 */
$race = static function (array $arguments, int $processes): array {
    $startAt     = microtime(true) + 0.75;
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $handles     = [];

    for ($i = 0; $i < $processes; $i++) {
        $command = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/Integration/contend.php')
            . ' ' . implode(' ', array_map('escapeshellarg', $arguments));

        $pipes   = [];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            dirname(__DIR__),
            array_merge(getenv(), ['CONTEND_START_AT' => (string) $startAt])
        );

        if (is_resource($process)) {
            $handles[] = [$process, $pipes];
        }
    }

    $output = [];

    foreach ($handles as [$process, $pipes]) {
        $output[] = trim((string) stream_get_contents($pipes[1]));
        $error    = trim((string) stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($error !== '') {
            fwrite(STDERR, "    contender stderr: {$error}\n");
        }
    }

    return $output;
};

$check('eight processes claim one recipient; exactly one wins', static function () use ($race, $connection): bool|string {
    $results = $race(['claim', '1', 'token'], 8);
    $won     = count(array_filter($results, static fn (string $r): bool => $r === 'won'));

    if ($won !== 1) {
        return $won . ' processes believed they had the claim';
    }

    $attempts = (int) $connection->scalar('SELECT attempt_count FROM campaign_recipients WHERE id = 1');

    return $attempts === 1 ? true : "attempt_count went to {$attempts}, so a losing claim still counted";
});

$check('concurrent reservations never exceed the daily limit', static function () use ($race, $connection): bool|string {
    $day = gmdate('Y-m-d');

    $connection->execute('DELETE FROM send_reservations WHERE organisation_id = 1');

    // Six processes, ten rounds each, three at a time: far more demand than the
    // limit of twenty, so any lost update shows up immediately.
    $results = $race(['reserve', '1', $day, '20', '3'], 6);
    $granted = array_sum(array_map('intval', $results));

    $stored = (int) $connection->scalar(
        'SELECT reserved FROM send_reservations WHERE organisation_id = 1 AND day_key = ?',
        [$day]
    );

    if ($granted > 20) {
        return "handed out {$granted} against a limit of 20";
    }

    return $granted === $stored
        ? true
        : "granted {$granted} but the counter says {$stored}";
});

$check('concurrent rate limit hits are all counted', static function () use ($race, $connection): bool|string {
    $key = 'login:contention-' . bin2hex(random_bytes(4)) . '@example.com';

    $connection->execute('DELETE FROM rate_limits');

    // Six processes, twenty hits each. A lost increment here is a free login
    // attempt for whoever is guessing passwords.
    $race(['ratelimit', $key, '20'], 6);

    $stored = (int) $connection->scalar(
        'SELECT hits FROM rate_limits WHERE bucket = ?',
        [substr(hash('sha256', $key), 0, 64)]
    );

    return $stored === 120 ? true : "counted {$stored} of 120";
});

// -------------------------------------------------------------------- result

echo "\n" . str_repeat('-', 64) . "\n";

if ($failures === []) {
    echo "OK — {$checks} checks on {$version}\n";

    exit(0);
}

echo 'FAILED — ' . count($failures) . " of {$checks} checks\n\n";

foreach ($failures as $failure) {
    echo "  {$failure}\n";
}

exit(1);
