<?php

declare(strict_types=1);

namespace App\Core;

interface RateLimitStore
{
    public function increment(string $key, int $decaySeconds): int;

    public function get(string $key): int;

    public function forget(string $key): void;

    public function ttl(string $key): int;
}
