<?php

declare(strict_types=1);

/**
 * One contender process.
 *
 *   php tests/Integration/contend.php claim <recipientId> <token>
 *   php tests/Integration/contend.php reserve <organisationId> <day> <limit> <want>
 *   php tests/Integration/contend.php ratelimit <key> <hits>
 *
 * Separate OS processes rather than a loop, because a loop in one process tests
 * the SQL and nothing else. Two connections racing on one row is the thing that
 * actually happens in production, and it is the only way to find out whether a
 * conditional UPDATE really does pick one winner.
 */

require __DIR__ . '/support.php';

use App\Core\Clock;
use App\Core\DatabaseRateLimitStore;
use App\Services\SendBudget;

$connection = integration_connection();
$clock      = new Clock();

$command = $argv[1] ?? '';

// A shared start time, so every contender arrives at the row together rather
// than being serialised by process start-up.
$startAt = (float) (getenv('CONTEND_START_AT') ?: microtime(true));

while (microtime(true) < $startAt) {
    usleep(200);
}

switch ($command) {
    case 'claim':
        $won = $connection->execute(
            "UPDATE campaign_recipients
             SET send_status = 'sending', claim_token = ?, claimed_at = ?,
                 claim_expires_at = ?, attempt_count = attempt_count + 1
             WHERE id = ? AND send_status IN ('pending', 'queued')",
            [
                (string) $argv[3],
                $clock->nowString(),
                $clock->now()->modify('+300 seconds')->format('Y-m-d H:i:s'),
                (int) $argv[2],
            ]
        );

        echo $won > 0 ? "won\n" : "lost\n";
        break;

    case 'reserve':
        $budget  = new SendBudget($connection, $clock);
        $granted = 0;

        for ($i = 0; $i < 10; $i++) {
            $granted += $budget->reserve((int) $argv[2], (string) $argv[3], (int) $argv[4], (int) $argv[5]);
        }

        echo $granted . "\n";
        break;

    case 'ratelimit':
        $store = new DatabaseRateLimitStore($connection, $clock);

        for ($i = 0; $i < (int) $argv[3]; $i++) {
            $store->increment((string) $argv[2], 600);
        }

        echo "done\n";
        break;

    default:
        fwrite(STDERR, "Unknown command\n");
        exit(1);
}

exit(0);
