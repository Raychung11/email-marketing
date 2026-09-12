<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Queue\QueueManager;

/**
 * Liveness and readiness.
 *
 * /health answers without touching anything, so a load balancer can tell the
 * process is up. /health/ready checks the dependencies, and deliberately leaks
 * nothing about them beyond up/down.
 */
final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly QueueManager $queue,
        private readonly Config $config,
    ) {
    }

    public function live(Request $request): Response
    {
        return Response::json(['status' => 'ok', 'time' => gmdate('c')]);
    }

    public function ready(Request $request): Response
    {
        $checks = ['database' => false, 'queue' => false];

        try {
            $this->connection->scalar('SELECT 1');
            $checks['database'] = true;
        } catch (\Throwable) {
            $checks['database'] = false;
        }

        try {
            $this->queue->driver()->size('email_marketing');
            $checks['queue'] = true;
        } catch (\Throwable) {
            $checks['queue'] = false;
        }

        $ready = !in_array(false, $checks, true);

        return Response::json([
            'status' => $ready ? 'ready' : 'degraded',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
