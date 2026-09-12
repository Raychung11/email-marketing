<?php

declare(strict_types=1);

namespace App\Mail;

final class IdentityStatus
{
    /** @param array<int,array{type:string,name:string,value:string,purpose:string}> $dnsRecords */
    public function __construct(
        public readonly string $identity,
        public readonly bool $verified,
        public readonly string $dkimStatus = 'pending',
        public readonly string $spfStatus = 'not_checked',
        public readonly string $dmarcStatus = 'not_checked',
        public readonly array $dnsRecords = [],
        public readonly ?string $error = null,
    ) {
    }
}
