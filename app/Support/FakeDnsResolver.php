<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Test double. Records are declared per host so a test can describe a zone that
 * is correctly configured, partly configured, or wrong.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string,array<int,string>> */
    private array $cnames = [];

    /** @var array<string,array<int,string>> */
    private array $txt = [];

    /** @var array<string,array<int,string>> */
    private array $mx = [];

    public function setCname(string $host, string ...$targets): void
    {
        $this->cnames[strtolower($host)] = array_map('strtolower', $targets);
    }

    public function setTxt(string $host, string ...$values): void
    {
        $this->txt[strtolower($host)] = $values;
    }

    public function setMx(string $host, string ...$targets): void
    {
        $this->mx[strtolower($host)] = array_map('strtolower', $targets);
    }

    public function cname(string $host): array
    {
        return $this->cnames[strtolower($host)] ?? [];
    }

    public function txt(string $host): array
    {
        return $this->txt[strtolower($host)] ?? [];
    }

    public function mx(string $host): array
    {
        return $this->mx[strtolower($host)] ?? [];
    }
}
