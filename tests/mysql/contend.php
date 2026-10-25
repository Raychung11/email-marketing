<?php

/**
 * One contender process, for tests/mysql/contention.php.
 *
 *   php tests/mysql/contend.php claim <recipientId> <token>
 *   php tests/mysql/contend.php ratelimit <key> <hits>
 *   php tests/mysql/contend.php schedulerlock <key>
 *
 * Separate OS processes rather than a loop, because a loop in one process
 * exercises the SQL and nothing else. Two connections arriving at one row is
 * what production does, and it is the only way to find out whether a
 * conditional UPDATE really does pick a single winner.
 */
declare(strict_types=1);

require __DIR__ . '/../../bootstrap/autoload.php';

use App\Core\Application;
use App\Core\Clock;
use App\Core\DatabaseRateLimitStore;
use App\Database\Connection;
use App\Services\SchedulerLock;

$container  = Application::bootConsole(dirname(__DIR__, 2))->container();
$connection = $container->make(Connection::class);
$clock      = $container->make(Clock::class);

// A shared start time, so the contenders arrive together rather than being
// serialised by how long each took to boot.
$startAt = (float) (getenv('CONTEND_START_AT') ?: microtime(true));

while (microtime(true) < $startAt) {
    usleep(200);
}

switch ($argv[1] ?? '') {
    case 'claim':
        $won = $connection->execute(
            "UPDATE campaign_recipients
                SET send_status = 'sending',
                    lock_token = ?,
                    claimed_at = ?,
                    lease_expires_at = ?,
                    last_attempt_at = ?,
                    attempt_count = attempt_count + 1
              WHERE id = ?
                AND send_status IN ('pending', 'queued')",
            [
                (string) $argv[3],
                $clock->nowString(),
                $clock->now()->modify('+300 seconds')->format('Y-m-d H:i:s'),
                $clock->nowString(),
                (int) $argv[2],
            ]
        );

        echo $won > 0 ? "won\n" : "lost\n";
        break;

    case 'ratelimit':
        $store = new DatabaseRateLimitStore($connection, $clock);

        for ($i = 0; $i < (int) $argv[3]; $i++) {
            $store->increment((string) $argv[2], 600);
        }

        echo "done\n";
        break;

    case 'schedulerlock':
        /** @var SchedulerLock $locks */
        $locks = $container->make(SchedulerLock::class);

        echo $locks->acquire((string) $argv[2], 300, 'contender-' . getmypid()) ? "won\n" : "lost\n";
        break;

    default:
        fwrite(STDERR, "Unknown command\n");
        exit(1);
}

exit(0);
