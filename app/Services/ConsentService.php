<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Repositories\ConsentRepository;
use App\Repositories\ContactRepository;
use App\Support\TenantContext;

/**
 * Consent is a history, never a flag.
 *
 * Every method here APPENDS. There is no path through this service that mutates
 * or removes an existing consent record, because the history is the evidence.
 */
final class ConsentService
{
    public function __construct(
        private readonly ConsentRepository $consents,
        private readonly ContactRepository $contacts,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Record a grant of consent.
     *
     * @param array{
     *   channel?:string, consent_type?:string, source?:string, source_reference?:string,
     *   consent_text?:string, topic?:string, ip_address?:string, user_agent?:string,
     *   recorded_by_user_id?:int, campaign_id?:int, expires_at?:string
     * } $evidence
     */
    public function grant(int $contactId, array $evidence = []): int
    {
        $id = $this->consents->record([
            'contact_id'             => $contactId,
            'channel'                => $evidence['channel'] ?? 'email',
            'status'                 => 'granted',
            'consent_type'           => $evidence['consent_type'] ?? 'express',
            'source'                 => $evidence['source'] ?? 'manual',
            'source_reference'       => $evidence['source_reference'] ?? null,
            'consent_text'           => $evidence['consent_text'] ?? null,
            'privacy_policy_version' => $evidence['privacy_policy_version']
                ?? (string) $this->config->get('compliance.privacy_policy_version', '1.0'),
            'terms_version'          => $evidence['terms_version']
                ?? (string) $this->config->get('compliance.terms_version', '1.0'),
            'ip_address'             => $evidence['ip_address'] ?? null,
            'user_agent'             => $evidence['user_agent'] ?? null,
            'topic'                  => $evidence['topic'] ?? null,
            'consented_at'           => $evidence['consented_at'] ?? $this->clock->nowString(),
            'expires_at'             => $evidence['expires_at'] ?? null,
            'recorded_by_user_id'    => $evidence['recorded_by_user_id'] ?? null,
            'campaign_id'            => $evidence['campaign_id'] ?? null,
        ]);

        $this->refreshCache($contactId, (string) ($evidence['channel'] ?? 'email'));

        $this->audit->log('consent_granted', 'contact', $contactId, null, [
            'channel'      => $evidence['channel'] ?? 'email',
            'consent_type' => $evidence['consent_type'] ?? 'express',
            'source'       => $evidence['source'] ?? 'manual',
            'topic'        => $evidence['topic'] ?? null,
        ]);

        return $id;
    }

    /**
     * Record a withdrawal. Never an update: this is a new row that supersedes the
     * previous state while leaving it intact.
     *
     * @param array<string,mixed> $evidence
     */
    public function withdraw(int $contactId, array $evidence = []): int
    {
        $id = $this->consents->record([
            'contact_id'          => $contactId,
            'channel'             => $evidence['channel'] ?? 'email',
            'status'              => 'withdrawn',
            'consent_type'        => $evidence['consent_type'] ?? 'other',
            'source'              => $evidence['source'] ?? 'unsubscribe',
            'source_reference'    => $evidence['source_reference'] ?? null,
            'topic'               => $evidence['topic'] ?? null,
            'ip_address'          => $evidence['ip_address'] ?? null,
            'user_agent'          => $evidence['user_agent'] ?? null,
            'withdrawn_at'        => $this->clock->nowString(),
            'recorded_by_user_id' => $evidence['recorded_by_user_id'] ?? null,
            'campaign_id'         => $evidence['campaign_id'] ?? null,
        ]);

        $this->refreshCache($contactId, (string) ($evidence['channel'] ?? 'email'));

        $this->audit->log('consent_withdrawn', 'contact', $contactId, null, [
            'channel' => $evidence['channel'] ?? 'email',
            'source'  => $evidence['source'] ?? 'unsubscribe',
            'topic'   => $evidence['topic'] ?? null,
        ]);

        return $id;
    }

    /** @param array<string,mixed> $evidence */
    public function deny(int $contactId, array $evidence = []): int
    {
        $id = $this->consents->record([
            'contact_id'          => $contactId,
            'channel'             => $evidence['channel'] ?? 'email',
            'status'              => 'denied',
            'consent_type'        => 'other',
            'source'              => $evidence['source'] ?? 'website_form',
            'consent_text'        => $evidence['consent_text'] ?? null,
            'ip_address'          => $evidence['ip_address'] ?? null,
            'user_agent'          => $evidence['user_agent'] ?? null,
            'recorded_by_user_id' => $evidence['recorded_by_user_id'] ?? null,
        ]);

        $this->refreshCache($contactId, (string) ($evidence['channel'] ?? 'email'));

        return $id;
    }

    /**
     * Record that consent state is not known.
     *
     * Used by CRM migrations and unknown-source imports. It is an explicit
     * statement of ignorance, which is exactly what a jurisdiction requiring a
     * consent basis needs to see in order to block the send.
     *
     * @param array<string,mixed> $evidence
     */
    public function recordUnknown(int $contactId, array $evidence = []): int
    {
        $id = $this->consents->record([
            'contact_id'          => $contactId,
            'channel'             => $evidence['channel'] ?? 'email',
            'status'              => 'unknown',
            'consent_type'        => $evidence['consent_type'] ?? 'other',
            'source'              => $evidence['source'] ?? 'import',
            'source_reference'    => $evidence['source_reference'] ?? null,
            'ip_address'          => $evidence['ip_address'] ?? null,
            'user_agent'          => $evidence['user_agent'] ?? null,
            'recorded_by_user_id' => $evidence['recorded_by_user_id'] ?? null,
        ]);

        $this->refreshCache($contactId, (string) ($evidence['channel'] ?? 'email'));

        return $id;
    }

    /** @return array<string,mixed>|null */
    public function current(int $contactId, string $channel = 'email'): ?array
    {
        return $this->consents->current($contactId, $channel);
    }

    public function status(int $contactId, string $channel = 'email'): string
    {
        $current = $this->consents->current($contactId, $channel);

        return $current === null ? 'unknown' : (string) $current['status'];
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $contactId, ?string $channel = null): array
    {
        return $this->consents->history($contactId, $channel);
    }

    /**
     * Whether the recorded consent has expired. Expiry is optional and only set
     * where a rule set or an organisation policy defines one.
     *
     * @param array<string,mixed> $consent
     */
    public function isExpired(array $consent): bool
    {
        $expiresAt = $consent['expires_at'] ?? null;

        return is_string($expiresAt) && $expiresAt !== '' && $expiresAt < $this->clock->nowString();
    }

    /** @return array<string,int> */
    public function statusBreakdown(string $channel = 'email'): array
    {
        return $this->consents->statusBreakdown($channel);
    }

    /**
     * Keep the denormalised mirror on contacts in step.
     *
     * This column exists only so that list screens and segment previews avoid a
     * correlated subquery per row. The send path never reads it.
     */
    private function refreshCache(int $contactId, string $channel): void
    {
        if ($channel !== 'email') {
            return;
        }

        $current    = $this->consents->current($contactId, 'email');
        $hasConsent = $current !== null
            && (string) $current['status'] === 'granted'
            && !$this->isExpired($current);

        $this->contacts->setConsentCache($contactId, $hasConsent);
    }
}
