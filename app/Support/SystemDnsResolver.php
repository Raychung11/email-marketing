<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Live DNS via the system resolver.
 *
 * Lookups are cached for the duration of the request: the verification screen
 * checks several records for the same domain, and re-asking the resolver for each
 * one makes an already slow page slower.
 */
final class SystemDnsResolver implements DnsResolver
{
    /** @var array<string,array<int,string>> */
    private array $cache = [];

    public function cname(string $host): array
    {
        return $this->lookup($host, DNS_CNAME, static function (array $record): ?string {
            $target = $record['target'] ?? null;

            return is_string($target) ? strtolower(rtrim($target, '.')) : null;
        });
    }

    public function txt(string $host): array
    {
        return $this->lookup($host, DNS_TXT, static function (array $record): ?string {
            // Long TXT values arrive split into 255-byte strings; a DKIM or SPF
            // record split this way is still one record.
            if (isset($record['entries']) && is_array($record['entries'])) {
                return implode('', $record['entries']);
            }

            return isset($record['txt']) && is_string($record['txt']) ? $record['txt'] : null;
        });
    }

    public function mx(string $host): array
    {
        return $this->lookup($host, DNS_MX, static function (array $record): ?string {
            $target = $record['target'] ?? null;

            return is_string($target) ? strtolower(rtrim($target, '.')) : null;
        });
    }

    /** @param callable(array<string,mixed>):?string $extract */
    private function lookup(string $host, int $type, callable $extract): array
    {
        $key = $type . ':' . strtolower($host);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // A missing record is the normal case during setup, not an error, so
        // warnings are suppressed and an empty result speaks for itself.
        $records = @dns_get_record($host, $type);

        if ($records === false) {
            return $this->cache[$key] = [];
        }

        $values = [];

        foreach ($records as $record) {
            $value = $extract($record);

            if ($value !== null && $value !== '') {
                $values[] = $value;
            }
        }

        return $this->cache[$key] = $values;
    }
}
