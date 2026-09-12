<?php

declare(strict_types=1);

namespace App\Repositories;

final class SegmentRepository extends Repository
{
    protected function table(): string
    {
        return 'segments';
    }

    protected function softDeletes(): bool
    {
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->scoped()->orderBy('name')->get();

        foreach ($rows as $index => $row) {
            $rows[$index]['definition'] = $this->decodeJson($row['definition'] ?? null) ?? [];
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function findWithDefinition(int $id): ?array
    {
        $row = $this->scoped()->where('id', '=', $id)->first();

        if ($row === null) {
            return null;
        }

        $row['definition'] = $this->decodeJson($row['definition'] ?? null) ?? [];

        return $row;
    }

    /**
     * @param array<string,mixed> $definition validated SegmentDefinition payload
     * @param array<string,mixed> $attributes
     */
    public function create(string $name, array $definition, array $attributes = []): int
    {
        $segmentId = $this->scoped()->insert($this->withTimestamps($this->withTenant(array_merge([
            'uuid'       => uuid4(),
            'name'       => $name,
            'slug'       => $this->uniqueSlug($name),
            'definition' => $this->encodeJson($definition),
            'match_type' => (string) ($definition['match'] ?? 'all'),
        ], $attributes))));

        $this->syncRules($segmentId, $definition);

        return $segmentId;
    }

    /**
     * @param array<string,mixed>      $attributes
     * @param array<string,mixed>|null $definition
     */
    public function update(int $id, array $attributes, ?array $definition = null): int
    {
        if ($definition !== null) {
            $attributes['definition'] = $this->encodeJson($definition);
            $attributes['match_type'] = (string) ($definition['match'] ?? 'all');
        }

        $updated = $this->scoped()->where('id', '=', $id)->update($this->withTimestamps($attributes, false));

        if ($definition !== null) {
            $this->syncRules($id, $definition);
        }

        return $updated;
    }

    public function softDelete(int $id): int
    {
        return $this->scoped()->where('id', '=', $id)->update(['deleted_at' => $this->now()]);
    }

    public function storeCounts(int $id, int $total, int $eligible): void
    {
        $this->scoped()->where('id', '=', $id)->update([
            'cached_count'          => $total,
            'cached_eligible_count' => $eligible,
            'counts_refreshed_at'   => $this->now(),
        ]);
    }

    public function countsAreStale(array $segment, int $ttlSeconds): bool
    {
        $refreshed = $segment['counts_refreshed_at'] ?? null;

        if (!is_string($refreshed) || $refreshed === '') {
            return true;
        }

        return strtotime($refreshed) < ($this->clock->timestamp() - $ttlSeconds);
    }

    /**
     * Mirror the JSON rule tree into segment_rules.
     *
     * The JSON on the segment is authoritative for evaluation; these rows exist
     * for the builder UI, for reporting on which fields organisations actually
     * segment by, and so a DBA can read a segment without parsing JSON.
     *
     * @param array<string,mixed> $definition
     */
    private function syncRules(int $segmentId, array $definition): void
    {
        $this->connection->table('segment_rules')
            ->where('organisation_id', '=', $this->organisationId())
            ->where('segment_id', '=', $segmentId)
            ->delete();

        $this->insertNode($segmentId, $definition, null, 0, 0);
    }

    /** @param array<string,mixed> $node */
    private function insertNode(
        int $segmentId,
        array $node,
        ?int $parentId,
        int $depth,
        int $sortOrder,
    ): void {
        $isGroup = isset($node['rules']) && is_array($node['rules']);

        $id = $this->connection->table('segment_rules')->insert([
            'organisation_id'  => $this->organisationId(),
            'segment_id'       => $segmentId,
            'parent_rule_id'   => $parentId,
            'node_type'        => $isGroup ? 'group' : 'condition',
            'boolean_operator' => (string) ($node['match'] ?? 'and') === 'any' ? 'or' : 'and',
            'field_key'        => $isGroup ? null : (string) ($node['field'] ?? ''),
            'operator'         => $isGroup ? null : (string) ($node['operator'] ?? ''),
            'value'            => $isGroup ? null : $this->stringifyValue($node['value'] ?? null),
            'value_secondary'  => $isGroup ? null : $this->stringifyValue($node['value2'] ?? null),
            'depth'            => $depth,
            'sort_order'       => $sortOrder,
            'created_at'       => $this->now(),
            'updated_at'       => $this->now(),
        ]);

        if (!$isGroup) {
            return;
        }

        foreach (array_values($node['rules']) as $index => $child) {
            if (is_array($child)) {
                $this->insertNode($segmentId, $child, $id, $depth + 1, $index);
            }
        }
    }

    private function stringifyValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return implode(',', array_map(static fn ($v): string => (string) $v, $value));
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function uniqueSlug(string $name): string
    {
        $base      = str_slug($name) ?: 'segment';
        $candidate = $base;
        $suffix    = 1;

        while ($this->scoped()->where('slug', '=', $candidate)->exists()) {
            $candidate = $base . '-' . (++$suffix);
        }

        return $candidate;
    }
}
