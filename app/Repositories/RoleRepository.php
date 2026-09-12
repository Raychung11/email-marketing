<?php

declare(strict_types=1);

namespace App\Repositories;

final class RoleRepository extends Repository
{
    protected function table(): string
    {
        return 'roles';
    }

    protected function isTenantScoped(): bool
    {
        return false;
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $key, ?int $organisationId = null): ?array
    {
        $query = $this->unscoped()->where('key', '=', $key);

        if ($organisationId === null) {
            $query->whereNull('organisation_id');
        } else {
            $query->where('organisation_id', '=', $organisationId);
        }

        return $query->first();
    }

    /** @return array<int,array<string,mixed>> */
    public function assignable(?int $organisationId = null): array
    {
        $rows = $this->connection->select(
            'SELECT * FROM roles WHERE organisation_id IS NULL OR organisation_id = ? ORDER BY rank DESC',
            [$organisationId ?? 0]
        );

        return $rows;
    }

    /**
     * Permission keys granted by a role.
     *
     * @return array<int,string>
     */
    public function permissionKeys(int $roleId): array
    {
        $rows = $this->connection->select(
            'SELECT p.key FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ?',
            [$roleId]
        );

        return array_map(static fn (array $row): string => (string) $row['key'], $rows);
    }
}
