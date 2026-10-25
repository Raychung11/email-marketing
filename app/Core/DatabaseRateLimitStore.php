<?php

declare(strict_types=1);

namespace App\Core;

use App\Database\Connection;
use Throwable;

/**
 * Rate limiting that survives the end of a PHP request.
 *
 * On shared hosting there is no Redis and every request is a fresh process, so
 * an in-memory limiter counts to one and forgets. Login throttling built on that
 * is not throttling — it is a function that always returns "fine, carry on" —
 * and the account lockout it is supposed to back up never fires. This store puts
 * the counter where both processes can see it.
 *
 * THE KEY IS HASHED. Callers pass readable keys: "login:someone@example.com",
 * "api:<key prefix>", "public:unsubscribe:203.0.113.4". Writing those into a
 * table would quietly build a second log of who tried to sign in and from where,
 * which is a privacy liability a throttle has no business creating. The hash is
 * one-way, fixed width, and just as unique.
 *
 * THE WINDOW IS FIXED, NOT SLIDING. Same semantics as the Redis store it stands
 * in for: the first hit sets an expiry, the count runs until then, and the
 * bucket is reused once it lapses. A sliding window would be better behaved at
 * the boundary and is not worth a second implementation that can disagree with
 * the first.
 */
final class DatabaseRateLimitStore implements RateLimitStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
    ) {
    }

    public function increment(string $key, int $decaySeconds): int
    {
        $bucket  = $this->bucket($key);
        $now     = $this->clock->nowString();
        $expires = $this->clock->now()
            ->modify('+' . max(1, $decaySeconds) . ' seconds')
            ->format('Y-m-d H:i:s');

        // A lapsed bucket is restarted in place rather than deleted and
        // reinserted: the unique index means a delete-then-insert race ends in a
        // duplicate key error, where this one just loses and retries.
        $restarted = $this->connection->execute(
            'UPDATE rate_limits SET hits = 1, expires_at = ?, created_at = ?
             WHERE bucket = ? AND expires_at <= ?',
            [$expires, $now, $bucket, $now]
        );

        if ($restarted > 0) {
            return 1;
        }

        // The increment itself is a single statement, so two concurrent requests
        // cannot both read 3 and both write 4.
        $bumped = $this->connection->execute(
            'UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ? AND expires_at > ?',
            [$bucket, $now]
        );

        if ($bumped > 0) {
            return $this->get($key);
        }

        try {
            $this->connection->table('rate_limits')->insert([
                'bucket'     => $bucket,
                'hits'       => 1,
                'expires_at' => $expires,
                'created_at' => $now,
            ]);

            return 1;
        } catch (Throwable) {
            // Another request created the row first. Count its hit rather than
            // dropping ours — under-counting is the direction that lets an
            // attacker through.
            $this->connection->execute(
                'UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?',
                [$bucket]
            );

            return $this->get($key);
        }
    }

    public function get(string $key): int
    {
        return (int) $this->connection->scalar(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND expires_at > ?',
            [$this->bucket($key), $this->clock->nowString()]
        );
    }

    public function forget(string $key): void
    {
        $this->connection->table('rate_limits')->where('bucket', '=', $this->bucket($key))->delete();
    }

    public function ttl(string $key): int
    {
        $expires = $this->connection->scalar(
            'SELECT expires_at FROM rate_limits WHERE bucket = ? AND expires_at > ?',
            [$this->bucket($key), $this->clock->nowString()]
        );

        if ($expires === null) {
            return 0;
        }

        return max(0, strtotime((string) $expires . ' UTC') - $this->clock->timestamp());
    }

    /**
     * Delete lapsed buckets.
     *
     * Without this the table grows by one row per distinct address, API key and
     * IP seen, forever. Called from the scheduler; `expires_at` is indexed so it
     * stays a cheap sweep however big the table has become.
     */
    public function prune(): int
    {
        return $this->connection->execute(
            'DELETE FROM rate_limits WHERE expires_at <= ?',
            [$this->clock->nowString()]
        );
    }

    private function bucket(string $key): string
    {
        return substr(hash('sha256', $key), 0, 64);
    }
}
