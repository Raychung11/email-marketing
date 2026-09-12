<?php

declare(strict_types=1);

namespace App\Repositories;

final class TemplateRepository extends Repository
{
    protected function table(): string
    {
        return 'templates';
    }

    protected function softDeletes(): bool
    {
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(?string $category = null): array
    {
        $query = $this->scoped()->orderBy('updated_at', 'desc');

        if ($category !== null && $category !== '') {
            $query->where('category', '=', $category);
        }

        $rows = $query->get();

        foreach ($rows as $index => $row) {
            $rows[$index]['blocks'] = $this->decodeJson($row['blocks'] ?? null) ?? [];
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    public function findOrFailWithBlocks(int $id): array
    {
        $row           = $this->findOrFail($id);
        $row['blocks'] = $this->decodeJson($row['blocks'] ?? null) ?? [];

        return $row;
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @param array<string,mixed>            $attributes
     */
    public function create(string $name, array $blocks, array $attributes = []): int
    {
        return $this->scoped()->insert($this->withTimestamps($this->withTenant(array_merge([
            'uuid'   => uuid4(),
            'name'   => $name,
            'blocks' => $this->encodeJson($blocks),
        ], $attributes))));
    }

    /**
     * @param array<string,mixed>                 $attributes
     * @param array<int,array<string,mixed>>|null $blocks
     */
    public function update(int $id, array $attributes, ?array $blocks = null): int
    {
        if ($blocks !== null) {
            $attributes['blocks'] = $this->encodeJson($blocks);
        }

        return $this->scoped()->where('id', '=', $id)->update($this->withTimestamps($attributes, false));
    }

    public function softDelete(int $id): int
    {
        return $this->scoped()->where('id', '=', $id)->update(['deleted_at' => $this->now()]);
    }

    public function isInUse(int $id): bool
    {
        return $this->connection->table('campaigns')
            ->where('organisation_id', '=', $this->organisationId())
            ->where('template_id', '=', $id)
            ->whereNull('deleted_at')
            ->exists();
    }
}
