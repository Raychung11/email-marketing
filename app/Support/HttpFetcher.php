<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Fetches a URL over HTTPS.
 *
 * An interface rather than a bare curl call for the same reason DnsResolver is:
 * the signature-verification path has to be testable without reaching the
 * internet, and a test that depends on Amazon being up is a test that fails on a
 * train.
 */
interface HttpFetcher
{
    /** Returns the body, or null if the request failed for any reason. */
    public function get(string $url, int $timeoutSeconds = 5): ?string;
}
