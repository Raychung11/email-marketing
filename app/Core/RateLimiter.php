<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fixed-window rate limiter used for login throttling, API keys and public
 * endpoints. Backed by a pluggable store so production can use Redis while
 * tests use an array.
 */
final class RateLimiter
{
    public function __construct(private readonly RateLimitStore $store)
    {
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    public function hit(string $key, int $decaySeconds): int
    {
        return $this->store->increment($key, $decaySeconds);
    }

    public function attempts(string $key): int
    {
        return $this->store->get($key);
    }

    public function clear(string $key): void
    {
        $this->store->forget($key);
    }

    public function availableIn(string $key): int
    {
        return $this->store->ttl($key);
    }
}
