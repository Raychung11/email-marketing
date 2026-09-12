<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Database\Connection;
use App\Database\QueryBuilder;
use App\Support\TenantContext;

/**
 * Base for every tenant-scoped repository.
 *
 * The important detail is {@see scoped()}: it is the only way subclasses build
 * a query, and it always applies organisation_id from TenantContext. Callers
 * cannot pass an organisation id, so they cannot pass the wrong one.
 */
abstract class Repository
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly TenantContext $tenant,
        protected readonly Clock $clock,
    ) {
    }

    abstract protected function table(): string;

    /** Whether this table carries an organisation_id column. */
    protected function isTenantScoped(): bool
    {
        return true;
    }

    /**
     * Whether this table uses deleted_at.
     *
     * When it does, scoped() excludes soft-deleted rows automatically, so a
     * deleted contact cannot reappear in a list, a segment or an API response
     * just because one call site forgot the filter.
     */
    protected function softDeletes(): bool
    {
        return false;
    }

    protected function scoped(): QueryBuilder
    {
        $query = $this->connection->table($this->table());

        if ($this->isTenantScoped()) {
            $query->where('organisation_id', '=', $this->tenant->organisationId());
        }

        if ($this->softDeletes()) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    /**
     * Tenant-scoped, but including soft-deleted rows. Used by the few operations
     * that must still see a deleted record — merge history, compliance evidence,
     * and the privacy erasure path itself.
     */
    protected function scopedWithTrashed(): QueryBuilder
    {
        $query = $this->connection->table($this->table());

        if ($this->isTenantScoped()) {
            $query->where('organisation_id', '=', $this->tenant->organisationId());
        }

        return $query;
    }

    /**
     * Deliberately explicit escape hatch for the few places that legitimately
     * work outside a bound tenant (login, organisation creation, the scheduler
     * scanning across tenants). Every call site is expected to be reviewable.
     */
    protected function unscoped(): QueryBuilder
    {
        return $this->connection->table($this->table());
    }

    protected function organisationId(): int
    {
        return $this->tenant->organisationId();
    }

    protected function now(): string
    {
        return $this->clock->nowString();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->scoped()->where('id', '=', $id)->first();
    }

    /** @return array<string,mixed> */
    public function findOrFail(int $id): array
    {
        $row = $this->find($id);

        if ($row === null) {
            // 404 rather than 403 on purpose: a cross-tenant id must not be
            // distinguishable from a non-existent one, or the response itself
            // becomes an enumeration oracle.
            throw \App\Core\HttpException::notFound();
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->scoped()->where('uuid', '=', $uuid)->first();
    }

    public function count(): int
    {
        return $this->scoped()->count();
    }

    /** @param array<string,mixed> $attributes */
    protected function withTenant(array $attributes): array
    {
        if ($this->isTenantScoped()) {
            $attributes['organisation_id'] = $this->tenant->organisationId();
        }

        return $attributes;
    }

    /** @param array<string,mixed> $attributes */
    protected function withTimestamps(array $attributes, bool $creating = true): array
    {
        $now = $this->now();

        if ($creating) {
            $attributes['created_at'] = $attributes['created_at'] ?? $now;
        }

        $attributes['updated_at'] = $now;

        return $attributes;
    }

    protected function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    /** @return array<mixed>|null */
    protected function decodeJson(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
