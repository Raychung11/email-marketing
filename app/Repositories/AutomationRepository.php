<?php

declare(strict_types=1);

namespace App\Repositories;

final class AutomationRepository extends Repository
{
    protected function table(): string
    {
        return 'automations';
    }

    protected function softDeletes(): bool
    {
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->scoped()->orderBy('created_at', 'desc')->get();
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $attributes['uuid'] = $attributes['uuid'] ?? uuid4();

        if (isset($attributes['trigger_config']) && is_array($attributes['trigger_config'])) {
            $attributes['trigger_config'] = $this->encodeJson($attributes['trigger_config']);
        }

        return $this->scoped()->insert($this->withTimestamps($this->withTenant($attributes)));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        if (isset($attributes['trigger_config']) && is_array($attributes['trigger_config'])) {
            $attributes['trigger_config'] = $this->encodeJson($attributes['trigger_config']);
        }

        return $this->scoped()->where('id', '=', $id)->update($this->withTimestamps($attributes, false));
    }

    /** @return array<string,mixed>|null */
    public function findDecoded(int $id): ?array
    {
        $row = $this->find($id);

        if ($row === null) {
            return null;
        }

        $row['trigger_config'] = $this->decodeJson($row['trigger_config'] ?? null) ?? [];

        return $row;
    }

    /**
     * Live automations listening for one kind of event, in this organisation.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeFor(string $triggerType): array
    {
        $rows = $this->scoped()
            ->where('status', '=', 'active')
            ->where('trigger_type', '=', $triggerType)
            ->get();

        foreach ($rows as $index => $row) {
            $rows[$index]['trigger_config'] = $this->decodeJson($row['trigger_config'] ?? null) ?? [];
        }

        return $rows;
    }

    /**
     * Scheduled triggers across every tenant, for the scheduler to walk.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeEverywhereFor(string $triggerType, int $limit = 100): array
    {
        return $this->connection->select(
            "SELECT * FROM automations
             WHERE status = 'active' AND trigger_type = ? AND deleted_at IS NULL
             ORDER BY id LIMIT " . max(1, $limit),
            [$triggerType]
        );
    }

    public function softDelete(int $id): int
    {
        return $this->scoped()->where('id', '=', $id)->update(['deleted_at' => $this->now()]);
    }

    /** @return array<int,array<string,mixed>> */
    public function nodes(int $automationId): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM automation_nodes WHERE organisation_id = ? AND automation_id = ? ORDER BY id',
            [$this->organisationId(), $automationId]
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['config'] = $this->decodeJson($row['config'] ?? null) ?? [];
        }

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function connections(int $automationId): array
    {
        return $this->connection->select(
            'SELECT * FROM automation_connections WHERE organisation_id = ? AND automation_id = ?
             ORDER BY sort_order, id',
            [$this->organisationId(), $automationId]
        );
    }

    /**
     * Recompute the counters shown on the journey list.
     *
     * Derived rather than incremented, so a crashed run or a replayed step
     * cannot leave the numbers permanently wrong.
     */
    public function refreshCounters(int $id): void
    {
        $row = $this->connection->selectOne(
            "SELECT COUNT(*) AS entered,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status IN ('active','waiting') THEN 1 ELSE 0 END) AS active
             FROM automation_runs WHERE organisation_id = ? AND automation_id = ?",
            [$this->organisationId(), $id]
        ) ?? [];

        $this->scoped()->where('id', '=', $id)->update([
            'entered_count'   => (int) ($row['entered'] ?? 0),
            'completed_count' => (int) ($row['completed'] ?? 0),
            'active_count'    => (int) ($row['active'] ?? 0),
        ]);
    }
}
