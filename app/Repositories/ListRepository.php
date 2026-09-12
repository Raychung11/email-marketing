<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Static lists. Membership is explicit, unlike segments which are evaluated.
 */
final class ListRepository extends Repository
{
    protected function table(): string
    {
        return 'lists';
    }

    protected function softDeletes(): bool
    {
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->scoped()->orderBy('name')->get();
    }

    /** @param array<string,mixed> $attributes */
    public function create(string $name, array $attributes = []): int
    {
        return $this->scoped()->insert($this->withTimestamps($this->withTenant(array_merge([
            'uuid' => uuid4(),
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
        ], $attributes))));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        return $this->scoped()->where('id', '=', $id)->update($this->withTimestamps($attributes, false));
    }

    public function softDelete(int $id): int
    {
        return $this->scoped()->where('id', '=', $id)->update(['deleted_at' => $this->now()]);
    }

    public function addContact(int $listId, int $contactId, string $via = 'manual'): bool
    {
        $exists = $this->connection->table('list_contacts')
            ->where('list_id', '=', $listId)
            ->where('contact_id', '=', $contactId)
            ->exists();

        if ($exists) {
            return false;
        }

        $this->connection->table('list_contacts')->insert([
            'organisation_id' => $this->organisationId(),
            'list_id'         => $listId,
            'contact_id'      => $contactId,
            'added_via'       => $via,
            'created_at'      => $this->now(),
        ]);

        $this->refreshCount($listId);

        return true;
    }

    public function removeContact(int $listId, int $contactId): bool
    {
        $removed = $this->connection->table('list_contacts')
            ->where('organisation_id', '=', $this->organisationId())
            ->where('list_id', '=', $listId)
            ->where('contact_id', '=', $contactId)
            ->delete();

        if ($removed > 0) {
            $this->refreshCount($listId);
        }

        return $removed > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function forContact(int $contactId): array
    {
        return $this->connection->select(
            'SELECT l.*, lc.added_via, lc.created_at AS joined_at
             FROM lists l
             INNER JOIN list_contacts lc ON lc.list_id = l.id
             WHERE lc.contact_id = ? AND l.organisation_id = ? AND l.deleted_at IS NULL
             ORDER BY l.name',
            [$contactId, $this->organisationId()]
        );
    }

    public function refreshCount(int $listId): void
    {
        $this->connection->execute(
            'UPDATE lists SET contact_count = (
                SELECT COUNT(*) FROM list_contacts lc WHERE lc.list_id = lists.id
             ) WHERE id = ? AND organisation_id = ?',
            [$listId, $this->organisationId()]
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base      = str_slug($name) ?: 'list';
        $candidate = $base;
        $suffix    = 1;

        while ($this->scoped()->where('slug', '=', $candidate)->exists()) {
            $candidate = $base . '-' . (++$suffix);
        }

        return $candidate;
    }
}
