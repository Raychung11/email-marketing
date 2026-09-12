<?php

declare(strict_types=1);

namespace App\Core;

final class ArrayRateLimitStore implements RateLimitStore
{
    /** @var array<string,array{count:int,expires:int}> */
    private array $buckets = [];

    public function __construct(private readonly Clock $clock = new Clock())
    {
    }

    public function increment(string $key, int $decaySeconds): int
    {
        $now = $this->clock->timestamp();

        if (!isset($this->buckets[$key]) || $this->buckets[$key]['expires'] <= $now) {
            $this->buckets[$key] = ['count' => 0, 'expires' => $now + $decaySeconds];
        }

        return ++$this->buckets[$key]['count'];
    }

    public function get(string $key): int
    {
        $now = $this->clock->timestamp();

        if (!isset($this->buckets[$key]) || $this->buckets[$key]['expires'] <= $now) {
            return 0;
        }

        return $this->buckets[$key]['count'];
    }

    public function forget(string $key): void
    {
        unset($this->buckets[$key]);
    }

    public function ttl(string $key): int
    {
        $now = $this->clock->timestamp();

        return isset($this->buckets[$key]) ? max(0, $this->buckets[$key]['expires'] - $now) : 0;
    }
}
