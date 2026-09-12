<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Per-session CSRF token with constant-time verification.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->put(self::KEY, $token);
        }

        return $token;
    }

    public function verify(?string $candidate): bool
    {
        if (!is_string($candidate) || $candidate === '') {
            return false;
        }

        $token = $this->session->get(self::KEY);

        if (!is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($token, $candidate);
    }

    public function rotate(): void
    {
        $this->session->forget(self::KEY);
    }
}
