<?php

declare(strict_types=1);

namespace App\Repositories;

final class ImportRepository extends Repository
{
    protected function table(): string
    {
        return 'import_batches';
    }

    /** @param array<string,mixed> $attributes */
    public function createBatch(array $attributes): int
    {
        foreach (['column_map', 'target_list_ids', 'target_tag_ids'] as $jsonColumn) {
            if (isset($attributes[$jsonColumn]) && is_array($attributes[$jsonColumn])) {
                $attributes[$jsonColumn] = $this->encodeJson($attributes[$jsonColumn]);
            }
        }

        $attributes['uuid'] = $attributes['uuid'] ?? uuid4();

        return $this->scoped()->insert($this->withTimestamps($this->withTenant($attributes)));
    }

    /** @param array<string,mixed> $attributes */
    public function updateBatch(int $id, array $attributes): int
    {
        foreach (['column_map', 'target_list_ids', 'target_tag_ids'] as $jsonColumn) {
            if (isset($attributes[$jsonColumn]) && is_array($attributes[$jsonColumn])) {
                $attributes[$jsonColumn] = $this->encodeJson($attributes[$jsonColumn]);
            }
        }

        return $this->scoped()->where('id', '=', $id)->update($this->withTimestamps($attributes, false));
    }

    /** @return array<string,mixed>|null */
    public function findBatch(int $id): ?array
    {
        $row = $this->scoped()->where('id', '=', $id)->first();

        if ($row === null) {
            return null;
        }

        foreach (['column_map', 'target_list_ids', 'target_tag_ids'] as $jsonColumn) {
            $row[$jsonColumn] = $this->decodeJson($row[$jsonColumn] ?? null);
        }

        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function recentBatches(int $limit = 20): array
    {
        return $this->scoped()->orderBy('created_at', 'desc')->limit($limit)->get();
    }

    /** @param array<string,mixed> $attributes */
    public function recordRow(array $attributes): int
    {
        if (isset($attributes['raw_data']) && is_array($attributes['raw_data'])) {
            $attributes['raw_data'] = $this->encodeJson($attributes['raw_data']);
        }

        return $this->connection->table('import_rows')->insert($this->withTenant(array_merge([
            'created_at' => $this->now(),
        ], $attributes)));
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function recordRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $prepared = [];

        foreach ($rows as $row) {
            if (isset($row['raw_data']) && is_array($row['raw_data'])) {
                $row['raw_data'] = $this->encodeJson($row['raw_data']);
            }

            $prepared[] = array_merge([
                'organisation_id'  => $this->organisationId(),
                'import_batch_id'  => 0,
                'row_number'       => 0,
                'email'            => null,
                'raw_data'         => null,
                'outcome'          => 'pending',
                'message'          => null,
                'contact_id'       => null,
                'created_at'       => $this->now(),
            ], $row);
        }

        return $this->connection->table('import_rows')->insertMany($prepared);
    }

    /** @return array<int,array<string,mixed>> */
    public function rowsForBatch(int $batchId, ?string $outcome = null, int $limit = 200): array
    {
        $query = $this->connection->table('import_rows')
            ->where('organisation_id', '=', $this->organisationId())
            ->where('import_batch_id', '=', $batchId);

        if ($outcome !== null) {
            $query->where('outcome', '=', $outcome);
        }

        return $query->orderBy('row_number')->limit($limit)->get();
    }

    /** @return array<string,int> */
    public function outcomeCounts(int $batchId): array
    {
        $rows = $this->connection->select(
            'SELECT outcome, COUNT(*) AS total FROM import_rows
             WHERE organisation_id = ? AND import_batch_id = ? GROUP BY outcome',
            [$this->organisationId(), $batchId]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['outcome']] = (int) $row['total'];
        }

        return $counts;
    }
}
