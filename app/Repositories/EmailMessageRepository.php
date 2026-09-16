<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * One row per message handed to a provider.
 *
 * Stores addressing and status, never the rendered body: this table would
 * otherwise become a permanent copy of every marketing email ever sent to every
 * customer, which is a liability with no operational value.
 */
final class EmailMessageRepository extends Repository
{
    protected function table(): string
    {
        return 'email_messages';
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $attributes['uuid']       = $attributes['uuid'] ?? uuid4();
        $attributes['created_at'] = $attributes['created_at'] ?? $this->now();

        if (isset($attributes['email'])) {
            $attributes['email_normalized'] = normalize_email((string) $attributes['email']);
        }

        return $this->scoped()->insert($this->withTenant($attributes));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        return $this->scoped()->where('id', '=', $id)->update($attributes);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->scoped()->where('uuid', '=', $uuid)->first();
    }

    /**
     * How many marketing messages this organisation has handed to a provider
     * today, in its own timezone.
     *
     * The daily limit is a calendar-day limit as the customer understands it, not
     * a rolling 24 hours, so the window is computed in their timezone.
     */
    public function sentToday(string $timezone): int
    {
        $start = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))
            ->setTime(0, 0)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        return (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM email_messages
             WHERE organisation_id = ? AND message_class = 'marketing' AND created_at >= ?",
            [$this->organisationId(), $start]
        );
    }

    /**
     * The outbox query: one row per message, newest first.
     *
     * The filters map onto the two indexes this table already carries —
     * (organisation_id, status, created_at) and (organisation_id,
     * email_normalized) — so a busy account can still page through it.
     *
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters = []): \App\Database\QueryBuilder
    {
        return $this->matching($filters)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');
    }

    /**
     * How many messages sit in each status, under the same filters as the list
     * but ignoring the status filter itself — otherwise picking "Arrived" would
     * leave every other total reading zero.
     *
     * @param  array<string,mixed> $filters
     * @return array<string,int>
     */
    public function statusCounts(array $filters = []): array
    {
        unset($filters['status']);

        // Deliberately built from matching() rather than filtered(): an ORDER BY
        // on a column that is not in the GROUP BY is rejected under MySQL's
        // ONLY_FULL_GROUP_BY, which is on by default in MySQL 8.
        $rows = $this->matching($filters)
            ->select('status', 'COUNT(*) AS total')
            ->groupBy('status')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The filters, without an ordering, so both the list and the totals are read
     * from exactly the same set of rows.
     *
     * @param array<string,mixed> $filters
     */
    private function matching(array $filters): \App\Database\QueryBuilder
    {
        $query = $this->scoped();

        if (($filters['search'] ?? '') !== '') {
            $query->where(
                'email_normalized',
                'like',
                '%' . normalize_email((string) $filters['search']) . '%'
            );
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', '=', (string) $filters['status']);
        }

        if ((int) ($filters['campaign'] ?? 0) > 0) {
            $query->where('campaign_id', '=', (int) $filters['campaign']);
        }

        if (($filters['class'] ?? '') !== '') {
            $query->where('message_class', '=', (string) $filters['class']);
        }

        if ((int) ($filters['days'] ?? 0) > 0) {
            // The injected clock, not time(): every other date rule in the
            // application reads from it, and a window that disagreed with the
            // created_at values the same repository writes would quietly return
            // an empty log.
            $query->where('created_at', '>=', $this->clock->agoString((int) $filters['days']));
        }

        return $query;
    }

    /** @return array<int,array<string,mixed>> */
    public function forContact(int $contactId, int $limit = 50): array
    {
        return $this->scoped()
            ->where('contact_id', '=', $contactId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /** @return array<int,array<string,mixed>> */
    public function forCampaign(int $campaignId, int $limit = 100, int $offset = 0): array
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }
}
