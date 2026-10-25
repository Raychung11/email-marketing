<?php

declare(strict_types=1);

namespace App\Queue;

use App\Core\Config;
use App\Core\Container;
use App\Core\Logger;
use Throwable;

/**
 * The part of the worker that decides what becomes of a job.
 *
 * It used to live inline in workers/worker.php, with a second copy in
 * Tests\Support\QueueRunner whose docblock said, correctly, "if
 * workers/worker.php changes those, this has to change with it". That is a
 * promise nothing enforces. The two could drift and the suite would stay green
 * while production did something else — and the decisions in here are precisely
 * the ones the send-reliability work depends on:
 *
 *   PermanentFailure  -> dead-letter, no retry
 *   anything else     -> retry on the retry queue with backoff, until the
 *                        attempt ceiling, then dead-letter
 *   returned normally -> acknowledge
 *
 * That last one carries more weight than it looks. A job is entitled to finish
 * without having sent anything — a paused campaign hands its recipient back and
 * returns — and what makes acknowledging safe is that the recipient row, not the
 * queue entry, is the record of work still owed. Getting that pair wrong is what
 * made pause lose its recipients in the first place, which is reason enough for
 * the test suite to drive this code rather than a copy of it.
 *
 * The script keeps everything that is genuinely about being a long-running
 * process: options, locks, signals, shift deadlines, memory ceilings, heartbeats.
 */
final class Worker
{
    public const ACKNOWLEDGED = 'acknowledged';
    public const DEAD_LETTERED = 'dead_lettered';
    public const EXHAUSTED    = 'exhausted';
    public const RETRIED      = 'retried';

    public function __construct(
        private readonly Container $container,
        private readonly QueueDriver $driver,
        private readonly QueueManager $queue,
        private readonly Config $config,
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
     * @return string|null the outcome, or null when every queue was empty
     */
    public function runNext(array $queues, string $workerId): ?string
    {
        foreach ($queues as $queueName) {
            $job = $this->driver->pop($queueName, $workerId);

            if ($job !== null) {
                return $this->process($job);
            }
        }

        return null;
    }

    /**
     * Work until the queues are empty or $maxJobs have been handled.
     *
     * Bounded, because a job that releases itself back onto a queue it can
     * immediately pop again would otherwise spin for ever.
     *
     * @param  array<int,string> $queues
     * @return int jobs processed
     */
    public function drain(array $queues, string $workerId, int $maxJobs = 200): int
    {
        $processed = 0;

        while ($processed < $maxJobs && $this->runNext($queues, $workerId) !== null) {
            $processed++;
        }

        return $processed;
    }

    /** @return string one of the outcome constants */
    public function process(Job $job): string
    {
        try {
            $handler = $this->container->make($job->jobClass);

            if (!$handler instanceof Queueable) {
                throw new PermanentFailure($job->jobClass . ' is not a queueable job.');
            }

            $handler->handle($job->payload);
            $this->driver->acknowledge($job);

            return self::ACKNOWLEDGED;
        } catch (PermanentFailure $e) {
            // No retry: the outcome will not change, and retrying a rejected
            // address burns sending reputation for nothing.
            $this->logger->warning('Job permanently failed', [
                'job'   => $job->jobClass,
                'queue' => $job->queue,
                'error' => $e->getMessage(),
            ]);

            $this->driver->fail($job, $e->getMessage(), true);

            return self::DEAD_LETTERED;
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

                return self::EXHAUSTED;
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
                (string) $this->config->get('queue.retry_queue', 'email_retry'),
                $job->jobClass,
                $job->payload,
                $job->organisationId,
                $attempt,
                null,
                $job->priority
            );

            $this->driver->release($retry, $backoff);
            $this->driver->acknowledge($job);

            return self::RETRIED;
        }
    }
}
