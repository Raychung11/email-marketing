<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\QueryBuilder;
use Generator;

final class ContactRepository extends Repository
{
    protected function table(): string
    {
        return 'contacts';
    }

    protected function softDeletes(): bool
    {
        return true;
    }

    /**
     * Include soft-deleted contacts.
     *
     * Needed by the merge and privacy paths, which must still be able to read a
     * record they have just anonymised or merged away.
     *
     * @return array<string,mixed>|null
     */
    public function findWithTrashed(int $id): ?array
    {
        return $this->scopedWithTrashed()->where('id', '=', $id)->first();
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->scoped()
            ->where('email_normalized', '=', normalize_email($email))
            ->first();
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $attributes['uuid']             = $attributes['uuid'] ?? uuid4();
        $attributes['email_normalized'] = normalize_email((string) $attributes['email']);

        return $this->scoped()->insert($this->withTimestamps($this->withTenant($attributes)));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        if (isset($attributes['email'])) {
            $attributes['email_normalized'] = normalize_email((string) $attributes['email']);
        }

        return $this->scoped()
            ->where('id', '=', $id)
            ->update($this->withTimestamps($attributes, false));
    }

    /**
     * Soft delete. Hard deletion is a separate, audited privacy operation that
     * anonymises in place and never touches suppression records.
     */
    public function softDelete(int $id): int
    {
        return $this->scoped()->where('id', '=', $id)->update(['deleted_at' => $this->now()]);
    }

    /**
     * @param array{search?:string,status?:string,lifecycle?:string,country?:string,tag_id?:int,list_id?:int,segment_id?:int,suppressed?:string,consent?:string,owner_user_id?:int} $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->scoped();

        if (($filters['search'] ?? '') !== '') {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';

            $query->whereGroup(static function (QueryBuilder $q) use ($term): void {
                $q->where('email', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('company', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            });
        }

        if (($filters['status'] ?? '') !== '') {
            $query->where('customer_status', '=', (string) $filters['status']);
        }

        if (($filters['lifecycle'] ?? '') !== '') {
            $query->where('lifecycle_stage', '=', (string) $filters['lifecycle']);
        }

        if (($filters['country'] ?? '') !== '') {
            $query->where('country', '=', (string) $filters['country']);
        }

        if ((int) ($filters['owner_user_id'] ?? 0) > 0) {
            $query->where('owner_user_id', '=', (int) $filters['owner_user_id']);
        }

        if ((int) ($filters['tag_id'] ?? 0) > 0) {
            $query->whereExistsRaw(
                'SELECT 1 FROM contact_tags ct WHERE ct.contact_id = contacts.id
                 AND ct.organisation_id = ? AND ct.tag_id = ?',
                [$this->organisationId(), (int) $filters['tag_id']]
            );
        }

        if ((int) ($filters['list_id'] ?? 0) > 0) {
            $query->whereExistsRaw(
                'SELECT 1 FROM list_contacts lc WHERE lc.contact_id = contacts.id
                 AND lc.organisation_id = ? AND lc.list_id = ?',
                [$this->organisationId(), (int) $filters['list_id']]
            );
        }

        if (($filters['suppressed'] ?? '') === 'yes') {
            $query->where('is_suppressed_cache', '=', 1);
        } elseif (($filters['suppressed'] ?? '') === 'no') {
            $query->where('is_suppressed_cache', '=', 0);
        }

        if (($filters['consent'] ?? '') === 'yes') {
            $query->where('marketing_consent_cache', '=', 1);
        } elseif (($filters['consent'] ?? '') === 'no') {
            $query->where('marketing_consent_cache', '=', 0);
        }

        return $query;
    }

    /**
     * Stream contacts for a batch job.
     *
     * Callers that need every contact in a segment (campaign snapshot, export)
     * use this, never filtered()->get(), because a million-row array is not a
     * thing PHP should be asked to hold.
     *
     * @return Generator<int,array<string,mixed>>
     */
    public function stream(QueryBuilder $query, int $chunkSize = 1000): Generator
    {
        $lastId = 0;

        do {
            $clone = clone $query;
            $rows  = $clone->where('contacts.id', '>', $lastId)
                ->orderBy('contacts.id')
                ->limit($chunkSize)
                ->get();

            foreach ($rows as $row) {
                yield $row;
                $lastId = (int) $row['id'];
            }
        } while (count($rows) === $chunkSize);
    }

    public function incrementLeadScore(int $contactId, int $delta): void
    {
        // Done in SQL so concurrent engagement events cannot lose an increment.
        $this->connection->execute(
            'UPDATE contacts SET lead_score = lead_score + ?, updated_at = ?
             WHERE id = ? AND organisation_id = ?',
            [$delta, $this->now(), $contactId, $this->organisationId()]
        );
    }

    public function touchEngagement(int $contactId, string $type): void
    {
        $column = match ($type) {
            'open'  => 'last_email_open_at',
            'click' => 'last_email_click_at',
            'sent'  => 'last_email_sent_at',
            default => null,
        };

        $now      = $this->now();
        $sets     = ['last_engagement_at = ?'];
        $bindings = [$now];

        if ($column !== null) {
            $sets[]     = $column . ' = ?';
            $bindings[] = $now;
        }

        $bindings[] = $now;
        $bindings[] = $contactId;
        $bindings[] = $this->organisationId();

        $this->connection->execute(
            'UPDATE contacts SET ' . implode(', ', $sets) . ', updated_at = ?
             WHERE id = ? AND organisation_id = ?',
            $bindings
        );
    }

    public function setConsentCache(int $contactId, bool $hasConsent): void
    {
        $this->scoped()->where('id', '=', $contactId)
            ->update(['marketing_consent_cache' => $hasConsent ? 1 : 0]);
    }

    /**
     * Refresh the suppression mirror for every contact matching an address.
     * Keyed on the address, not the id, because suppression is address-scoped.
     */
    public function setSuppressionCacheByEmail(string $emailNormalized, bool $suppressed): void
    {
        $this->scoped()
            ->where('email_normalized', '=', $emailNormalized)
            ->update(['is_suppressed_cache' => $suppressed ? 1 : 0]);
    }

    /** @return array<string,int> */
    public function statusCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT customer_status, COUNT(*) AS total FROM contacts
             WHERE organisation_id = ? AND deleted_at IS NULL
             GROUP BY customer_status',
            [$this->organisationId()]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['customer_status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array<string,mixed> */
    public function dashboardTotals(): array
    {
        $row = $this->connection->selectOne(
            "SELECT
                COUNT(*) AS total_contacts,
                SUM(CASE WHEN customer_status IN ('customer','repeat_customer','vip') THEN 1 ELSE 0 END) AS customers,
                SUM(CASE WHEN customer_status = 'lead' THEN 1 ELSE 0 END) AS leads,
                SUM(CASE WHEN marketing_consent_cache = 1 THEN 1 ELSE 0 END) AS marketable,
                SUM(CASE WHEN is_suppressed_cache = 1 THEN 1 ELSE 0 END) AS suppressed,
                COALESCE(SUM(total_revenue), 0) AS lifetime_revenue
             FROM contacts WHERE organisation_id = ? AND deleted_at IS NULL",
            [$this->organisationId()]
        );

        return $row ?? [];
    }

    public function createdSince(string $since): int
    {
        return $this->scoped()->where('created_at', '>=', $since)->count();
    }

    /** @return array<int,array<string,mixed>> */
    public function growthByMonth(int $months = 12): array
    {
        $since  = $this->clock->now()->modify('-' . $months . ' months')->format('Y-m-01 00:00:00');
        $format = $this->connection->driver() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        return $this->connection->select(
            "SELECT {$format} AS period, COUNT(*) AS total
             FROM contacts
             WHERE organisation_id = ? AND deleted_at IS NULL AND created_at >= ?
             GROUP BY period ORDER BY period",
            [$this->organisationId(), $since]
        );
    }
}
