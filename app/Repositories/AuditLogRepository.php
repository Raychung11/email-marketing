<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\QueryBuilder;

/**
 * Security audit trail. Append-only by design: there is no update() or delete()
 * on this repository, and nothing in the application writes to the table any
 * other way.
 */
final class AuditLogRepository extends Repository
{
    protected function table(): string
    {
        return 'audit_logs';
    }

    protected function isTenantScoped(): bool
    {
        // Some audited actions happen before a tenant is bound (a login, a
        // failed login, an organisation being created), so the tenant key is
        // written explicitly by the caller instead.
        return false;
    }

    /** @param array<string,mixed> $attributes */
    public function record(array $attributes): int
    {
        foreach (['old_values', 'new_values'] as $jsonColumn) {
            if (isset($attributes[$jsonColumn]) && is_array($attributes[$jsonColumn])) {
                $attributes[$jsonColumn] = $this->encodeJson($attributes[$jsonColumn]);
            }
        }

        $attributes['created_at'] = $attributes['created_at'] ?? $this->now();

        return $this->unscoped()->insert($attributes);
    }

    /** @param array{action?:string,entity_type?:string,user_id?:int,from?:string,to?:string} $filters */
    public function forOrganisation(int $organisationId, array $filters = []): QueryBuilder
    {
        $query = $this->unscoped()->where('organisation_id', '=', $organisationId);

        if (($filters['action'] ?? '') !== '') {
            $query->where('action', '=', (string) $filters['action']);
        }

        if (($filters['entity_type'] ?? '') !== '') {
            $query->where('entity_type', '=', (string) $filters['entity_type']);
        }

        if ((int) ($filters['user_id'] ?? 0) > 0) {
            $query->where('user_id', '=', (int) $filters['user_id']);
        }

        if (($filters['from'] ?? '') !== '') {
            $query->where('created_at', '>=', (string) $filters['from']);
        }

        if (($filters['to'] ?? '') !== '') {
            $query->where('created_at', '<=', (string) $filters['to']);
        }

        return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
    }

    /** @return array<int,array<string,mixed>> */
    public function recentForEntity(string $entityType, int $entityId, int $limit = 25): array
    {
        return $this->unscoped()
            ->where('entity_type', '=', $entityType)
            ->where('entity_id', '=', $entityId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
