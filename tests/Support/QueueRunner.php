<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Config;
use App\Core\Container;
use App\Queue\Job;
use App\Queue\PermanentFailure;
use App\Queue\QueueDriver;
use App\Queue\QueueManager;
use App\Queue\Queueable;
use Throwable;

/**
 * The worker's job-handling loop, for tests that need a real queue.
 *
 * MIRRORS workers/worker.php. Deliberately a copy rather than an extraction:
 * pulling the loop out of the worker is a refactor of production code to suit a
 * test, and this brief is not allowed to make that change. The copy covers the
 * decisions that matter here and nothing else — no signals, no shift deadline,
 * no memory ceiling, no heartbeat.
 *
 * What it does have to match exactly is the error handling, because that is the
 * part the reliability work depends on:
 *
 *   PermanentFailure  -> dead-letter, no retry
 *   anything else     -> retry onto the retry queue with the backoff, until the
 *                        attempt ceiling, then dead-letter
 *   success           -> acknowledge
 *
 * If workers/worker.php changes those, this has to change with it.
 */
final class QueueRunner
{
    /** @var array<int,array{job:string,outcome:string,error:string|null}> */
    private array $log = [];

    public function __construct(
        private readonly Container $container,
        private readonly QueueDriver $driver,
        private readonly QueueManager $queue,
        private readonly Config $config,
    ) {
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
            $job = null;

            foreach ($queues as $queueName) {
                $job = $this->driver->pop($queueName, 'test-worker');

                if ($job !== null) {
                    break;
                }
            }

            if ($job === null) {
                return $processed;
            }

            $processed++;
            $this->run($job);
        }

        return $processed;
    }

    /** @return array<int,array{job:string,outcome:string,error:string|null}> */
    public function log(): array
    {
        return $this->log;
    }

    private function run(Job $job): void
    {
        try {
            $handler = $this->container->make($job->jobClass);

            if (!$handler instanceof Queueable) {
                throw new PermanentFailure($job->jobClass . ' is not a queueable job.');
            }

            $handler->handle($job->payload);
            $this->driver->acknowledge($job);
            $this->record($job, 'acknowledged', null);
        } catch (PermanentFailure $e) {
            $this->driver->fail($job, $e->getMessage(), true);
            $this->record($job, 'dead_lettered', $e->getMessage());
        } catch (Throwable $e) {
            $attempt = $job->attemptCount + 1;
            $backoff = $this->queue->backoffFor($attempt - 1);

            if ($backoff === null || $attempt >= $this->queue->maxAttempts()) {
                $this->driver->fail($job, $e->getMessage(), false);
                $this->record($job, 'exhausted', $e->getMessage());

                return;
            }

            $this->driver->release(
                new Job(
                    (string) $this->config->get('queue.retry_queue', 'email_retry'),
                    $job->jobClass,
                    $job->payload,
                    $job->organisationId,
                    $attempt,
                    null,
                    $job->priority
                ),
                $backoff
            );

            $this->driver->acknowledge($job);
            $this->record($job, 'retried', $e->getMessage());
        }
    }

    private function record(Job $job, string $outcome, ?string $error): void
    {
        $this->log[] = ['job' => $job->jobClass, 'outcome' => $outcome, 'error' => $error];
    }
}
