<?php

declare(strict_types=1);

use App\Core\Container;
use App\Core\Env;

if (!function_exists('app')) {
    /**
     * Resolve a service from the container, or the container itself.
     *
     * @template T of object
     * @param class-string<T>|string|null $abstract
     * @return T|Container|mixed
     */
    function app(?string $abstract = null): mixed
    {
        $container = Container::getInstance();

        return $abstract === null ? $container : $container->make($abstract);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return app(App\Core\Config::class)->get($key, $default);
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_path')) {
    function base_path(string $append = ''): string
    {
        $base = dirname(__DIR__, 2);

        return $append === '' ? $base : $base . '/' . ltrim($append, '/');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $append = ''): string
    {
        return base_path('storage' . ($append === '' ? '' : '/' . ltrim($append, '/')));
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url', 'http://localhost'), '/');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('uuid4')) {
    function uuid4(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

if (!function_exists('normalize_email')) {
    /**
     * Canonical form used for deduplication and suppression matching.
     *
     * Lowercases and trims. It deliberately does NOT strip gmail dots or
     * plus-addresses: those are different mailboxes as far as consent and
     * unsubscribe are concerned, and collapsing them would let one person's
     * unsubscribe silently suppress another's address.
     */
    function normalize_email(string $email): string
    {
        $email = trim($email);
        $at    = strrpos($email, '@');

        if ($at === false) {
            return mb_strtolower($email);
        }

        $local  = substr($email, 0, $at);
        $domain = mb_strtolower(substr($email, $at + 1));

        return mb_strtolower($local) . '@' . $domain;
    }
}

if (!function_exists('is_valid_email')) {
    function is_valid_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 254;
    }
}

if (!function_exists('array_get')) {
    /** @param array<string,mixed> $array */
    function array_get(array $array, string $key, mixed $default = null): mixed
    {
        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('str_slug')) {
    function str_slug(string $value, string $separator = '-'): string
    {
        $value = preg_replace('/[^\p{L}\p{N}]+/u', $separator, $value) ?? $value;
        $value = preg_replace('/' . preg_quote($separator, '/') . '+/', $separator, $value) ?? $value;

        return trim(mb_strtolower($value), $separator);
    }
}

if (!function_exists('money')) {
    function money(float|int|string $amount, string $currency = 'USD'): string
    {
        $symbols = ['USD' => '$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'GBP' => '£', 'EUR' => '€', 'CAD' => 'C$'];
        $symbol  = $symbols[strtoupper($currency)] ?? '';

        return $symbol . number_format((float) $amount, 2);
    }
}
