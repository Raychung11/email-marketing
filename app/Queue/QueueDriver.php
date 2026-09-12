<?php

declare(strict_types=1);

namespace App\Queue;

interface QueueDriver
{
    public function push(Job $job, int $delaySeconds = 0): void;

    /** Reserve the next available job on a queue, or null when idle. */
    public function pop(string $queue, string $workerId): ?Job;

    public function acknowledge(Job $job): void;

    /** Return a job to the queue for a later attempt. */
    public function release(Job $job, int $delaySeconds): void;

    public function fail(Job $job, string $exception, bool $permanent = false): void;

    public function size(string $queue): int;
}
