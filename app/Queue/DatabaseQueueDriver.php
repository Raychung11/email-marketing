<?php

declare(strict_types=1);

namespace App\Queue;

use App\Core\Clock;
use App\Database\Connection;

/**
 * Database-backed queue.
 *
 * The production default is Redis; this driver exists so a small single-server
 * deployment works without it, and so the queue can be inspected with SQL when
 * something goes wrong.
 *
 * Reservation is a conditional UPDATE, which is what stops two workers claiming
 * the same job.
 */
final class DatabaseQueueDriver implements QueueDriver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
    ) {
    }

    public function push(Job $job, int $delaySeconds = 0): void
    {
        $availableAt = $delaySeconds > 0
            ? $this->clock->now()->modify("+{$delaySeconds} seconds")->format('Y-m-d H:i:s')
            : $this->clock->nowString();

        $this->connection->table('jobs')->insert([
            'queue'           => $job->queue,
            'organisation_id' => $job->organisationId,
            'job_class'       => $job->jobClass,
            'payload'         => json_encode($job->payload, JSON_UNESCAPED_SLASHES),
            'attempt_count'   => $job->attemptCount,
            'priority'        => $job->priority,
            'available_at'    => $availableAt,
            'created_at'      => $this->clock->nowString(),
        ]);
    }

    public function pop(string $queue, string $workerId): ?Job
    {
        $now = $this->clock->nowString();

        // Reclaim jobs whose worker died mid-flight.
        $stale = $this->clock->now()->modify('-10 minutes')->format('Y-m-d H:i:s');

        $this->connection->execute(
            'UPDATE jobs SET reserved_at = NULL, reserved_by = NULL
             WHERE queue = ? AND reserved_at IS NOT NULL AND reserved_at < ?',
            [$queue, $stale]
        );

        $candidate = $this->connection->selectOne(
            'SELECT * FROM jobs
             WHERE queue = ? AND reserved_at IS NULL AND available_at <= ?
             ORDER BY priority DESC, id ASC
             LIMIT 1',
            [$queue, $now]
        );

        if ($candidate === null) {
            return null;
        }

        // Conditional claim: if another worker got there first, rowCount is 0 and
        // we simply try again on the next tick.
        $claimed = $this->connection->execute(
            'UPDATE jobs SET reserved_at = ?, reserved_by = ? WHERE id = ? AND reserved_at IS NULL',
            [$now, $workerId, (int) $candidate['id']]
        );

        if ($claimed === 0) {
            return null;
        }

        return Job::fromArray($candidate);
    }

    public function acknowledge(Job $job): void
    {
        if ($job->id === null) {
            return;
        }

        $this->connection->table('jobs')->where('id', '=', $job->id)->delete();
    }

    public function release(Job $job, int $delaySeconds): void
    {
        if ($job->id === null) {
            $this->push($job, $delaySeconds);

            return;
        }

        $this->connection->table('jobs')->where('id', '=', $job->id)->update([
            'reserved_at'   => null,
            'reserved_by'   => null,
            'attempt_count' => $job->attemptCount,
            'available_at'  => $this->clock->now()->modify("+{$delaySeconds} seconds")->format('Y-m-d H:i:s'),
        ]);
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

        $this->acknowledge($job);
    }

    public function size(string $queue): int
    {
        return $this->connection->table('jobs')->where('queue', '=', $queue)->count();
    }
}
