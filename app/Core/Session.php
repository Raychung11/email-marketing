<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session wrapper with a swappable backing store.
 *
 * The native driver uses PHP sessions with hardened cookie settings. The array
 * driver keeps everything in memory so the HTTP stack can be exercised in tests
 * without touching the filesystem or emitting headers.
 */
final class Session
{
    public const DRIVER_NATIVE = 'native';
    public const DRIVER_ARRAY  = 'array';

    /** @var array<string,mixed> */
    private array $store = [];

    private bool $started = false;

    /** @var array<string,mixed>|null */
    private ?array $pulledFlash = null;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config = [],
        private readonly string $driver = self::DRIVER_NATIVE,
    ) {
    }

    /**
     * Reset per-request state. Called once per request by StartSession.
     *
     * In production each request is a fresh process, but the test suite and any
     * long-lived worker reuse the object, and a flash bag cached from the
     * previous request would silently swallow the next one's messages.
     */
    public function beginRequest(): void
    {
        $this->pulledFlash = null;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        if ($this->driver === self::DRIVER_ARRAY) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->store = &$_SESSION;

            return;
        }

        session_name((string) ($this->config['name'] ?? 'aigh_session'));

        session_set_cookie_params([
            'lifetime' => (int) ($this->config['lifetime'] ?? 7200),
            'path'     => '/',
            'domain'   => (string) ($this->config['domain'] ?? ''),
            'secure'   => (bool) ($this->config['secure'] ?? false),
            'httponly' => true,
            'samesite' => (string) ($this->config['samesite'] ?? 'Lax'),
        ]);

        // Never accept a session id supplied in the URL.
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');

        session_start();

        $this->store = &$_SESSION;

        $this->enforceAbsoluteTimeout();
        $this->rotatePeriodically();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }

    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->store;
    }

    public function flush(): void
    {
        $this->store = [];

        if ($this->driver === self::DRIVER_NATIVE && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    /**
     * Regenerate the session id while preserving data.
     *
     * Called on login, logout, organisation switch and any privilege change to
     * defeat session fixation.
     */
    public function regenerate(): void
    {
        if ($this->driver === self::DRIVER_NATIVE && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $this->store['_regenerated_at'] = time();
    }

    public function invalidate(): void
    {
        $this->flush();
        $this->regenerate();
    }

    /** Flash data survives exactly one subsequent request. */
    public function flash(string $key, mixed $value): void
    {
        $flash                 = $this->store['_flash'] ?? [];
        $flash[$key]           = $value;
        $this->store['_flash'] = $flash;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->store['_flash'][$key] ?? $default;
    }

    /**
     * Read and clear the flash bag.
     *
     * Idempotent within a request: both the view-context middleware and the
     * controller ask for it, and the second caller must see the same values
     * rather than an empty bag.
     *
     * @return array<string,mixed>
     */
    public function pullFlash(): array
    {
        if ($this->pulledFlash !== null) {
            return $this->pulledFlash;
        }

        $flash = $this->store['_flash'] ?? [];
        unset($this->store['_flash']);

        return $this->pulledFlash = is_array($flash) ? $flash : [];
    }

    private function enforceAbsoluteTimeout(): void
    {
        $max = (int) ($this->config['absolute_lifetime'] ?? 86400);

        $createdAt = $this->store['_created_at'] ?? null;

        if ($createdAt === null) {
            $this->store['_created_at'] = time();

            return;
        }

        if (time() - (int) $createdAt > $max) {
            $this->flush();
            $this->regenerate();
            $this->store['_created_at'] = time();
        }
    }

    private function rotatePeriodically(): void
    {
        $interval = (int) ($this->config['regenerate_every'] ?? 900);
        $last     = (int) ($this->store['_regenerated_at'] ?? 0);

        if (time() - $last > $interval) {
            $this->regenerate();
        }
    }
}
