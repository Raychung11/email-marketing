<?php

declare(strict_types=1);

namespace App\Queue;

use App\Core\Config;
use App\Core\Logger;

/**
 * Dispatch side of the queue. Everything that wants work done later goes through
 * here, so the driver is a configuration detail.
 */
final class QueueManager
{
    public function __construct(
        private readonly QueueDriver $driver,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function dispatch(
        string $queue,
        string $jobClass,
        array $payload,
        ?int $organisationId = null,
        int $delaySeconds = 0,
        int $priority = 0,
    ): void {
        $this->driver->push(
            new Job($queue, $jobClass, $payload, $organisationId, 0, null, $priority),
            $delaySeconds
        );
    }

    /**
     * Retry backoff: 1m → 5m → 30m → 2h, then dead-letter.
     *
     * @return int|null delay in seconds, or null when attempts are exhausted
     */
    public function backoffFor(int $attemptCount): ?int
    {
        /** @var array<int,int> $schedule */
        $schedule = $this->config->get('queue.retry_backoff', [60, 300, 1800, 7200]);

        return $schedule[$attemptCount] ?? null;
    }

    public function maxAttempts(): int
    {
        return (int) $this->config->get('queue.max_attempts', 4);
    }

    public function driver(): QueueDriver
    {
        return $this->driver;
    }

    /** @return array<string,int> */
    public function depths(): array
    {
        /** @var array<int,string> $queues */
        $queues = $this->config->get('queue.queues', []);
        $depths = [];

        foreach ($queues as $queue) {
            $depths[$queue] = $this->driver->size($queue);
        }

        return $depths;
    }
}
