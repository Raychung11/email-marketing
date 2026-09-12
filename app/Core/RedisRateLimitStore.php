<?php

declare(strict_types=1);

namespace App\Core;

use Redis;

final class RedisRateLimitStore implements RateLimitStore
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'ratelimit:',
    ) {
    }

    public function increment(string $key, int $decaySeconds): int
    {
        $full  = $this->prefix . $key;
        $count = (int) $this->redis->incr($full);

        if ($count === 1) {
            $this->redis->expire($full, $decaySeconds);
        }

        return $count;
    }

    public function get(string $key): int
    {
        return (int) $this->redis->get($this->prefix . $key);
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function ttl(string $key): int
    {
        return max(0, (int) $this->redis->ttl($this->prefix . $key));
    }
}
