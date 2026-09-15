<?php

declare(strict_types=1);

namespace App\Repositories;

final class AutomationRunRepository extends Repository
{
    protected function table(): string
    {
        return 'automation_runs';
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        if (isset($attributes['context']) && is_array($attributes['context'])) {
            $attributes['context'] = $this->encodeJson($attributes['context']);
        }

        $attributes['started_at'] = $attributes['started_at'] ?? $this->now();

        return $this->scoped()->insert($this->withTimestamps($this->withTenant($attributes)));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        if (isset($attributes['context']) && is_array($attributes['context'])) {
            $attributes['context'] = $this->encodeJson($attributes['context']);
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

        $row['context'] = $this->decodeJson($row['context'] ?? null) ?? [];

        return $row;
    }

    /**
     * How many times this contact has been through this journey.
     *
     * The re-entry guard reads this. Without it, a contact who keeps triggering
     * an automation — re-tagged by an import, say — gets mailed every time.
     */
    public function runCountFor(int $automationId, int $contactId): int
    {
        return $this->scoped()
            ->where('automation_id', '=', $automationId)
            ->where('contact_id', '=', $contactId)
            ->count();
    }

    /** @return array<string,mixed>|null */
    public function lastRunFor(int $automationId, int $contactId): ?array
    {
        return $this->scoped()
            ->where('automation_id', '=', $automationId)
            ->where('contact_id', '=', $contactId)
            ->orderBy('started_at', 'desc')
            ->first();
    }

    public function hasOpenRun(int $automationId, int $contactId): bool
    {
        return $this->scoped()
            ->where('automation_id', '=', $automationId)
            ->where('contact_id', '=', $contactId)
            ->whereIn('status', ['active', 'waiting'])
            ->exists();
    }

    /**
     * Runs ready to move, across every tenant. The scheduler's query.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueEverywhere(string $now, int $limit = 200): array
    {
        return $this->connection->select(
            "SELECT * FROM automation_runs
             WHERE status = 'waiting' AND resume_at IS NOT NULL AND resume_at <= ?
             ORDER BY resume_at LIMIT " . max(1, $limit),
            [$now]
        );
    }

    /**
     * Claim a run for stepping.
     *
     * A conditional UPDATE, so two schedulers overlapping cannot both advance the
     * same run and send the same email twice.
     */
    public function claim(int $id): bool
    {
        return $this->scoped()
            ->where('id', '=', $id)
            ->whereIn('status', ['waiting', 'active'])
            ->update(['status' => 'active', 'resume_at' => null, 'updated_at' => $this->now()]) > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function forAutomation(int $automationId, int $limit = 50): array
    {
        return $this->scoped()
            ->where('automation_id', '=', $automationId)
            ->orderBy('started_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /** @param array<string,mixed> $metadata */
    public function log(
        int $runId,
        ?int $nodeId,
        string $nodeType,
        ?string $actionType,
        string $outcome,
        string $message = '',
        ?string $reasonCode = null,
        array $metadata = [],
    ): void {
        $this->connection->table('automation_run_logs')->insert([
            'organisation_id'   => $this->organisationId(),
            'automation_run_id' => $runId,
            'automation_node_id' => $nodeId,
            'node_type'         => $nodeType,
            'action_type'       => $actionType,
            'outcome'           => $outcome,
            'reason_code'       => $reasonCode,
            'message'           => mb_substr($message, 0, 255),
            'metadata'          => $metadata === [] ? null : $this->encodeJson($metadata),
            'created_at'        => $this->now(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function logsFor(int $runId): array
    {
        return $this->connection->select(
            'SELECT * FROM automation_run_logs WHERE organisation_id = ? AND automation_run_id = ?
             ORDER BY id',
            [$this->organisationId(), $runId]
        );
    }
}
