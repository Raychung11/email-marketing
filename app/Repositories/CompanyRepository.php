<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\QueryBuilder;

final class CompanyRepository extends Repository
{
    protected function table(): string
    {
        return 'companies';
    }

    protected function softDeletes(): bool
    {
        return true;
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $attributes['uuid'] = $attributes['uuid'] ?? uuid4();

        return $this->scoped()->insert($this->withTimestamps($this->withTenant($attributes)));
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

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->scoped()->where('name', '=', $name)->first();
    }

    public function firstOrCreate(string $name): int
    {
        $existing = $this->findByName($name);

        return $existing !== null ? (int) $existing['id'] : $this->create(['name' => $name]);
    }

    /** @param array{search?:string,country?:string} $filters */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->scoped();

        if (($filters['search'] ?? '') !== '') {
            $term = '%' . (string) $filters['search'] . '%';
            $query->whereGroup(static function (QueryBuilder $q) use ($term): void {
                $q->where('name', 'like', $term)->orWhere('domain', 'like', $term);
            });
        }

        if (($filters['country'] ?? '') !== '') {
            $query->where('country', '=', (string) $filters['country']);
        }

        return $query->orderBy('name');
    }

    /** @return array<int,array<string,mixed>> */
    public function contacts(int $companyId): array
    {
        return $this->connection->select(
            'SELECT id, uuid, email, first_name, last_name, job_title, customer_status
             FROM contacts WHERE organisation_id = ? AND company_id = ? AND deleted_at IS NULL
             ORDER BY last_name, first_name',
            [$this->organisationId(), $companyId]
        );
    }
}
