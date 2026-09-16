<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Database\Connection;
use App\Repositories\EmailMessageRepository;
use App\Support\MessageStatus;
use App\Support\TenantContext;

/**
 * The outbox: every individual message this organisation has produced, with
 * what became of it.
 *
 * The analytics screens answer "how did that campaign do". This answers a
 * different and more urgent question — "did the email to *this customer*
 * actually go, and if not, why not" — which a rolled-up percentage can never
 * answer. When a business owner rings up asking why their biggest client never
 * got the quote, a support person needs the one row, not the funnel.
 *
 * Bodies are deliberately not stored (see EmailMessageRepository), so this is a
 * delivery log, not an archive of what was written.
 */
final class OutboxService
{
    public function __construct(
        private readonly EmailMessageRepository $messages,
        private readonly Connection $connection,
        private readonly TenantContext $tenant,
        private readonly Clock $clock,
    ) {
    }

    /**
     * One page of the log, with campaign names resolved.
     *
     * @param  array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $query = $this->messages->filtered($filters);
        $total = (clone $query)->count();
        $rows  = $query->forPage($page, $perPage)->get();

        $names = $this->campaignNames($rows);

        foreach ($rows as $index => $row) {
            $campaignId = (int) ($row['campaign_id'] ?? 0);

            $rows[$index]['campaign_name'] = $names[$campaignId] ?? null;
            $rows[$index]['status_label']  = MessageStatus::label((string) $row['status']);
            $rows[$index]['status_tone']   = MessageStatus::tone((string) $row['status']);
        }

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /**
     * The headline numbers, under the same filters as the list.
     *
     * "Sent" here means handed to the provider and accepted — which is the only
     * thing this application can claim on its own. "Arrived" is a stronger claim
     * and comes from the provider's delivery notification, so it is reported
     * separately and will always lag behind. Conflating the two is how tools end
     * up telling customers a message was delivered when it bounced.
     *
     * @param  array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function summary(array $filters = []): array
    {
        $counts = $this->messages->statusCounts($filters);

        $of = static fn (string ...$statuses): int => array_sum(
            array_map(static fn (string $s): int => $counts[$s] ?? 0, $statuses)
        );

        $total      = array_sum($counts);
        $handedOver = $of(...MessageStatus::HANDED_OVER);
        $arrived    = $of('delivered');
        $failed     = $of(...MessageStatus::NEVER_SENT);
        $bounced    = $of('bounced', 'soft_bounced');

        return [
            'total'         => $total,
            'sent'          => $handedOver,
            'arrived'       => $arrived,
            'bounced'       => $bounced,
            'complained'    => $of('complained'),
            'failed'        => $failed,
            'waiting'       => $of('queued', 'sending'),
            'awaiting_news' => max(0, $handedOver - $arrived - $bounced - $of('complained')),
            'arrival_rate'  => $handedOver > 0 ? round($arrived / $handedOver * 100, 1) : 0.0,
            'by_status'     => $counts,
        ];
    }

    /**
     * Campaigns that have actually sent something, for the filter dropdown.
     *
     * Reading these from email_messages rather than from the campaigns table
     * keeps the dropdown to campaigns with rows in the log — offering a filter
     * that can only ever return nothing is worse than not offering it.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function campaignOptions(int $limit = 50): array
    {
        $rows = $this->connection->select(
            'SELECT c.id, c.name FROM campaigns c
             WHERE c.organisation_id = ? AND c.deleted_at IS NULL
               AND EXISTS (SELECT 1 FROM email_messages m
                           WHERE m.campaign_id = c.id AND m.organisation_id = c.organisation_id)
             ORDER BY c.id DESC
             LIMIT ' . max(1, $limit),
            [$this->tenant->organisationId()]
        );

        return array_map(static fn (array $row): array => [
            'id'   => (int) $row['id'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    /**
     * The same totals, for every organisation on this server.
     *
     * This is the one method here that crosses tenants, and it exists for the
     * console command only — an operator at an SSH prompt has no session and so
     * no bound organisation. It is never routed, and the grouping is by
     * organisation precisely so the numbers cannot be read as one pooled figure.
     *
     * @return array<string,array<string,int>>
     */
    public function totalsByOrganisation(int $days = 30): array
    {
        $rows = $this->connection->select(
            'SELECT o.name AS org, m.status, COUNT(*) AS total
             FROM email_messages m
             JOIN organisations o ON o.id = m.organisation_id
             WHERE m.created_at >= ?
             GROUP BY o.name, m.status
             ORDER BY o.name',
            [$this->clock->agoString(max(1, $days))]
        );

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row['org']][(string) $row['status']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    private function campaignNames(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ((int) ($row['campaign_id'] ?? 0) > 0) {
                $ids[(int) $row['campaign_id']] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $ids         = array_keys($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $found = $this->connection->select(
            "SELECT id, name FROM campaigns WHERE organisation_id = ? AND id IN ({$placeholders})",
            array_merge([$this->tenant->organisationId()], $ids)
        );

        $names = [];

        foreach ($found as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }
}
