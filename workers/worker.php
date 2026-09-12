<?php

declare(strict_types=1);

/**
 * Queue worker.
 *
 *   php workers/worker.php --queue=email_marketing --sleep=1 --max-jobs=500
 *
 * Supervisor keeps these alive; see docs/DEPLOYMENT.md. The worker exits
 * voluntarily after --max-jobs or when memory grows, and Supervisor restarts it,
 * which is how a long-running PHP process stays healthy.
 *
 * Several queues can be given comma-separated. They are drained strictly left to
 * right, so transactional mail never waits behind a 100k marketing blast.
 */

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\Application;
use App\Core\Config;
use App\Core\Logger;
use App\Queue\Job;
use App\Queue\PermanentFailure;
use App\Queue\QueueDriver;
use App\Queue\QueueManager;
use App\Queue\Queueable;

$options = getopt('', ['queue::', 'sleep::', 'max-jobs::', 'memory::', 'once']);

$app       = Application::bootConsole(dirname(__DIR__));
$container = $app->container();

/** @var Config $config */
$config = $container->make(Config::class);
/** @var Logger $logger */
$logger = $container->make(Logger::class);
/** @var QueueDriver $driver */
$driver = $container->make(QueueDriver::class);
/** @var QueueManager $queue */
$queue = $container->make(QueueManager::class);

$queues = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($options['queue'] ?? 'email_marketing'))
)));

$sleep      = max(0, (int) ($options['sleep'] ?? $config->get('queue.worker.sleep', 1)));
$maxJobs    = max(0, (int) ($options['max-jobs'] ?? $config->get('queue.worker.max_jobs', 500)));
$memoryCap  = (int) ($options['memory'] ?? $config->get('queue.worker.memory_limit_mb', 256));
$runOnce    = isset($options['once']);
$workerId   = gethostname() . ':' . getmypid();

$shouldStop = false;

// Graceful shutdown: finish the job in hand, then exit. Killing a worker
// mid-send is how duplicate emails happen.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);

    foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
        pcntl_signal($signal, static function () use (&$shouldStop, $logger, $workerId): void {
            $logger->info('Worker asked to stop; finishing current job.', ['worker' => $workerId]);
            $shouldStop = true;
        });
    }
}

$logger->info('Worker started', ['worker' => $workerId, 'queues' => $queues]);

$processed = 0;

while (!$shouldStop) {
    $job = null;

    // Strict priority: drain the first queue before touching the next.
    foreach ($queues as $queueName) {
        $job = $driver->pop($queueName, $workerId);

        if ($job !== null) {
            break;
        }
    }

    if ($job === null) {
        if ($runOnce) {
            break;
        }

        if ($sleep > 0) {
            sleep($sleep);
        }

        continue;
    }

    $processed++;

    try {
        $handler = $container->make($job->jobClass);

        if (!$handler instanceof Queueable) {
            throw new PermanentFailure($job->jobClass . ' is not a queueable job.');
        }

        $handler->handle($job->payload);
        $driver->acknowledge($job);
    } catch (PermanentFailure $e) {
        // No retry: the outcome will not change, and retrying a rejected address
        // burns sending reputation for nothing.
        $logger->warning('Job permanently failed', [
            'job'   => $job->jobClass,
            'queue' => $job->queue,
            'error' => $e->getMessage(),
        ]);

        $driver->fail($job, $e->getMessage(), true);
    } catch (Throwable $e) {
        $attempt = $job->attemptCount + 1;
        $backoff = $queue->backoffFor($attempt - 1);

        if ($backoff === null || $attempt >= $queue->maxAttempts()) {
            $logger->error('Job exhausted its retries', [
                'job'      => $job->jobClass,
                'queue'    => $job->queue,
                'attempts' => $attempt,
                'error'    => $e->getMessage(),
            ]);

            $driver->fail($job, $e->getMessage(), false);
        } else {
            $logger->info('Job failed; retrying with backoff', [
                'job'     => $job->jobClass,
                'attempt' => $attempt,
                'retry_in' => $backoff,
                'error'   => $e->getMessage(),
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

            $driver->release($retry, $backoff);
            $driver->acknowledge($job);
        }
    }

    if ($runOnce) {
        break;
    }

    if ($maxJobs > 0 && $processed >= $maxJobs) {
        $logger->info('Worker reached its job limit; exiting for a clean restart.', [
            'worker'    => $workerId,
            'processed' => $processed,
        ]);
        break;
    }

    if ($memoryCap > 0 && (memory_get_usage(true) / 1048576) > $memoryCap) {
        $logger->warning('Worker exceeded its memory ceiling; exiting for a clean restart.', [
            'worker' => $workerId,
            'mb'     => round(memory_get_usage(true) / 1048576, 1),
        ]);
        break;
    }
}

$logger->info('Worker stopped', ['worker' => $workerId, 'processed' => $processed]);

exit(0);
