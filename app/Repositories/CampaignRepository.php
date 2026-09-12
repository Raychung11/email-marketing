<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\QueryBuilder;

final class CampaignRepository extends Repository
{
    protected function table(): string
    {
        return 'campaigns';
    }

    protected function softDeletes(): bool
    {
        return true;
    }

    /** @param array{status?:string,type?:string,search?:string} $filters */
    public function filtered(array $filters = []): QueryBuilder
    {
        $query = $this->scoped();

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', '=', (string) $filters['status']);
        }

        if (($filters['type'] ?? '') !== '') {
            $query->where('campaign_type', '=', (string) $filters['type']);
        }

        if (($filters['search'] ?? '') !== '') {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';

            $query->whereGroup(static function (QueryBuilder $q) use ($term): void {
                $q->where('name', 'like', $term)->orWhere('subject', 'like', $term);
            });
        }

        return $query->orderBy('created_at', 'desc');
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $attributes['uuid'] = $attributes['uuid'] ?? uuid4();

        foreach (['validation_findings'] as $jsonColumn) {
            if (isset($attributes[$jsonColumn]) && is_array($attributes[$jsonColumn])) {
                $attributes[$jsonColumn] = $this->encodeJson($attributes[$jsonColumn]);
            }
        }

        return $this->scoped()->insert($this->withTimestamps($this->withTenant($attributes)));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        foreach (['validation_findings'] as $jsonColumn) {
            if (isset($attributes[$jsonColumn]) && is_array($attributes[$jsonColumn])) {
                $attributes[$jsonColumn] = $this->encodeJson($attributes[$jsonColumn]);
            }
        }

        return $this->scoped()->where('id', '=', $id)->update($this->withTimestamps($attributes, false));
    }

    public function softDelete(int $id): int
    {
        return $this->scoped()->where('id', '=', $id)->update(['deleted_at' => $this->now()]);
    }

    /** @return array<string,mixed> */
    public function findOrFailDecoded(int $id): array
    {
        $row                        = $this->findOrFail($id);
        $row['validation_findings'] = $this->decodeJson($row['validation_findings'] ?? null) ?? [];

        return $row;
    }

    /**
     * A status transition that only applies if the campaign is still in the state
     * we think it is.
     *
     * This is the concurrency guard for the whole workflow: two people pressing
     * Approve, or the scheduler racing a manual send, cannot both win. The caller
     * checks the return value — 0 means somebody else moved it first.
     *
     * @param array<int,string>   $from
     * @param array<string,mixed> $attributes
     */
    public function transition(int $id, array $from, string $to, array $attributes = []): int
    {
        return $this->scoped()
            ->where('id', '=', $id)
            ->whereIn('status', $from)
            ->update($this->withTimestamps(array_merge($attributes, ['status' => $to]), false));
    }

    /**
     * Campaigns whose scheduled time has arrived.
     *
     * Crosses tenants deliberately — this is the scheduler's query — so the caller
     * binds each organisation before acting.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueForSending(string $now, int $limit = 25): array
    {
        return $this->connection->select(
            "SELECT * FROM campaigns
             WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ?
               AND deleted_at IS NULL
             ORDER BY scheduled_at
             LIMIT " . max(1, $limit),
            [$now]
        );
    }

    /**
     * Campaigns that are mid-send, so the scheduler can notice one that has
     * finished and close it out.
     *
     * @return array<int,array<string,mixed>>
     */
    public function inFlight(int $limit = 50): array
    {
        return $this->connection->select(
            "SELECT * FROM campaigns WHERE status = 'sending' AND deleted_at IS NULL
             ORDER BY send_started_at LIMIT " . max(1, $limit)
        );
    }

    /**
     * Has a recipient snapshot actually been built?
     *
     * Deliberately asks campaign_recipients rather than trusting
     * `recipient_count`, which validation also writes as the current audience
     * size. Those are two different facts and conflating them made a paused
     * campaign resume into "sending" with nothing to send.
     */
    public function hasSnapshot(int $campaignId): bool
    {
        return $this->connection->table('campaign_recipients')
            ->where('organisation_id', '=', $this->organisationId())
            ->where('campaign_id', '=', $campaignId)
            ->exists();
    }

    /** @return array<string,int> */
    public function statusCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT status, COUNT(*) AS total FROM campaigns
             WHERE organisation_id = ? AND deleted_at IS NULL GROUP BY status',
            [$this->organisationId()]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Rebuild the engagement counters from email_messages.
     *
     * The counters on campaigns are a rollup for reporting; email_messages and
     * email_events are authoritative. Recomputing rather than incrementing means a
     * missed or replayed webhook cannot leave the numbers permanently skewed.
     */
    public function refreshCounters(int $id): void
    {
        $row = $this->connection->selectOne(
            "SELECT
                COUNT(*) AS sent,
                SUM(CASE WHEN status IN ('delivered','opened','clicked') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS unique_opens,
                COALESCE(SUM(open_count), 0) AS opens,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS unique_clicks,
                COALESCE(SUM(click_count), 0) AS clicks,
                SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                SUM(CASE WHEN status = 'soft_bounced' THEN 1 ELSE 0 END) AS soft_bounced,
                SUM(CASE WHEN status = 'complained' THEN 1 ELSE 0 END) AS complained
             FROM email_messages
             WHERE organisation_id = ? AND campaign_id = ?",
            [$this->organisationId(), $id]
        ) ?? [];

        $this->scoped()->where('id', '=', $id)->update([
            'sent_count'          => (int) ($row['sent'] ?? 0),
            'delivered_count'     => (int) ($row['delivered'] ?? 0),
            'open_count'          => (int) ($row['opens'] ?? 0),
            'unique_open_count'   => (int) ($row['unique_opens'] ?? 0),
            'click_count'         => (int) ($row['clicks'] ?? 0),
            'unique_click_count'  => (int) ($row['unique_clicks'] ?? 0),
            'bounce_count'        => (int) ($row['bounced'] ?? 0),
            'soft_bounce_count'   => (int) ($row['soft_bounced'] ?? 0),
            'complaint_count'     => (int) ($row['complained'] ?? 0),
        ]);
    }
}
