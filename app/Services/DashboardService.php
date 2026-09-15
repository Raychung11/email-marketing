<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Database\Connection;
use App\Repositories\ContactRepository;
use App\Support\TenantContext;

/**
 * Dashboard metrics.
 *
 * The headline numbers are deliberately business outcomes — leads, customers,
 * reactivation, attributed revenue — rather than open rate. Open rate appears,
 * but labelled and de-emphasised, because privacy proxies in modern mail clients
 * make it an unreliable signal and building a business on it misleads the user.
 */
final class DashboardService
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly ConsentService $consent,
        private readonly SuppressionService $suppression,
        private readonly OnboardingService $onboarding,
        private readonly Connection $connection,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
    ) {
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        $organisationId = $this->tenant->organisationId();
        $totals         = $this->contacts->dashboardTotals();

        $thirtyDaysAgo = $this->clock->agoString(30);

        return [
            'totals' => [
                'contacts'         => (int) ($totals['total_contacts'] ?? 0),
                'customers'        => (int) ($totals['customers'] ?? 0),
                'leads'            => (int) ($totals['leads'] ?? 0),
                'marketable'       => (int) ($totals['marketable'] ?? 0),
                'suppressed'       => (int) ($totals['suppressed'] ?? 0),
                'lifetime_revenue' => (float) ($totals['lifetime_revenue'] ?? 0),
                'new_contacts_30d' => $this->contacts->createdSince($thirtyDaysAgo),
            ],
            'email' => $this->emailMetrics($organisationId, $thirtyDaysAgo),
            'revenue' => $this->revenueMetrics($organisationId, $thirtyDaysAgo),
            'leads' => $this->leadMetrics($organisationId),
            'consent'     => $this->consent->statusBreakdown('email'),
            'suppression' => $this->suppression->reasonCounts(),
            'growth'      => $this->contacts->growthByMonth(12),
            'checklist'   => $this->onboarding->checklist(),
            'currency'    => $this->tenant->currency(),
            'timezone'    => $this->tenant->timezone(),
        ];
    }

    /** @return array<string,mixed> */
    private function emailMetrics(int $organisationId, string $since): array
    {
        $row = $this->connection->selectOne(
            "SELECT
                SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) AS sent,
                -- An open or a click proves the message arrived, even when the
                -- provider's delivery notification never reached us. ('opened'
                -- and 'clicked' are not message statuses.)
                SUM(CASE WHEN status = 'delivered' OR opened_at IS NOT NULL OR clicked_at IS NOT NULL
                         THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                SUM(CASE WHEN status = 'complained' THEN 1 ELSE 0 END) AS complained
             FROM email_messages
             WHERE organisation_id = ? AND created_at >= ? AND message_class = 'marketing'",
            [$organisationId, $since]
        ) ?? [];

        $sent      = (int) ($row['sent'] ?? 0);
        $delivered = (int) ($row['delivered'] ?? 0);

        return [
            'sent'            => $sent,
            'delivered'       => $delivered,
            'delivery_rate'   => $sent > 0 ? round($delivered / $sent * 100, 1) : 0.0,
            'opened'          => (int) ($row['opened'] ?? 0),
            'clicked'         => (int) ($row['clicked'] ?? 0),
            // Click rate is the honest engagement signal; open rate is reported
            // alongside it with a caveat in the UI.
            'click_rate'      => $delivered > 0 ? round(((int) ($row['clicked'] ?? 0)) / $delivered * 100, 2) : 0.0,
            'open_rate'       => $delivered > 0 ? round(((int) ($row['opened'] ?? 0)) / $delivered * 100, 1) : 0.0,
            'bounced'         => (int) ($row['bounced'] ?? 0),
            'bounce_rate'     => $sent > 0 ? round(((int) ($row['bounced'] ?? 0)) / $sent * 100, 2) : 0.0,
            'complained'      => (int) ($row['complained'] ?? 0),
            'complaint_rate'  => $sent > 0 ? round(((int) ($row['complained'] ?? 0)) / $sent * 100, 3) : 0.0,
        ];
    }

    /** @return array<string,mixed> */
    private function revenueMetrics(int $organisationId, string $since): array
    {
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS conversions,
                    COALESCE(SUM(value), 0) AS total_value,
                    COALESCE(SUM(CASE WHEN attributed_campaign_id IS NOT NULL THEN value ELSE 0 END), 0) AS attributed_value,
                    SUM(CASE WHEN attributed_campaign_id IS NOT NULL THEN 1 ELSE 0 END) AS attributed_conversions
             FROM conversions
             WHERE organisation_id = ? AND occurred_at >= ?',
            [$organisationId, $since]
        ) ?? [];

        return [
            'conversions'            => (int) ($row['conversions'] ?? 0),
            'total_value'            => (float) ($row['total_value'] ?? 0),
            'attributed_value'       => (float) ($row['attributed_value'] ?? 0),
            'attributed_conversions' => (int) ($row['attributed_conversions'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function leadMetrics(int $organisationId): array
    {
        $row = $this->connection->selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_leads,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won,
                SUM(CASE WHEN status = 'open' AND first_response_at IS NULL THEN 1 ELSE 0 END) AS awaiting_first_response
             FROM leads WHERE organisation_id = ? AND deleted_at IS NULL",
            [$organisationId]
        ) ?? [];

        $overdue = (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM leads
             WHERE organisation_id = ? AND status = 'open' AND next_followup_at IS NOT NULL
               AND next_followup_at < ? AND deleted_at IS NULL",
            [$organisationId, $this->clock->nowString()]
        );

        $total = (int) ($row['total'] ?? 0);
        $won   = (int) ($row['won'] ?? 0);

        return [
            'total'                   => $total,
            'open'                    => (int) ($row['open_leads'] ?? 0),
            'won'                     => $won,
            'conversion_rate'         => $total > 0 ? round($won / $total * 100, 1) : 0.0,
            'awaiting_first_response' => (int) ($row['awaiting_first_response'] ?? 0),
            'overdue_followups'       => $overdue,
        ];
    }

    /**
     * Actionable recommendations derived from observed data only.
     *
     * These are calculated, not generated: each one is a real query with a real
     * count, labelled 'calculated' so it is never confused with AI output. The AI
     * recommendation engine (phase 3) adds to this list; it does not replace it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recommendations(): array
    {
        $organisationId = $this->tenant->organisationId();
        $recommendations = [];

        $inactive = (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM contacts
             WHERE organisation_id = ? AND deleted_at IS NULL
               AND customer_status IN ('customer','repeat_customer','vip')
               AND (last_purchase_at IS NULL OR last_purchase_at < ?)
               AND marketing_consent_cache = 1 AND is_suppressed_cache = 0",
            [$organisationId, $this->clock->agoString(180)]
        );

        if ($inactive > 0) {
            $recommendations[] = [
                'type'         => 'reactivation_opportunity',
                'title'        => number_format($inactive) . ' customers have not purchased in 180 days',
                'body'         => 'They are eligible for marketing email. A reactivation campaign is the highest-value '
                    . 'thing you can send to this group.',
                'impact'       => $inactive > 100 ? 'high' : 'medium',
                'data_basis'   => 'calculated',
                'action_label' => 'Build reactivation segment',
                'action_url'   => '/segments/create?template=inactive_180',
                'metric'       => $inactive,
            ];
        }

        $noConsent = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM contacts
             WHERE organisation_id = ? AND deleted_at IS NULL
               AND marketing_consent_cache = 0 AND is_suppressed_cache = 0',
            [$organisationId]
        );

        if ($noConsent > 0) {
            $recommendations[] = [
                'type'         => 'consent_gap',
                'title'        => number_format($noConsent) . ' contacts cannot be sent marketing email',
                'body'         => 'Consent has not been established for these contacts, so they are excluded from '
                    . 'marketing campaigns. A re-permission campaign or an opt-in form is the way to recover them.',
                'impact'       => $noConsent > 500 ? 'high' : 'medium',
                'data_basis'   => 'observed',
                'action_label' => 'Review consent',
                'action_url'   => '/contacts?consent=no',
                'metric'       => $noConsent,
            ];
        }

        $staleLeads = (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM leads
             WHERE organisation_id = ? AND status = 'open' AND first_response_at IS NULL
               AND created_at < ? AND deleted_at IS NULL",
            [$organisationId, $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s')]
        );

        if ($staleLeads > 0) {
            $recommendations[] = [
                'type'         => 'lead_recovery',
                'title'        => $staleLeads . ' leads have had no follow-up within 24 hours',
                'body'         => 'Enquiries that go unanswered for a day rarely convert. A lead recovery automation '
                    . 'sends the follow-up for you.',
                'impact'       => 'high',
                'data_basis'   => 'observed',
                'action_label' => 'Set up lead recovery',
                'action_url'   => '/leads/recovery',
                'metric'       => $staleLeads,
            ];
        }

        foreach ($this->onboarding->checklist() as $item) {
            if (!$item['done'] && $item['critical']) {
                $recommendations[] = [
                    'type'         => 'setup_required',
                    'title'        => $item['label'],
                    'body'         => 'This is required before marketing email can be sent.',
                    'impact'       => 'high',
                    'data_basis'   => 'observed',
                    'action_label' => 'Complete setup',
                    'action_url'   => $item['url'],
                    'metric'       => null,
                ];
            }
        }

        return $recommendations;
    }
}
