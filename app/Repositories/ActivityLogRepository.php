<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Operational activity: the Customer 360 timeline. High volume, and deliberately
 * separate from audit_logs so engagement noise never dilutes the security trail.
 */
final class ActivityLogRepository extends Repository
{
    protected function table(): string
    {
        return 'activity_logs';
    }

    /** @param array<string,mixed> $attributes */
    public function record(array $attributes): int
    {
        if (isset($attributes['metadata']) && is_array($attributes['metadata'])) {
            $attributes['metadata'] = $this->encodeJson($attributes['metadata']);
        }

        $now = $this->now();

        return $this->scoped()->insert($this->withTenant(array_merge([
            'occurred_at' => $now,
            'created_at'  => $now,
        ], $attributes)));
    }

    /** @return array<int,array<string,mixed>> */
    public function timelineForContact(int $contactId, int $limit = 100): array
    {
        $rows = $this->scoped()
            ->where('contact_id', '=', $contactId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        foreach ($rows as $index => $row) {
            $rows[$index]['metadata'] = $this->decodeJson($row['metadata'] ?? null) ?? [];
        }

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 30): array
    {
        return $this->scoped()
            ->orderBy('occurred_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }
}
