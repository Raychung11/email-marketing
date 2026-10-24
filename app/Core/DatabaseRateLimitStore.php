<?php

declare(strict_types=1);

namespace App\Core;

use App\Database\Connection;
use Throwable;

/**
 * Rate-limit counters in the database, for every deployment without Redis.
 *
 * WHY THIS EXISTS.
 *
 * The store used to be picked from the queue driver, so anything but Redis got
 * an in-process PHP array. On shared hosting — where the queue driver is
 * `database` because it has to be — that meant every HTTP request began with an
 * empty counter. Login throttling counted to one and reset on the next request.
 * The API key limit never bound. The public endpoints were not limited at all.
 *
 * THE WINDOW IS FIXED, AND THAT IS A CHOICE.
 *
 * A fixed window lets up to 2n requests through across a window boundary, which
 * a sliding window would not. It is one row and one statement per check, it is
 * what the Redis store already does, and the limits here are coarse enough
 * (five logins in fifteen minutes, a hundred and twenty API calls a minute)
 * that the boundary case is not the thing an attacker would exploit. Matching
 * the existing semantics matters more than the extra precision.
 *
 * THE INCREMENT IS ONE STATEMENT.
 *
 * Two concurrent requests must not both read 4, both write 5 and both be let
 * through. The upsert does the read, the expiry check and the increment inside
 * the database, where the unique index on the key serialises them.
 *
 * The count is read back in a second statement. Under concurrency that can
 * return a value higher than this caller's own increment produced — never
 * lower — so the limiter errs towards refusing rather than letting something
 * extra through. For a throttle that is the right direction to be wrong in.
 */
final class DatabaseRateLimitStore implements RateLimitStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function increment(string $key, int $decaySeconds): int
    {
        $now     = $this->clock->now();
        $nowSql  = $now->format('Y-m-d H:i:s');
        $expires = $now->modify('+' . max(1, $decaySeconds) . ' seconds')->format('Y-m-d H:i:s');

        /*
         * An expired window is reset rather than deleted and reinserted: the
         * row is the lock. Deleting it first would open a gap in which two
         * requests both insert, and the unique index would turn one of them
         * into an error instead of a count.
         */
        if ($this->connection->driver() === 'sqlite') {
            $this->connection->execute(
                'INSERT INTO rate_limits (limit_key, hits, expires_at, created_at)
                 VALUES (?, 1, ?, ?)
                 ON CONFLICT (limit_key) DO UPDATE SET
                     hits = CASE WHEN rate_limits.expires_at <= ? THEN 1 ELSE rate_limits.hits + 1 END,
                     expires_at = CASE WHEN rate_limits.expires_at <= ? THEN ? ELSE rate_limits.expires_at END',
                [$key, $expires, $nowSql, $nowSql, $nowSql, $expires]
            );
        } else {
            $this->connection->execute(
                'INSERT INTO rate_limits (limit_key, hits, expires_at, created_at)
                 VALUES (?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     hits = IF(expires_at <= ?, 1, hits + 1),
                     expires_at = IF(expires_at <= ?, ?, expires_at)',
                [$key, $expires, $nowSql, $nowSql, $nowSql, $expires]
            );
        }

        return $this->get($key);
    }

    public function get(string $key): int
    {
        return (int) $this->connection->scalar(
            'SELECT hits FROM rate_limits WHERE limit_key = ? AND expires_at > ?',
            [$key, $this->clock->nowString()]
        );
    }

    public function forget(string $key): void
    {
        $this->connection->execute('DELETE FROM rate_limits WHERE limit_key = ?', [$key]);
    }

    public function ttl(string $key): int
    {
        $expires = $this->connection->scalar(
            'SELECT expires_at FROM rate_limits WHERE limit_key = ?',
            [$key]
        );

        if (!is_string($expires) || $expires === '') {
            return 0;
        }

        return max(0, strtotime($expires) - $this->clock->timestamp());
    }

    /**
     * Drop windows that have closed.
     *
     * Without it the table grows by one row per key per window for ever — on a
     * busy public endpoint that is a row per IP per minute, which is millions a
     * month on a host that charges for the disk.
     *
     * Run from the scheduler. Bounded per call so it never turns into a long
     * lock on a table that every request writes to.
     */
    public function purgeExpired(int $limit = 5000): int
    {
        try {
            return $this->connection->execute(
                'DELETE FROM rate_limits WHERE expires_at <= ? LIMIT ' . max(1, $limit),
                [$this->clock->nowString()]
            );
        } catch (Throwable $e) {
            // SQLite is built without DELETE ... LIMIT more often than not, and
            // a failed tidy-up must never take a scheduler tick down with it.
            $this->logger?->warning('Could not purge expired rate limits with a bounded delete.', [
                'error' => $e->getMessage(),
            ]);

            return $this->connection->execute(
                'DELETE FROM rate_limits WHERE expires_at <= ?',
                [$this->clock->nowString()]
            );
        }
    }
}
