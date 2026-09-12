<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Suppression is keyed on the normalized address, not on contact_id, so that
 * deleting, merging or re-importing a contact cannot resurrect a suppressed
 * address.
 */
final class SuppressionRepository extends Repository
{
    protected function table(): string
    {
        return 'suppressions';
    }

    /** @return array<string,mixed>|null */
    public function findActive(string $email): ?array
    {
        return $this->scoped()
            ->where('email_normalized', '=', normalize_email($email))
            ->whereNull('removed_at')
            ->first();
    }

    public function isSuppressed(string $email): bool
    {
        return $this->findActive($email) !== null;
    }

    /**
     * Insert-or-keep. An existing active suppression is never overwritten with a
     * weaker reason: once a complaint is on record, a later "manual" entry must
     * not dilute it.
     *
     * @param array<string,mixed> $attributes
     */
    public function suppress(string $email, string $reason, array $attributes = []): int
    {
        $normalized = normalize_email($email);
        $existing   = $this->findActive($normalized);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->scoped()->insert($this->withTenant(array_merge([
            'email'            => $email,
            'email_normalized' => $normalized,
            'email_hash'       => hash('sha256', $normalized),
            'reason'           => $reason,
            'created_at'       => $this->now(),
        ], $attributes)));
    }

    /**
     * Removal is deliberate, permission-gated and audited. Nothing in the import
     * pipeline, the API or the automation engine calls this.
     */
    public function remove(int $id, ?int $userId, string $reason): int
    {
        return $this->scoped()->where('id', '=', $id)->update([
            'removed_at'         => $this->now(),
            'removed_by_user_id' => $userId,
            'removal_reason'     => $reason,
        ]);
    }

    /**
     * Which of these addresses are suppressed.
     *
     * @param array<int,string> $emails
     * @return array<string,string> normalized email => reason
     */
    public function suppressedAmong(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $normalized = array_values(array_unique(array_map('normalize_email', $emails)));
        $result     = [];

        // Chunked so a 50k-row import does not build one enormous IN () list.
        foreach (array_chunk($normalized, 500) as $chunk) {
            $rows = $this->scoped()
                ->select('email_normalized', 'reason')
                ->whereIn('email_normalized', $chunk)
                ->whereNull('removed_at')
                ->get();

            foreach ($rows as $row) {
                $result[(string) $row['email_normalized']] = (string) $row['reason'];
            }
        }

        return $result;
    }

    /** @param array{search?:string,reason?:string} $filters */
    public function filtered(array $filters): \App\Database\QueryBuilder
    {
        $query = $this->scoped()->whereNull('removed_at');

        if (($filters['search'] ?? '') !== '') {
            $query->where('email_normalized', 'like', '%' . (string) $filters['search'] . '%');
        }

        if (($filters['reason'] ?? '') !== '') {
            $query->where('reason', '=', (string) $filters['reason']);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /** @return array<string,int> */
    public function reasonCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT reason, COUNT(*) AS total FROM suppressions
             WHERE organisation_id = ? AND removed_at IS NULL GROUP BY reason',
            [$this->organisationId()]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['reason']] = (int) $row['total'];
        }

        return $counts;
    }

    public function activeCount(): int
    {
        return $this->scoped()->whereNull('removed_at')->count();
    }
}
