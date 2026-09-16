<?php

declare(strict_types=1);

namespace App\Support;

/** Test double. Serves only what a test has explicitly put in it. */
final class FakeHttpFetcher implements HttpFetcher
{
    /** @var array<string,string> */
    private array $responses = [];

    /** @var array<int,string> */
    private array $requested = [];

    public function stub(string $url, string $body): void
    {
        $this->responses[$url] = $body;
    }

    public function get(string $url, int $timeoutSeconds = 5): ?string
    {
        $this->requested[] = $url;

        return $this->responses[$url] ?? null;
    }

    /** @return array<int,string> */
    public function requested(): array
    {
        return $this->requested;
    }
}
