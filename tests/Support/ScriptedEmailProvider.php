<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mail\EmailProviderInterface;
use App\Mail\IdentityStatus;
use App\Mail\OutboundMessage;
use App\Mail\ReputationMetrics;
use App\Mail\SendQuota;
use App\Mail\SendResult;

/**
 * A provider whose answers are scripted, so the failure paths can be tested.
 *
 * The happy path is covered by LogEmailProvider; what needs a fake is the
 * behaviour that only shows up when a provider says no — a rejection that must
 * never be retried, and a throttle that must be.
 */
final class ScriptedEmailProvider implements EmailProviderInterface
{
    /** @var array<int,SendResult> */
    private array $script = [];

    /** @var array<int,OutboundMessage> */
    private array $sent = [];

    public function __construct(private readonly SendQuota $quota = new SendQuota(1_000_000.0, 100.0, 0.0))
    {
    }

    public function script(SendResult ...$results): void
    {
        $this->script = array_values($results);
    }

    public function channel(): string
    {
        return 'email';
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): SendResult
    {
        $this->sent[] = $message;

        // Once the script runs out, behave like a working provider.
        return array_shift($this->script) ?? SendResult::accepted('scripted-' . count($this->sent));
    }

    public function validateIdentity(string $identity): IdentityStatus
    {
        return new IdentityStatus($identity, true, 'verified', 'verified', 'verified');
    }

    public function getQuota(): SendQuota
    {
        return $this->quota;
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
    public function sentMessages(): array
    {
        return $this->sent;
    }
}
