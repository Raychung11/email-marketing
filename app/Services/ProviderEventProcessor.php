<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Logger;
use App\Database\Connection;
use App\Support\TenantContext;

/**
 * Inbound provider events (SES via SNS, and any future provider).
 *
 * Two properties matter here and both are tested:
 *
 *  1. IDEMPOTENCY. Providers redeliver. The unique index on
 *     (provider, provider_event_id) is what makes a repeat a no-op rather than a
 *     double count — the check is the database's, not a racy SELECT-then-INSERT.
 *
 *  2. Correct escalation. A permanent bounce or a complaint suppresses
 *     immediately. A transient bounce does NOT: it is counted, and only escalates
 *     after a configured number of failures.
 *
 * Payloads arriving from a provider are untrusted input. The transport is
 * responsible for verifying the signature before anything reaches this class,
 * and nothing here trusts a recipient-supplied field to decide tenancy.
 */
final class ProviderEventProcessor
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SuppressionService $suppressions,
        private readonly ActivityService $activity,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly TenantContext $tenant,
        private readonly \App\Core\Config $config,
    ) {
    }

    /**
     * @param array{
     *   provider?:string, provider_event_id?:string, event_type:string, email:string,
     *   provider_message_id?:string, bounce_type?:string, bounce_subtype?:string,
     *   complaint_type?:string, clicked_url?:string, event_at?:string,
     *   user_agent?:string, ip_address?:string, metadata?:array<string,mixed>
     * } $event
     * @return array{recorded:bool,reason:string}
     */
    public function process(array $event): array
    {
        $provider  = (string) ($event['provider'] ?? 'ses');
        $eventId   = (string) ($event['provider_event_id'] ?? '');
        $eventType = strtolower((string) $event['event_type']);
        $email     = (string) $event['email'];

        if ($eventId === '') {
            // Without a provider event id there is no way to de-duplicate, so
            // synthesise a stable one from the event's own content.
            $eventId = hash('sha256', implode('|', [
                $provider,
                $eventType,
                $email,
                (string) ($event['provider_message_id'] ?? ''),
                (string) ($event['event_at'] ?? ''),
            ]));
        }

        // Resolve the message first: the organisation comes from our own record,
        // never from the inbound payload.
        $message = null;

        if (($event['provider_message_id'] ?? '') !== '') {
            $message = $this->connection->table('email_messages')
                ->where('provider_message_id', '=', (string) $event['provider_message_id'])
                ->first();
        }

        if ($message === null) {
            $this->logger->info('Provider event for an unknown message', [
                'provider'   => $provider,
                'event_type' => $eventType,
            ]);

            return ['recorded' => false, 'reason' => 'unknown_message'];
        }

        $organisationId = (int) $message['organisation_id'];

        try {
            $this->connection->table('email_events')->insert([
                'organisation_id'    => $organisationId,
                'email_message_id'   => (int) $message['id'],
                'campaign_id'        => $message['campaign_id'] ?? null,
                'contact_id'         => $message['contact_id'] ?? null,
                'event_type'         => $eventType,
                'provider'           => $provider,
                'provider_event_id'  => $eventId,
                'bounce_type'        => $event['bounce_type'] ?? null,
                'bounce_subtype'     => $event['bounce_subtype'] ?? null,
                'complaint_type'     => $event['complaint_type'] ?? null,
                'clicked_url'        => $event['clicked_url'] ?? null,
                'ip_address'         => $event['ip_address'] ?? null,
                'user_agent'         => isset($event['user_agent']) ? substr((string) $event['user_agent'], 0, 255) : null,
                'metadata_json'      => isset($event['metadata'])
                    ? json_encode($event['metadata'], JSON_UNESCAPED_SLASHES)
                    : null,
                'event_at'           => (string) ($event['event_at'] ?? $this->clock->nowString()),
                'created_at'         => $this->clock->nowString(),
            ]);
        } catch (\Throwable $e) {
            // The unique index rejected it: this is a redelivery, which is normal
            // and must not be counted twice.
            return ['recorded' => false, 'reason' => 'duplicate'];
        }

        $this->applySideEffects($eventType, $event, $message, $organisationId, $email);

        return ['recorded' => true, 'reason' => 'ok'];
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $message
     */
    private function applySideEffects(
        string $eventType,
        array $event,
        array $message,
        int $organisationId,
        string $email,
    ): void {
        $now       = $this->clock->nowString();
        $messageId = (int) $message['id'];

        $wasBound = $this->tenant->isBound();

        if (!$wasBound) {
            $organisation = $this->connection->table('organisations')->where('id', '=', $organisationId)->first();
            $this->tenant->bind($organisationId, null, $organisation ?? []);
        }

        try {
            switch ($eventType) {
                case 'delivery':
                    $this->updateMessage($messageId, ['status' => 'delivered', 'delivered_at' => $now]);
                    break;

                case 'open':
                    // Opens are recorded but never promoted to the primary signal:
                    // mail privacy features pre-fetch tracking pixels, so an "open"
                    // may mean nothing happened at all.
                    $this->connection->execute(
                        'UPDATE email_messages SET open_count = open_count + 1,
                            opened_at = COALESCE(opened_at, ?) WHERE id = ?',
                        [$now, $messageId]
                    );
                    break;

                case 'click':
                    $this->connection->execute(
                        'UPDATE email_messages SET click_count = click_count + 1,
                            clicked_at = COALESCE(clicked_at, ?) WHERE id = ?',
                        [$now, $messageId]
                    );
                    break;

                case 'bounce':
                    $this->handleBounce($event, $message, $messageId, $email, $now);
                    break;

                case 'complaint':
                    $this->updateMessage($messageId, ['status' => 'complained', 'complained_at' => $now]);

                    // A complaint suppresses immediately. There is no threshold:
                    // somebody pressed "this is spam".
                    $this->suppressions->suppressForComplaint(
                        $email,
                        (string) ($event['provider'] ?? 'ses'),
                        isset($message['campaign_id']) ? (int) $message['campaign_id'] : null,
                        $messageId,
                        (string) ($event['complaint_type'] ?? '')
                    );

                    if (($message['contact_id'] ?? null) !== null) {
                        $this->activity->record(
                            'email_complaint',
                            (int) $message['contact_id'],
                            'Reported a message as spam',
                            ['message_id' => $messageId]
                        );
                    }
                    break;

                case 'reject':
                case 'rendering_failure':
                    $this->updateMessage($messageId, [
                        'status'         => 'failed',
                        'failed_at'      => $now,
                        'failure_reason' => substr((string) ($event['reason'] ?? $eventType), 0, 255),
                    ]);
                    break;
            }
        } finally {
            if (!$wasBound) {
                $this->tenant->clear();
            }
        }
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $message
     */
    private function handleBounce(array $event, array $message, int $messageId, string $email, string $now): void
    {
        $bounceType = strtolower((string) ($event['bounce_type'] ?? 'undetermined'));

        if ($bounceType === 'permanent') {
            $this->updateMessage($messageId, ['status' => 'bounced', 'bounced_at' => $now]);

            $this->suppressions->suppressForHardBounce(
                $email,
                (string) ($event['provider'] ?? 'ses'),
                isset($message['campaign_id']) ? (int) $message['campaign_id'] : null,
                $messageId,
                trim((string) ($event['bounce_subtype'] ?? ''))
            );

            return;
        }

        // Transient / undetermined: record it, do NOT suppress. A full mailbox or
        // a temporary outage is not a reason to stop mailing someone for ever.
        $this->updateMessage($messageId, ['status' => 'soft_bounced', 'bounced_at' => $now]);

        $threshold = (int) $this->config->get('compliance.soft_bounce_threshold', 5);

        $softBounces = (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM email_events e
             INNER JOIN email_messages m ON m.id = e.email_message_id
             WHERE e.organisation_id = ? AND e.event_type = 'bounce'
               AND (e.bounce_type IS NULL OR LOWER(e.bounce_type) != 'permanent')
               AND m.email_normalized = ?",
            [(int) $message['organisation_id'], normalize_email($email)]
        );

        if ($softBounces >= $threshold) {
            // Repeated soft bounces eventually mean the address is dead.
            $this->suppressions->suppress($email, 'invalid', [
                'source'           => 'provider',
                'provider'         => (string) ($event['provider'] ?? 'ses'),
                'email_message_id' => $messageId,
                'detail'           => $softBounces . ' consecutive soft bounces',
            ]);
        }
    }

    /** @param array<string,mixed> $values */
    private function updateMessage(int $messageId, array $values): void
    {
        $this->connection->table('email_messages')->where('id', '=', $messageId)->update($values);
    }
}
