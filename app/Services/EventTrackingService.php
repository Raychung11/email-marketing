<?php

declare(strict_types=1);

namespace App\Services;

use App\Automation\TriggerDispatcher;
use App\Core\Clock;
use App\Core\Config;
use App\Database\Connection;
use App\Repositories\ContactRepository;
use App\Support\TenantContext;

/**
 * "Somebody looked at the boiler servicing page."
 *
 * Website events are what turn a mailing list into something that knows its
 * customers. They are also the part of a product like this most likely to
 * become a privacy problem, so two rules hold throughout:
 *
 *  NOTHING IS IDENTIFIED UNTIL SOMEBODY IDENTIFIES THEMSELVES. Anonymous
 *  browsing is stored against a random id the visitor's browser generated. It
 *  becomes a person only when they click a link in an email or fill in a form —
 *  a deliberate act by them, not a fingerprint we assembled.
 *
 *  THE SNIPPET CANNOT WRITE TO THE CRM. Events come in through a public key that
 *  can post events and read nothing. A key that sits in every page's HTML is a
 *  public key whatever we call it, so it is given the access that assumption
 *  deserves.
 */
final class EventTrackingService
{
    /** Events we understand. Anything else is stored under its own name but never acted on. */
    private const KNOWN = [
        'page_view', 'product_view', 'service_view', 'pricing_view',
        'form_start', 'form_submit', 'add_to_cart', 'checkout_start',
        'booking_start', 'booking_complete', 'quote_request', 'call_click',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContactRepository $contacts,
        private readonly TriggerDispatcher $triggers,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Record one event.
     *
     * @param array<string,mixed> $event
     * @return array{id:int,identified:bool}
     */
    public function record(array $event): array
    {
        $name = $this->cleanName((string) ($event['event_name'] ?? ''));

        if ($name === '') {
            return ['id' => 0, 'identified' => false];
        }

        $contactId = $this->resolveContact($event);
        $now       = $this->clock->nowString();

        $id = $this->connection->table('tracking_events')->insert([
            'organisation_id' => $this->tenant->organisationId(),
            'contact_id'      => $contactId,
            'anonymous_id'    => mb_substr((string) ($event['anonymous_id'] ?? ''), 0, 64) ?: null,
            'session_id'      => mb_substr((string) ($event['session_id'] ?? ''), 0, 64) ?: null,
            'event_name'      => $name,
            'page_url'        => $this->cleanUrl((string) ($event['page_url'] ?? '')),
            'referrer'        => $this->cleanUrl((string) ($event['referrer'] ?? '')),
            'properties'      => isset($event['properties']) && is_array($event['properties'])
                ? json_encode($this->cleanProperties($event['properties']), JSON_UNESCAPED_SLASHES)
                : null,
            'utm_source'      => mb_substr((string) ($event['utm_source'] ?? ''), 0, 80) ?: null,
            'utm_medium'      => mb_substr((string) ($event['utm_medium'] ?? ''), 0, 80) ?: null,
            'utm_campaign'    => mb_substr((string) ($event['utm_campaign'] ?? ''), 0, 120) ?: null,
            'ip_address'      => mb_substr((string) ($event['ip_address'] ?? ''), 0, 45) ?: null,
            'user_agent'      => mb_substr((string) ($event['user_agent'] ?? ''), 0, 255) ?: null,
            'occurred_at'     => $now,
            'created_at'      => $now,
        ]);

        if ($contactId !== null) {
            $this->contacts->touchEngagement($contactId, 'other');

            // Journeys listening for this event get their chance. Firing writes a
            // run row and returns, so a busy website never waits on an email.
            $this->triggers->fire('api_event', $contactId, [
                'event_name' => $name,
                'reference'  => (string) ($event['page_url'] ?? ''),
            ]);
        }

        return ['id' => $id, 'identified' => $contactId !== null];
    }

    /**
     * Tie a browser to a person.
     *
     * Called when somebody does something that identifies them — submits a form,
     * clicks a link in an email. Backfills the anonymous events from that browser
     * so the history is not lost, which is the whole reason for keeping an
     * anonymous id in the first place.
     */
    public function identify(string $anonymousId, int $contactId): int
    {
        $anonymousId = mb_substr(trim($anonymousId), 0, 64);

        if ($anonymousId === '' || $contactId <= 0) {
            return 0;
        }

        return $this->connection->execute(
            'UPDATE tracking_events SET contact_id = ?
             WHERE organisation_id = ? AND anonymous_id = ? AND contact_id IS NULL',
            [$contactId, $this->tenant->organisationId(), $anonymousId]
        );
    }

    /**
     * What one contact has been doing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function timelineFor(int $contactId, int $limit = 50): array
    {
        return $this->connection->select(
            'SELECT * FROM tracking_events WHERE organisation_id = ? AND contact_id = ?
             ORDER BY occurred_at DESC LIMIT ' . max(1, $limit),
            [$this->tenant->organisationId(), $contactId]
        );
    }

    /**
     * The busiest events over a period, for the dashboard.
     *
     * @return array<int,array<string,mixed>>
     */
    public function popular(int $days = 30, int $limit = 10): array
    {
        return $this->connection->select(
            'SELECT event_name, COUNT(*) AS total,
                    SUM(CASE WHEN contact_id IS NOT NULL THEN 1 ELSE 0 END) AS identified
             FROM tracking_events
             WHERE organisation_id = ? AND occurred_at >= ?
             GROUP BY event_name ORDER BY total DESC LIMIT ' . max(1, $limit),
            [$this->tenant->organisationId(), $this->clock->agoString($days)]
        );
    }

    /** The events this system knows how to reason about. */
    public function knownEvents(): array
    {
        return self::KNOWN;
    }

    // ------------------------------------------------------------ internals

    /**
     * Who, if anyone, this event belongs to.
     *
     * An explicit contact uuid wins; then a previously identified browser. There
     * is deliberately no IP or user-agent matching: guessing who somebody is from
     * their network is exactly the kind of thing this product should not do.
     *
     * @param array<string,mixed> $event
     */
    private function resolveContact(array $event): ?int
    {
        $uuid = trim((string) ($event['contact_uuid'] ?? ''));

        if ($uuid !== '') {
            $contact = $this->contacts->findByUuid($uuid);

            if ($contact !== null) {
                return (int) $contact['id'];
            }
        }

        $anonymousId = trim((string) ($event['anonymous_id'] ?? ''));

        if ($anonymousId === '') {
            return null;
        }

        $known = $this->connection->selectOne(
            'SELECT contact_id FROM tracking_events
             WHERE organisation_id = ? AND anonymous_id = ? AND contact_id IS NOT NULL
             ORDER BY id DESC LIMIT 1',
            [$this->tenant->organisationId(), mb_substr($anonymousId, 0, 64)]
        );

        return $known === null ? null : (int) $known['contact_id'];
    }

    private function cleanName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = (string) preg_replace('/[^a-z0-9_]/', '_', $name);

        return mb_substr(trim($name, '_'), 0, 60);
    }

    /**
     * Strip the query string off a URL before storing it.
     *
     * Query strings on real websites carry session tokens, password reset links
     * and email addresses. None of that belongs in an analytics table, and the
     * path is what the report needs anyway. UTM values are passed separately and
     * kept on purpose.
     */
    private function cleanUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host'])) {
            return mb_substr($url, 0, 500);
        }

        return mb_substr(
            ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . ($parts['path'] ?? ''),
            0,
            500
        );
    }

    /**
     * Properties are the site owner's own data, so they are kept — but bounded,
     * and flattened to scalars so a nested blob cannot become a storage problem.
     *
     * @param array<string,mixed> $properties
     * @return array<string,mixed>
     */
    private function cleanProperties(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (count($clean) >= 20) {
                break;
            }

            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $clean[mb_substr((string) $key, 0, 40)] = is_string($value) ? mb_substr($value, 0, 200) : $value;
        }

        return $clean;
    }
}
