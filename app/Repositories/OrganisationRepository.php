<?php

declare(strict_types=1);

namespace App\Repositories;

final class OrganisationRepository extends Repository
{
    protected function table(): string
    {
        return 'organisations';
    }

    protected function isTenantScoped(): bool
    {
        return false;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->unscoped()->where('id', '=', $id)->whereNull('deleted_at')->first();
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->unscoped()->where('slug', '=', $slug)->whereNull('deleted_at')->first();
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $attributes['uuid'] = $attributes['uuid'] ?? uuid4();
        $attributes['slug'] = $this->uniqueSlug((string) ($attributes['slug'] ?? $attributes['name']));

        return $this->unscoped()->insert($this->withTimestamps($attributes));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        return $this->unscoped()
            ->where('id', '=', $id)
            ->update($this->withTimestamps($attributes, false));
    }

    public function uniqueSlug(string $base): string
    {
        $slug     = str_slug($base) ?: 'org';
        $candidate = $slug;
        $suffix    = 1;

        while ($this->unscoped()->where('slug', '=', $candidate)->exists()) {
            $candidate = $slug . '-' . (++$suffix);
        }

        return $candidate;
    }

    /** @return array<int,array<string,mixed>> */
    public function forUser(int $userId): array
    {
        return $this->connection->select(
            'SELECT o.id, o.uuid, o.name, o.slug, o.timezone, o.currency, o.country, ou.role_id, r.key AS role_key
             FROM organisations o
             INNER JOIN organisation_users ou ON ou.organisation_id = o.id
             INNER JOIN roles r ON r.id = ou.role_id
             WHERE ou.user_id = ? AND ou.status = ? AND o.deleted_at IS NULL
             ORDER BY o.name',
            [$userId, 'active']
        );
    }
}
