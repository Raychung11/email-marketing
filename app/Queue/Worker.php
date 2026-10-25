<?php

declare(strict_types=1);

namespace App\Queue;

use App\Core\Container;
use App\Core\Logger;
use Throwable;

/**
 * The part of the worker that decides what happens to a job.
 *
 * Pulled out of workers/worker.php so that it can be exercised by the test
 * suite. That is not tidiness: the interesting bugs in a queue are in exactly
 * this code — what gets acknowledged, what gets retried, what gets
 * dead-lettered — and a test that calls the job handler directly proves none of
 * it. A campaign paused mid-send looked perfectly healthy under handler-level
 * tests for exactly that reason, while in production the worker was deleting
 * the jobs and the recipients were never sent.
 *
 * The script keeps everything that is genuinely about being a long-running
 * process: options, locks, signals, shift deadlines, heartbeats.
 */
final class Worker
{
    public function __construct(
        private readonly QueueDriver $driver,
        private readonly QueueManager $queue,
        private readonly Container $container,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Take the next job from the first queue that has one, and see it through.
     *
     * Strict priority: the queues are drained left to right, so transactional
     * mail never waits behind a 100k marketing blast.
     *
     * @param  array<int,string> $queues
     * @return bool whether there was anything to do
     */
    public function runNext(array $queues, string $workerId): bool
    {
        foreach ($queues as $queueName) {
            $job = $this->driver->pop($queueName, $workerId);

            if ($job !== null) {
                $this->process($job);

                return true;
            }
        }

        return false;
    }

    /**
     * Work until the queues are empty or $maxJobs have been processed.
     *
     * @param  array<int,string> $queues
     * @return int jobs processed
     */
    public function drain(array $queues, string $workerId, int $maxJobs = 1000): int
    {
        $processed = 0;

        while ($processed < $maxJobs && $this->runNext($queues, $workerId)) {
            $processed++;
        }

        return $processed;
    }

    public function process(Job $job): void
    {
        try {
            $handler = $this->container->make($job->jobClass);

            if (!$handler instanceof Queueable) {
                throw new PermanentFailure($job->jobClass . ' is not a queueable job.');
            }

            $handler->handle($job->payload);

            // Completing normally is an acknowledgement, and a job is entitled
            // to complete normally without having sent anything: a paused
            // campaign hands its recipient back and returns. What makes that
            // safe is that the recipient row, not the queue entry, is the record
            // of work still owed.
            $this->driver->acknowledge($job);
        } catch (PermanentFailure $e) {
            // No retry: the outcome will not change, and retrying a rejected
            // address burns sending reputation for nothing.
            $this->logger->warning('Job permanently failed', [
                'job'   => $job->jobClass,
                'queue' => $job->queue,
                'error' => $e->getMessage(),
            ]);

            $this->driver->fail($job, $e->getMessage(), true);
        } catch (Throwable $e) {
            $attempt = $job->attemptCount + 1;
            $backoff = $this->queue->backoffFor($attempt - 1);

            if ($backoff === null || $attempt >= $this->queue->maxAttempts()) {
                $this->logger->error('Job exhausted its retries', [
                    'job'      => $job->jobClass,
                    'queue'    => $job->queue,
                    'attempts' => $attempt,
                    'error'    => $e->getMessage(),
                ]);

                $this->driver->fail($job, $e->getMessage(), false);

                return;
            }

            $this->logger->info('Job failed; retrying with backoff', [
                'job'      => $job->jobClass,
                'attempt'  => $attempt,
                'retry_in' => $backoff,
                'error'    => $e->getMessage(),
            ]);

            // Retries go to the dedicated retry queue so a hot-looping failure
            // cannot starve first-attempt work.
            $retry = new Job(
                'email_retry',
                $job->jobClass,
                $job->payload,
                $job->organisationId,
                $attempt,
                null,
                $job->priority
            );

            $this->driver->release($retry, $backoff);
            $this->driver->acknowledge($job);
        }
    }
}
