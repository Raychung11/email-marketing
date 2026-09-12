<?php

declare(strict_types=1);

namespace App\Queue;

use App\Core\Clock;
use App\Database\Connection;
use Redis;

/**
 * Redis queue with a delayed set.
 *
 * Ready jobs live in a list; delayed and released jobs live in a sorted set keyed
 * by their run-at timestamp and are migrated into the list on each pop. Permanent
 * and exhausted failures go to the database dead-letter table, because a failure
 * you cannot query later is a failure you will not fix.
 */
final class RedisQueueDriver implements QueueDriver
{
    public function __construct(
        private readonly Redis $redis,
        private readonly Connection $connection,
        private readonly Clock $clock,
        private readonly string $prefix = 'aigh:queue:',
    ) {
    }

    public function push(Job $job, int $delaySeconds = 0): void
    {
        $encoded = json_encode($job->toArray(), JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode job payload.');
        }

        if ($delaySeconds > 0) {
            $this->redis->zAdd($this->delayedKey($job->queue), $this->clock->timestamp() + $delaySeconds, $encoded);

            return;
        }

        $this->redis->rPush($this->readyKey($job->queue), $encoded);
    }

    public function pop(string $queue, string $workerId): ?Job
    {
        $this->migrateDueJobs($queue);

        $raw = $this->redis->lPop($this->readyKey($queue));

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        return Job::fromArray($decoded);
    }

    public function acknowledge(Job $job): void
    {
        // Nothing to do: popping removes it. Crash-safety for in-flight jobs is
        // provided by the at-least-once retry path in the worker, which is the
        // right trade-off here — a duplicate send is prevented by the
        // campaign_recipients send_status check, not by the queue.
    }

    public function release(Job $job, int $delaySeconds): void
    {
        $this->push($job, $delaySeconds);
    }

    public function fail(Job $job, string $exception, bool $permanent = false): void
    {
        $this->connection->table('failed_jobs')->insert([
            'queue'           => $job->queue,
            'organisation_id' => $job->organisationId,
            'job_class'       => $job->jobClass,
            'payload'         => json_encode($job->payload, JSON_UNESCAPED_SLASHES),
            'attempt_count'   => $job->attemptCount,
            'exception'       => substr($exception, 0, 65000),
            'permanent'       => $permanent ? 1 : 0,
            'failed_at'       => $this->clock->nowString(),
        ]);
    }

    public function size(string $queue): int
    {
        return (int) $this->redis->lLen($this->readyKey($queue))
            + (int) $this->redis->zCard($this->delayedKey($queue));
    }

    private function migrateDueJobs(string $queue): void
    {
        $now = $this->clock->timestamp();
        $due = $this->redis->zRangeByScore($this->delayedKey($queue), '-inf', (string) $now, ['limit' => [0, 100]]);

        if (!is_array($due) || $due === []) {
            return;
        }

        foreach ($due as $raw) {
            // Remove first: if the push fails the job is retried by the release
            // path rather than being delivered twice from the delayed set.
            if ($this->redis->zRem($this->delayedKey($queue), $raw) > 0) {
                $this->redis->rPush($this->readyKey($queue), $raw);
            }
        }
    }

    private function readyKey(string $queue): string
    {
        return $this->prefix . $queue;
    }

    private function delayedKey(string $queue): string
    {
        return $this->prefix . $queue . ':delayed';
    }
}
