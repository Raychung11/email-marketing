<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mail\EmailProviderInterface;
use App\Mail\IdentityStatus;
use App\Mail\OutboundMessage;
use App\Mail\ReputationMetrics;
use App\Mail\SendQuota;
use App\Mail\SendResult;
use RuntimeException;

/**
 * A provider that throws instead of answering.
 *
 * The case a SendResult cannot express: a timeout, a connection reset, a worker
 * killed mid-call. There is no result, and crucially no way to tell from here
 * whether the request arrived — which is the whole reason the pipeline has an
 * `outcome_unknown` state to put the recipient into.
 */
final class ExplodingEmailProvider implements EmailProviderInterface
{
    /** @var array<int,OutboundMessage> */
    private array $attempted = [];

    public function __construct(private readonly string $message = 'Connection reset by peer')
    {
    }

    public function channel(): string
    {
        return 'email';
    }

    public function name(): string
    {
        return 'exploding';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): SendResult
    {
        $this->attempted[] = $message;

        throw new RuntimeException($this->message);
    }

    public function validateIdentity(string $identity): IdentityStatus
    {
        return new IdentityStatus($identity, true, 'verified', 'verified', 'verified');
    }

    public function getQuota(): SendQuota
    {
        return new SendQuota(1_000_000.0, 100.0, 0.0);
    }

    public function getReputationMetrics(): ReputationMetrics
    {
        return new ReputationMetrics();
    }

    /** @return array<int,array{type:string,name:string,value:string,purpose:string}> */
    public function dnsRecordsFor(string $domain): array
    {
        return [];
    }

    /** @return array<int,OutboundMessage> */
    public function attemptedMessages(): array
    {
        return $this->attempted;
    }
}
