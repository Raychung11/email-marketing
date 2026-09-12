<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Database\Connection;

/**
 * Named advisory locks, so a cron minute that overlaps the previous run cannot
 * double-execute a job. Expiry means a crashed run releases its lock instead of
 * wedging the scheduler for ever.
 */
final class SchedulerLock
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
    ) {
    }

    public function acquire(string $key, int $ttlSeconds = 300, ?string $owner = null): bool
    {
        $owner ??= gethostname() . ':' . getmypid();
        $now     = $this->clock->nowString();
        $expires = $this->clock->now()->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

        // Clear expired locks first so a crashed worker does not block for ever.
        $this->connection->table('scheduler_locks')
            ->where('lock_key', '=', $key)
            ->where('expires_at', '<', $now)
            ->delete();

        try {
            $this->connection->table('scheduler_locks')->insert([
                'lock_key'    => $key,
                'owner'       => $owner,
                'acquired_at' => $now,
                'expires_at'  => $expires,
            ]);

            return true;
        } catch (\Throwable) {
            // The unique index on lock_key is what makes this atomic: a duplicate
            // key means somebody else holds the lock.
            return false;
        }
    }

    public function release(string $key): void
    {
        $this->connection->table('scheduler_locks')->where('lock_key', '=', $key)->delete();
    }

    /**
     * Run a callback only if the lock can be taken. Returns false when another
     * process holds it.
     */
    public function withLock(string $key, int $ttlSeconds, callable $callback): bool
    {
        if (!$this->acquire($key, $ttlSeconds)) {
            return false;
        }

        try {
            $callback();
        } finally {
            $this->release($key);
        }

        return true;
    }
}
