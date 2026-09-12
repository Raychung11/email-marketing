<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Signer;
use App\Database\Connection;
use App\Repositories\ContactRepository;
use App\Support\TenantContext;

/**
 * Unsubscribe and the preference centre.
 *
 * Links carry an HMAC-signed, opaque token. A raw contact id NEVER appears in a
 * URL: the token holds the contact's UUID plus the organisation, so a link cannot
 * be edited into someone else's unsubscribe and ids cannot be enumerated.
 *
 * Tokens do not expire by default — an unsubscribe link in a two-year-old email
 * must still work, because a broken unsubscribe link is both a compliance failure
 * and a guaranteed spam complaint.
 */
final class UnsubscribeService
{
    public function __construct(
        private readonly Signer $signer,
        private readonly Connection $connection,
        private readonly ContactRepository $contacts,
        private readonly ConsentService $consent,
        private readonly SuppressionService $suppression,
        private readonly ActivityService $activity,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Build the token for a message.
     *
     * Called by the renderer for {{unsubscribe_url}} and for the
     * List-Unsubscribe header.
     */
    public function tokenFor(
        int $organisationId,
        string $contactUuid,
        ?int $campaignId = null,
        ?int $messageId = null,
    ): string {
        return $this->signer->sign([
            'o' => $organisationId,
            'c' => $contactUuid,
            'k' => $campaignId,
            'm' => $messageId,
            'v' => 1,
        ]);
    }

    public function urlFor(
        int $organisationId,
        string $contactUuid,
        ?int $campaignId = null,
        ?int $messageId = null,
    ): string {
        return url('unsubscribe/' . $this->tokenFor($organisationId, $contactUuid, $campaignId, $messageId));
    }

    public function preferencesUrlFor(
        int $organisationId,
        string $contactUuid,
        ?int $campaignId = null,
        ?int $messageId = null,
    ): string {
        return url('preferences/' . $this->tokenFor($organisationId, $contactUuid, $campaignId, $messageId));
    }

    /**
     * Resolve a token to its contact.
     *
     * Runs outside a bound tenant (the recipient is not logged in), so the
     * organisation comes from the signed payload and every query below filters on
     * it explicitly.
     *
     * @return array{organisation:array<string,mixed>,contact:array<string,mixed>,campaign_id:?int,message_id:?int}|null
     */
    public function resolve(string $token): ?array
    {
        $payload = $this->signer->verify($token);

        if ($payload === null) {
            return null;
        }

        $organisationId = (int) ($payload['o'] ?? 0);
        $contactUuid    = (string) ($payload['c'] ?? '');

        if ($organisationId <= 0 || $contactUuid === '') {
            return null;
        }

        $organisation = $this->connection->table('organisations')
            ->where('id', '=', $organisationId)
            ->whereNull('deleted_at')
            ->first();

        if ($organisation === null) {
            return null;
        }

        $contact = $this->connection->table('contacts')
            ->where('organisation_id', '=', $organisationId)
            ->where('uuid', '=', $contactUuid)
            ->first();

        if ($contact === null) {
            return null;
        }

        return [
            'organisation' => $organisation,
            'contact'      => $contact,
            'campaign_id'  => isset($payload['k']) ? (int) $payload['k'] : null,
            'message_id'   => isset($payload['m']) ? (int) $payload['m'] : null,
        ];
    }

    /**
     * Unsubscribe from all marketing.
     *
     * Two things happen, and both matter: a withdrawal is appended to the consent
     * history, and a suppression row is created. The suppression is what actually
     * stops future sends, including from a later import of the same address.
     *
     * @param array{organisation:array<string,mixed>,contact:array<string,mixed>,campaign_id:?int,message_id:?int} $resolved
     */
    public function unsubscribeAll(array $resolved, ?string $ip, ?string $userAgent, string $method = 'link'): void
    {
        $organisationId = (int) $resolved['organisation']['id'];
        $contact        = $resolved['contact'];
        $contactId      = (int) $contact['id'];
        $email          = (string) $contact['email'];

        $this->withTenant($resolved['organisation'], function () use (
            $contactId,
            $email,
            $resolved,
            $ip,
            $userAgent,
            $method,
            $organisationId
        ): void {
            $this->connection->transaction(function () use (
                $contactId,
                $email,
                $resolved,
                $ip,
                $userAgent,
                $method,
                $organisationId
            ): void {
                $this->consent->withdraw($contactId, [
                    'channel'          => 'email',
                    'source'           => 'unsubscribe',
                    'source_reference' => $method,
                    'ip_address'       => $ip,
                    'user_agent'       => $userAgent,
                    'campaign_id'      => $resolved['campaign_id'],
                ]);

                $this->suppression->suppressForUnsubscribe(
                    $email,
                    $resolved['campaign_id'],
                    $resolved['message_id'],
                    $ip,
                    $userAgent
                );

                $this->recordEvent(
                    $organisationId,
                    $contactId,
                    $email,
                    $resolved['campaign_id'],
                    $resolved['message_id'],
                    'all_marketing',
                    null,
                    $method,
                    $ip,
                    $userAgent
                );

                $this->activity->record(
                    'unsubscribed',
                    $contactId,
                    'Unsubscribed from all marketing email',
                    ['method' => $method, 'campaign_id' => $resolved['campaign_id']]
                );

                if ($resolved['campaign_id'] !== null) {
                    $this->connection->execute(
                        'UPDATE campaigns SET unsubscribe_count = unsubscribe_count + 1
                         WHERE id = ? AND organisation_id = ?',
                        [$resolved['campaign_id'], $organisationId]
                    );
                }
            });
        });
    }

    /**
     * Topic-level preferences.
     *
     * Choosing "no topics" is equivalent to unsubscribing from all marketing and
     * is treated as such — including the suppression row.
     *
     * @param array{organisation:array<string,mixed>,contact:array<string,mixed>,campaign_id:?int,message_id:?int} $resolved
     * @param array<int,string> $topics topics the contact wants to keep
     */
    public function updatePreferences(array $resolved, array $topics, ?string $ip, ?string $userAgent): void
    {
        /** @var array<string,string> $available */
        $available = $this->config->get('compliance.preference_topics', []);
        $topics    = array_values(array_intersect($topics, array_keys($available)));

        // Nothing kept, or "all" explicitly dropped: that is a full unsubscribe.
        if ($topics === []) {
            $this->unsubscribeAll($resolved, $ip, $userAgent, 'preference_centre');

            return;
        }

        $organisationId = (int) $resolved['organisation']['id'];
        $contactId      = (int) $resolved['contact']['id'];
        $email          = (string) $resolved['contact']['email'];

        $this->withTenant($resolved['organisation'], function () use (
            $contactId,
            $email,
            $topics,
            $available,
            $resolved,
            $ip,
            $userAgent,
            $organisationId
        ): void {
            $this->connection->transaction(function () use (
                $contactId,
                $email,
                $topics,
                $available,
                $resolved,
                $ip,
                $userAgent,
                $organisationId
            ): void {
                foreach (array_keys($available) as $topic) {
                    if ($topic === 'all') {
                        continue;
                    }

                    $keeping = in_array($topic, $topics, true);

                    $evidence = [
                        'channel'          => 'email',
                        'topic'            => $topic,
                        'source'           => 'preference_centre',
                        'source_reference' => 'preference_centre',
                        'ip_address'       => $ip,
                        'user_agent'       => $userAgent,
                        'campaign_id'      => $resolved['campaign_id'],
                        'consent_type'     => 'express',
                    ];

                    $keeping
                        ? $this->consent->grant($contactId, $evidence)
                        : $this->consent->withdraw($contactId, $evidence);
                }

                // The contact still wants some marketing, so channel-level
                // consent is re-affirmed.
                $this->consent->grant($contactId, [
                    'channel'          => 'email',
                    'consent_type'     => 'express',
                    'source'           => 'preference_centre',
                    'source_reference' => 'topics:' . implode(',', $topics),
                    'ip_address'       => $ip,
                    'user_agent'       => $userAgent,
                ]);

                // This link came from the contact's own inbox, so keeping some
                // topics is a verified re-subscribe. It clears an earlier
                // *unsubscribe* suppression and nothing else — a bounce, a
                // complaint, a legal hold or an admin block all stand.
                $this->suppression->clearOnContactReaffirmation($email, 'preference_centre');

                $this->recordEvent(
                    $organisationId,
                    $contactId,
                    $email,
                    $resolved['campaign_id'],
                    $resolved['message_id'],
                    'topic',
                    implode(',', $topics),
                    'preference_centre',
                    $ip,
                    $userAgent
                );

                $this->activity->record(
                    'preferences_updated',
                    $contactId,
                    'Updated email preferences',
                    ['topics' => $topics]
                );
            });
        });
    }

    /**
     * Current topic preferences for the preference centre form.
     *
     * @param array{organisation:array<string,mixed>,contact:array<string,mixed>} $resolved
     * @return array<string,bool>
     */
    public function currentPreferences(array $resolved): array
    {
        /** @var array<string,string> $available */
        $available = $this->config->get('compliance.preference_topics', []);
        $contactId = (int) $resolved['contact']['id'];

        $preferences = [];

        $this->withTenant($resolved['organisation'], function () use ($available, $contactId, &$preferences): void {
            $channelConsent = $this->consent->current($contactId, 'email');
            $channelGranted = $channelConsent !== null && (string) $channelConsent['status'] === 'granted';

            foreach (array_keys($available) as $topic) {
                if ($topic === 'all') {
                    $preferences[$topic] = $channelGranted;
                    continue;
                }

                $row = $this->connection->table('contact_consents')
                    ->where('organisation_id', '=', $this->tenant->organisationId())
                    ->where('contact_id', '=', $contactId)
                    ->where('channel', '=', 'email')
                    ->where('topic', '=', $topic)
                    ->orderBy('id', 'desc')
                    ->first();

                // No explicit topic preference means the contact is included while
                // channel consent stands.
                $preferences[$topic] = $row === null
                    ? $channelGranted
                    : (string) $row['status'] === 'granted';
            }
        });

        return $preferences;
    }

    /**
     * The unsubscribe flow runs for an anonymous visitor, so bind the tenant from
     * the signed token for the duration of the operation and unbind afterwards.
     * This keeps every repository call organisation-scoped exactly as it is in an
     * authenticated request.
     *
     * @param array<string,mixed> $organisation
     */
    private function withTenant(array $organisation, callable $callback): void
    {
        $wasBound     = $this->tenant->isBound();
        $previousId   = $wasBound ? $this->tenant->organisationId() : null;
        $previousOrg  = $wasBound ? $this->tenant->organisation() : [];

        $this->tenant->bind((int) $organisation['id'], null, $organisation);

        try {
            $callback();
        } finally {
            $this->tenant->clear();

            if ($previousId !== null) {
                $this->tenant->bind($previousId, null, $previousOrg);
            }
        }
    }

    private function recordEvent(
        int $organisationId,
        ?int $contactId,
        string $email,
        ?int $campaignId,
        ?int $messageId,
        string $scope,
        ?string $topic,
        string $method,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $this->connection->table('unsubscribe_events')->insert([
            'organisation_id'  => $organisationId,
            'contact_id'       => $contactId,
            'email'            => $email,
            'campaign_id'      => $campaignId,
            'email_message_id' => $messageId,
            'scope'            => $scope,
            'topic'            => $topic,
            'method'           => $method,
            'ip_address'       => $ip,
            'user_agent'       => $userAgent === null ? null : substr($userAgent, 0, 255),
            'created_at'       => $this->clock->nowString(),
        ]);
    }
}
