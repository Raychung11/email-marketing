<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ArrayRateLimitStore;
use App\Core\Config;
use App\Core\RateLimitStore;
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
        private readonly RateLimitStore $rateLimits,
    ) {
    }

    public function live(Request $request): Response
    {
        return Response::json(['status' => 'ok', 'time' => gmdate('c')]);
    }

    public function ready(Request $request): Response
    {
        $checks = ['database' => false, 'queue' => false, 'rate_limiting' => false];

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

        /*
         * Is the limiter actually limiting anything?
         *
         * An in-process store counts within one request and forgets, so on a
         * normal PHP deployment it is indistinguishable from no limiter at all
         * — which is what this platform shipped with, silently, on every host
         * without Redis. It is a dependency like the others and it is reported
         * like the others, rather than being discovered during an incident.
         *
         * No store name is returned: readiness tells an operator up or down and
         * tells anybody else nothing about how the inside is built.
         */
        $checks['rate_limiting'] = !$this->rateLimits instanceof ArrayRateLimitStore
            || (string) $this->config->get('app.env', 'production') === 'testing';

        $ready = !in_array(false, $checks, true);

        return Response::json([
            'status' => $ready ? 'ready' : 'degraded',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }
}
