<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader.
 *
 * Values are read into an internal map rather than $_ENV/putenv() so that a
 * leaked phpinfo() or a child process cannot trivially expose credentials.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip inline comments only when the value is not quoted.
            if (!str_starts_with($value, '"') && !str_starts_with($value, "'")) {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$values)) {
            return self::cast(self::$values[$key]);
        }

        // Real environment variables win when no .env entry exists; this is how
        // container/systemd deployments inject secrets.
        $fromEnv = getenv($key);

        return $fromEnv === false ? $default : self::cast($fromEnv);
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    private static function cast(string $value): mixed
    {
        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            ''                 => '',
            default            => $value,
        };
    }
}
