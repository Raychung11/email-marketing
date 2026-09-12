<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Runs jobs inline. Test-only.
 *
 * It is deliberately NOT usable for campaign sending in an HTTP request: the
 * campaign dispatcher refuses to run when the driver is sync outside the test
 * environment, because sending bulk mail inside a web request is the single
 * worst thing this application could do.
 */
final class SyncQueueDriver implements QueueDriver
{
    /** @var array<int,Job> */
    private array $dispatched = [];

    /** @var array<string,Queueable> */
    private array $handlers = [];

    public function __construct(private readonly bool $execute = false)
    {
    }

    public function registerHandler(string $jobClass, Queueable $handler): void
    {
        $this->handlers[$jobClass] = $handler;
    }

    public function push(Job $job, int $delaySeconds = 0): void
    {
        $this->dispatched[] = $job;

        if ($this->execute && isset($this->handlers[$job->jobClass])) {
            $this->handlers[$job->jobClass]->handle($job->payload);
        }
    }

    public function pop(string $queue, string $workerId): ?Job
    {
        foreach ($this->dispatched as $index => $job) {
            if ($job->queue === $queue) {
                unset($this->dispatched[$index]);

                return $job;
            }
        }

        return null;
    }

    public function acknowledge(Job $job): void
    {
    }

    public function release(Job $job, int $delaySeconds): void
    {
        $this->dispatched[] = $job;
    }

    public function fail(Job $job, string $exception, bool $permanent = false): void
    {
    }

    public function size(string $queue): int
    {
        return count(array_filter($this->dispatched, static fn (Job $job): bool => $job->queue === $queue));
    }

    /** @return array<int,Job> */
    public function dispatched(): array
    {
        return array_values($this->dispatched);
    }

    public function flush(): void
    {
        $this->dispatched = [];
    }
}
