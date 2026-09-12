<?php

declare(strict_types=1);

namespace App\Repositories;

final class TagRepository extends Repository
{
    protected function table(): string
    {
        return 'tags';
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->scoped()->orderBy('name')->get();
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->scoped()->where('slug', '=', $slug)->first();
    }

    /** @param array<string,mixed> $attributes */
    public function create(string $name, array $attributes = []): int
    {
        return $this->scoped()->insert($this->withTimestamps($this->withTenant(array_merge([
            'name' => $name,
            'slug' => str_slug($name),
        ], $attributes))));
    }

    public function firstOrCreate(string $name): int
    {
        $slug     = str_slug($name);
        $existing = $this->findBySlug($slug);

        return $existing !== null ? (int) $existing['id'] : $this->create($name);
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        if (isset($attributes['name'])) {
            $attributes['slug'] = str_slug((string) $attributes['name']);
        }

        return $this->scoped()->where('id', '=', $id)->update($this->withTimestamps($attributes, false));
    }

    public function delete(int $id): int
    {
        $this->connection->table('contact_tags')
            ->where('organisation_id', '=', $this->organisationId())
            ->where('tag_id', '=', $id)
            ->delete();

        return $this->scoped()->where('id', '=', $id)->delete();
    }

    public function attach(int $contactId, int $tagId, ?int $userId = null): bool
    {
        $exists = $this->connection->table('contact_tags')
            ->where('contact_id', '=', $contactId)
            ->where('tag_id', '=', $tagId)
            ->exists();

        if ($exists) {
            return false;
        }

        $this->connection->table('contact_tags')->insert([
            'organisation_id'  => $this->organisationId(),
            'contact_id'       => $contactId,
            'tag_id'           => $tagId,
            'added_by_user_id' => $userId,
            'created_at'       => $this->now(),
        ]);

        $this->refreshCount($tagId);

        return true;
    }

    public function detach(int $contactId, int $tagId): bool
    {
        $removed = $this->connection->table('contact_tags')
            ->where('organisation_id', '=', $this->organisationId())
            ->where('contact_id', '=', $contactId)
            ->where('tag_id', '=', $tagId)
            ->delete();

        if ($removed > 0) {
            $this->refreshCount($tagId);
        }

        return $removed > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function forContact(int $contactId): array
    {
        return $this->connection->select(
            'SELECT t.* FROM tags t
             INNER JOIN contact_tags ct ON ct.tag_id = t.id
             WHERE ct.contact_id = ? AND t.organisation_id = ?
             ORDER BY t.name',
            [$contactId, $this->organisationId()]
        );
    }

    public function refreshCount(int $tagId): void
    {
        $this->connection->execute(
            'UPDATE tags SET contact_count = (
                SELECT COUNT(*) FROM contact_tags ct WHERE ct.tag_id = tags.id
             ) WHERE id = ? AND organisation_id = ?',
            [$tagId, $this->organisationId()]
        );
    }
}
