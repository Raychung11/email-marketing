<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Password hashing. bcrypt by default; cost is configurable so it can be raised
 * as hardware improves without a code change.
 */
final class Hash
{
    public function __construct(private readonly int $cost = 12)
    {
    }

    public function make(string $plain): string
    {
        $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => $this->cost]);

        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash password.');
        }

        return $hash;
    }

    public function check(string $plain, string $hash): bool
    {
        if ($hash === '') {
            // Still burn time so a missing user is not distinguishable by timing.
            password_verify($plain, '$2y$12$usesomesillystringfor.e/AltoTrmvJZjR2Wy8pmaHBDN5J8k7Q6');

            return false;
        }

        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $this->cost]);
    }
}
