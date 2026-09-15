<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Database\Connection;
use App\Support\TenantContext;

/**
 * Which email, if any, led to this sale?
 *
 * Attribution is a decision, not a fact, and the honest way to handle a decision
 * is to record it at the moment it is made and stand by it. So the attributed
 * campaign is written onto the conversion row, together with the model and the
 * window that produced it. Change the window next month and last month's figures
 * do not silently rewrite themselves — which they would if every report
 * recomputed attribution on the fly, and which is how a business ends up
 * unable to reconcile two printouts of the same quarter.
 *
 * The default model is last click within a configurable window. Not because it
 * is the most sophisticated — it is the least — but because it is the one a
 * plumber can check by hand: "they pressed the link in the Tuesday email, then
 * booked on Thursday". A model nobody can verify is a model nobody should
 * believe.
 *
 * A click beats an open, always. An open may mean a mail server fetched an
 * image; a click means a person did something.
 */
final class AttributionService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Record a conversion and decide what it should be credited to.
     *
     * Idempotent on (organisation, external_id, type): a payment gateway that
     * retries its webhook must not double the day's revenue.
     *
     * @param array<string,mixed> $attributes
     * @return array{id:int,duplicate:bool,attribution:array<string,mixed>}
     */
    public function record(array $attributes): array
    {
        $organisation = $this->tenant->organisation();

        $contactId  = (int) ($attributes['contact_id'] ?? 0) ?: null;
        $occurredAt = $this->normaliseTime((string) ($attributes['occurred_at'] ?? ''));
        $externalId = trim((string) ($attributes['external_id'] ?? '')) ?: null;
        $type       = $this->conversionType((string) ($attributes['conversion_type'] ?? 'purchase'));

        if ($externalId !== null) {
            $existing = $this->connection->table('conversions')
                ->where('organisation_id', '=', $this->tenant->organisationId())
                ->where('external_id', '=', $externalId)
                ->where('conversion_type', '=', $type)
                ->first();

            if ($existing !== null) {
                return [
                    'id'          => (int) $existing['id'],
                    'duplicate'   => true,
                    'attribution' => $this->describe($existing),
                ];
            }
        }

        $attribution = $contactId === null
            ? $this->none()
            : $this->attribute($contactId, $occurredAt);

        $id = $this->connection->table('conversions')->insert([
            'organisation_id'             => $this->tenant->organisationId(),
            'uuid'                        => uuid4(),
            'contact_id'                  => $contactId,
            'conversion_type'             => $type,
            'event_name'                  => mb_substr((string) ($attributes['event_name'] ?? ''), 0, 60) ?: null,
            'value'                       => round((float) ($attributes['value'] ?? 0), 2),
            'currency'                    => strtoupper(mb_substr(
                (string) ($attributes['currency'] ?? ($organisation['currency'] ?? 'USD')),
                0,
                3
            )),
            'external_id'                 => $externalId,
            'attributed_campaign_id'      => $attribution['campaign_id'],
            'attributed_email_message_id' => $attribution['message_id'],
            'attributed_automation_id'    => $attribution['automation_id'],
            'attribution_model'           => $attribution['model'],
            'attribution_window_days'     => $attribution['window_days'],
            'attribution_touch_at'        => $attribution['touched_at'],
            'utm_source'                  => mb_substr((string) ($attributes['utm_source'] ?? ''), 0, 80) ?: null,
            'utm_medium'                  => mb_substr((string) ($attributes['utm_medium'] ?? ''), 0, 80) ?: null,
            'utm_campaign'                => mb_substr((string) ($attributes['utm_campaign'] ?? ''), 0, 120) ?: null,
            'session_id'                  => mb_substr((string) ($attributes['session_id'] ?? ''), 0, 64) ?: null,
            'lead_id'                     => (int) ($attributes['lead_id'] ?? 0) ?: null,
            'properties'                  => isset($attributes['properties']) && is_array($attributes['properties'])
                ? json_encode($attributes['properties'], JSON_UNESCAPED_SLASHES)
                : null,
            'source'                      => in_array($attributes['source'] ?? '', ['api', 'js', 'manual', 'integration'], true)
                ? (string) $attributes['source'] : 'api',
            'occurred_at'                 => $occurredAt,
            'created_at'                  => $this->clock->nowString(),
        ]);

        if ($contactId !== null) {
            $this->updateContactTotals($contactId, (float) ($attributes['value'] ?? 0), $occurredAt);
        }

        return ['id' => $id, 'duplicate' => false, 'attribution' => $attribution];
    }

    /**
     * The attribution decision for one contact at one moment.
     *
     * @return array<string,mixed>
     */
    public function attribute(int $contactId, string $occurredAt): array
    {
        $organisation = $this->tenant->organisation();

        $model  = (string) ($organisation['attribution_model'] ?? 'last_click');
        $window = max(1, (int) ($organisation['attribution_window_days'] ?? 30));

        $since = (new \DateTimeImmutable($occurredAt))
            ->modify('-' . $window . ' days')
            ->format('Y-m-d H:i:s');

        // Clicks only, and only ones that happened before the sale. A click after
        // the fact did not cause anything.
        $direction = $model === 'first_click' ? 'ASC' : 'DESC';

        $touch = $this->connection->selectOne(
            "SELECT m.campaign_id, m.automation_id, m.id AS message_id, m.clicked_at
             FROM email_messages m
             WHERE m.organisation_id = ? AND m.contact_id = ?
               AND m.clicked_at IS NOT NULL AND m.clicked_at <= ? AND m.clicked_at >= ?
             ORDER BY m.clicked_at " . $direction . "
             LIMIT 1",
            [$this->tenant->organisationId(), $contactId, $occurredAt, $since]
        );

        if ($touch === null) {
            return $this->none($window);
        }

        return [
            'campaign_id'   => isset($touch['campaign_id']) ? (int) $touch['campaign_id'] : null,
            'automation_id' => isset($touch['automation_id']) ? (int) $touch['automation_id'] : null,
            'message_id'    => (int) $touch['message_id'],
            'model'         => in_array($model, ['last_click', 'first_click', 'influenced'], true) ? $model : 'last_click',
            'window_days'   => $window,
            'touched_at'    => (string) $touch['clicked_at'],
        ];
    }

    // ------------------------------------------------------------- reporting

    /**
     * What email was worth over a period.
     *
     * Reported alongside the total, never instead of it: "£8,400 of £31,000"
     * is an honest claim, and "email generated £8,400" on its own invites the
     * reader to think email did all the work.
     *
     * @return array<string,mixed>
     */
    public function summary(int $days = 90): array
    {
        $since = $this->clock->agoString($days);

        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS conversions,
                    COALESCE(SUM(value), 0) AS total_value,
                    SUM(CASE WHEN attributed_campaign_id IS NOT NULL OR attributed_automation_id IS NOT NULL
                             THEN 1 ELSE 0 END) AS attributed_conversions,
                    COALESCE(SUM(CASE WHEN attributed_campaign_id IS NOT NULL OR attributed_automation_id IS NOT NULL
                                      THEN value ELSE 0 END), 0) AS attributed_value
             FROM conversions WHERE organisation_id = ? AND occurred_at >= ?',
            [$this->tenant->organisationId(), $since]
        ) ?? [];

        $total      = (float) ($row['total_value'] ?? 0);
        $attributed = (float) ($row['attributed_value'] ?? 0);

        return [
            'days'                   => $days,
            'conversions'            => (int) ($row['conversions'] ?? 0),
            'total_value'            => $total,
            'attributed_value'       => $attributed,
            'attributed_conversions' => (int) ($row['attributed_conversions'] ?? 0),
            'attributed_share'       => $total > 0.0 ? round($attributed / $total * 100, 1) : 0.0,
            'currency'               => $this->tenant->currency(),
            'model'                  => (string) ($this->tenant->organisation()['attribution_model'] ?? 'last_click'),
            'window_days'            => (int) ($this->tenant->organisation()['attribution_window_days'] ?? 30),
        ];
    }

    /**
     * Which campaigns earned their keep.
     *
     * @return array<int,array<string,mixed>>
     */
    public function byCampaign(int $days = 90, int $limit = 20): array
    {
        return $this->connection->select(
            'SELECT c.id, c.name, COUNT(v.id) AS conversions, COALESCE(SUM(v.value), 0) AS value
             FROM conversions v
             INNER JOIN campaigns c ON c.id = v.attributed_campaign_id AND c.organisation_id = v.organisation_id
             WHERE v.organisation_id = ? AND v.occurred_at >= ?
             GROUP BY c.id, c.name
             ORDER BY value DESC
             LIMIT ' . max(1, $limit),
            [$this->tenant->organisationId(), $this->clock->agoString($days)]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function monthly(int $months = 12): array
    {
        $since = $this->clock->now()->modify('-' . max(1, $months) . ' months')->format('Y-m-d H:i:s');

        return $this->connection->select(
            "SELECT SUBSTR(occurred_at, 1, 7) AS period,
                    COUNT(*) AS conversions,
                    COALESCE(SUM(value), 0) AS total_value,
                    COALESCE(SUM(CASE WHEN attributed_campaign_id IS NOT NULL OR attributed_automation_id IS NOT NULL
                                      THEN value ELSE 0 END), 0) AS attributed_value
             FROM conversions
             WHERE organisation_id = ? AND occurred_at >= ?
             GROUP BY SUBSTR(occurred_at, 1, 7)
             ORDER BY period",
            [$this->tenant->organisationId(), $since]
        );
    }

    // ------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function none(?int $window = null): array
    {
        return [
            'campaign_id'   => null,
            'automation_id' => null,
            'message_id'    => null,
            'model'         => 'none',
            'window_days'   => $window,
            'touched_at'    => null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function describe(array $row): array
    {
        return [
            'campaign_id'   => isset($row['attributed_campaign_id']) ? (int) $row['attributed_campaign_id'] : null,
            'automation_id' => isset($row['attributed_automation_id']) ? (int) $row['attributed_automation_id'] : null,
            'message_id'    => isset($row['attributed_email_message_id']) ? (int) $row['attributed_email_message_id'] : null,
            'model'         => (string) $row['attribution_model'],
            'window_days'   => isset($row['attribution_window_days']) ? (int) $row['attribution_window_days'] : null,
            'touched_at'    => $row['attribution_touch_at'] ?? null,
        ];
    }

    private function updateContactTotals(int $contactId, float $value, string $occurredAt): void
    {
        $this->connection->execute(
            'UPDATE contacts
             SET total_revenue = COALESCE(total_revenue, 0) + ?,
                 purchase_count = COALESCE(purchase_count, 0) + 1,
                 first_purchase_at = COALESCE(first_purchase_at, ?),
                 last_purchase_at = ?,
                 updated_at = ?
             WHERE id = ? AND organisation_id = ?',
            [
                round($value, 2), $occurredAt, $occurredAt, $this->clock->nowString(),
                $contactId, $this->tenant->organisationId(),
            ]
        );
    }

    private function conversionType(string $type): string
    {
        $allowed = ['lead', 'booking', 'purchase', 'quote_request', 'appointment', 'form_submission', 'custom'];

        return in_array($type, $allowed, true) ? $type : 'purchase';
    }

    private function normaliseTime(string $value): string
    {
        if ($value === '') {
            return $this->clock->nowString();
        }

        try {
            $when = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return $this->clock->nowString();
        }

        // A conversion dated next year would sit outside every report and quietly
        // never be counted, so the future is clamped to now.
        $utc = $when->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return $utc > $this->clock->nowString() ? $this->clock->nowString() : $utc;
    }
}
