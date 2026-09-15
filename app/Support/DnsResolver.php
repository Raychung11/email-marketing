<?php

declare(strict_types=1);

namespace App\Support;

/**
 * DNS lookups, behind an interface.
 *
 * Domain verification is the one part of the sending setup that depends on the
 * outside world. Injecting the resolver means the wizard's logic — "are all three
 * DKIM records published? does SPF include the provider? is there a DMARC
 * policy?" — is testable without a live zone, and a flaky network cannot make the
 * test suite lie.
 */
interface DnsResolver
{
    /**
     * @return array<int,string> the target of each CNAME found, lowercased and
     *                           without a trailing dot
     */
    public function cname(string $host): array;

    /**
     * @return array<int,string> each TXT record, concatenated if it was split
     *                           across strings
     */
    public function txt(string $host): array;

    /** @return array<int,string> */
    public function mx(string $host): array;
}
