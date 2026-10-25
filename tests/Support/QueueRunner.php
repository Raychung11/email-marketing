<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Container;
use App\Queue\Job;
use App\Queue\QueueDriver;
use App\Queue\Worker;

/**
 * A worker, for tests that need a real queue.
 *
 * This used to be a copy of the loop in workers/worker.php, with a note saying
 * the two had to be kept in step by hand. They do not any more: the loop lives
 * in App\Queue\Worker and both the script and this call into it. A test that
 * passes here is a test that passed against the code production runs, which is
 * the only version of that claim worth making — the bug this suite exists to
 * catch was precisely the worker acknowledging a job that had not done its work.
 *
 * What is left here is the part a test wants and a long-running process does
 * not: a record of what happened to each job, so an assertion can say "that was
 * retried, not dead-lettered" instead of inferring it from side effects.
 */
final class QueueRunner
{
    /** @var array<int,array{job:string,outcome:string}> */
    private array $log = [];

    private readonly Worker $worker;

    public function __construct(
        Container $container,
        private readonly QueueDriver $driver,
    ) {
        $this->worker = $container->make(Worker::class);
    }

    /**
     * Drain the queues in priority order until they are empty.
     *
     * Bounded, because a job that releases itself back onto a queue it can
     * immediately pop again would otherwise spin for ever — and a test that
     * hangs tells you nothing.
     *
     * @param array<int,string> $queues
     */
    public function drain(array $queues = ['email_marketing', 'email_retry'], int $maxJobs = 200): int
    {
        $processed = 0;

        while ($processed < $maxJobs) {
            $job = $this->next($queues);

            if ($job === null) {
                return $processed;
            }

            $this->log[] = ['job' => $job->jobClass, 'outcome' => $this->worker->process($job)];
            $processed++;
        }

        return $processed;
    }

    /** @return array<int,array{job:string,outcome:string}> */
    public function log(): array
    {
        return $this->log;
    }

    /** @param array<int,string> $queues */
    private function next(array $queues): ?Job
    {
        foreach ($queues as $queueName) {
            $job = $this->driver->pop($queueName, 'test-worker');

            if ($job !== null) {
                return $job;
            }
        }

        return null;
    }
}
