<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dot-notation configuration repository loaded from config/*.php.
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $items = [];

    public function __construct(private readonly string $path)
    {
    }

    public function load(): void
    {
        foreach (glob($this->path . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            /** @psalm-suppress UnresolvableInclude */
            $this->items[$key] = require $file;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value    = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref      = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }

        $ref = $value;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
