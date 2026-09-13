<?php

declare(strict_types=1);

namespace App\AI;

use App\Services\AnalyticsService;
use App\Services\AttributionService;
use App\Services\LeadService;
use App\Services\SegmentService;
use App\Support\TenantContext;
use App\Database\Connection;

/**
 * Everything the assistant is allowed to look at.
 *
 * This class is the assistant's entire world. It has no general query path, no
 * way to name a table, and no write of any kind — every method here answers one
 * fixed question with a number the rest of the product already computes, scoped
 * to the bound tenant by the services underneath.
 *
 * That is the design, not a limitation to be lifted later. An assistant with a
 * general query tool is an assistant that can be talked into reading anything,
 * and the pressure to add "just one" write tool never stops at one. So:
 *
 *  - Every tool is READ ONLY. There is no write tool to argue about.
 *  - Every tool takes at most a small integer, never a field name, a table, a
 *    filter or a fragment of anything.
 *  - The tenant comes from TenantContext, never from an argument, so there is
 *    no parameter a model could get wrong.
 *
 * When the assistant concludes that something should be done, it says so and
 * links to the screen where a person does it. That is the whole interaction
 * model, and it is why this is safe to ship.
 */
final class AssistantTools
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly AttributionService $attribution,
        private readonly LeadService $leads,
        private readonly SegmentService $segments,
        private readonly Connection $connection,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * What the model is told it can ask for.
     *
     * Descriptions are written for the model, but they are also the honest list
     * of what the assistant can see — worth reading before trusting it with a
     * question.
     *
     * @return array<string,array{description:string,argument:?string}>
     */
    public function catalogue(): array
    {
        return [
            'contact_counts' => [
                'description' => 'How many contacts there are, how many can be emailed, and why the '
                    . 'rest cannot.',
                'argument'    => null,
            ],
            'email_performance' => [
                'description' => 'Sent, arrived, clicked, bounced and spam figures over a number of days.',
                'argument'    => 'days',
            ],
            'campaign_list' => [
                'description' => 'Recent campaigns with their click rates.',
                'argument'    => 'days',
            ],
            'inbox_health' => [
                'description' => 'Whether email is reaching inboxes, broken down by email provider.',
                'argument'    => 'days',
            ],
            'revenue' => [
                'description' => 'Sales, and how much of it can be traced back to an email.',
                'argument'    => 'days',
            ],
            'enquiries' => [
                'description' => 'Enquiry numbers, win rate, and how many are still unanswered.',
                'argument'    => 'days',
            ],
            'smart_lists' => [
                'description' => 'The saved smart lists and how many people are in each.',
                'argument'    => null,
            ],
        ];
    }

    /**
     * Run one tool.
     *
     * The argument is coerced to a bounded integer whatever arrives, so there is
     * no input shape a model can produce that reaches anything underneath.
     *
     * @return array<string,mixed>
     */
    public function call(string $tool, mixed $argument = null): array
    {
        $days = $this->days($argument);

        return match ($tool) {
            'contact_counts'    => $this->contactCounts(),
            'email_performance' => $this->analytics->inboxDelivery($days)['totals'],
            'campaign_list'     => $this->campaignList($days),
            'inbox_health'      => $this->inboxHealth($days),
            'revenue'           => $this->attribution->summary($days),
            'enquiries'         => $this->leads->stats($days),
            'smart_lists'       => $this->smartLists(),
            default             => ['error' => 'There is no such tool.'],
        };
    }

    // ------------------------------------------------------------- the tools

    /** @return array<string,mixed> */
    private function contactCounts(): array
    {
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN marketing_consent_cache = 1 AND is_suppressed_cache = 0 THEN 1 ELSE 0 END) AS can_email,
                    SUM(CASE WHEN is_suppressed_cache = 1 THEN 1 ELSE 0 END) AS do_not_email,
                    SUM(CASE WHEN marketing_consent_cache = 0 AND is_suppressed_cache = 0 THEN 1 ELSE 0 END) AS never_agreed,
                    SUM(CASE WHEN purchase_count > 0 THEN 1 ELSE 0 END) AS customers
             FROM contacts WHERE organisation_id = ? AND deleted_at IS NULL',
            [$this->tenant->organisationId()]
        ) ?? [];

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'can_email'    => (int) ($row['can_email'] ?? 0),
            'do_not_email' => (int) ($row['do_not_email'] ?? 0),
            'never_agreed' => (int) ($row['never_agreed'] ?? 0),
            'customers'    => (int) ($row['customers'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function campaignList(int $days): array
    {
        $rows = [];

        foreach (array_slice($this->analytics->campaignPerformance($days), 0, 10) as $campaign) {
            $rows[] = [
                'name'       => $campaign['name'],
                'sent'       => $campaign['sent'],
                'click_rate' => $campaign['click_rate'],
                'bounced'    => $campaign['bounced'],
                'complained' => $campaign['complained'],
            ];
        }

        return ['campaigns' => $rows, 'days' => $days];
    }

    /** @return array<string,mixed> */
    private function inboxHealth(int $days): array
    {
        $report = $this->analytics->inboxDelivery($days);

        return [
            'verdict'   => $report['verdict']['headline'],
            'totals'    => $report['totals'],
            'providers' => array_map(static fn (array $p): array => [
                'provider'      => $p['provider'],
                'sent'          => $p['sent'],
                'delivery_rate' => $p['delivery_rate'],
            ], $report['providers']),
        ];
    }

    /** @return array<string,mixed> */
    private function smartLists(): array
    {
        $lists = [];

        foreach ($this->segments->all() as $segment) {
            $lists[] = [
                'name'      => (string) $segment['name'],
                'people'    => (int) ($segment['cached_count'] ?? 0),
                'can_email' => (int) ($segment['cached_eligible_count'] ?? 0),
            ];
        }

        return ['smart_lists' => $lists];
    }

    /**
     * Whatever arrived, as a sensible number of days.
     *
     * A model asking for a million days would be asking the database to scan
     * everything; a model sending a string gets 30.
     */
    private function days(mixed $argument): int
    {
        $days = is_numeric($argument) ? (int) $argument : 30;

        return max(1, min(365, $days));
    }
}
