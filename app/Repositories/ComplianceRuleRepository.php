<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Versioned compliance rules. Lookup prefers, in order:
 *   1. an organisation-specific override for the country
 *   2. the platform default for the country
 *   3. the platform wildcard default
 *
 * Only rules whose effective window covers "now" are considered, so a future
 * rule change can be staged ahead of time.
 */
final class ComplianceRuleRepository extends Repository
{
    protected function table(): string
    {
        return 'compliance_rules';
    }

    protected function isTenantScoped(): bool
    {
        return false;
    }

    /** @var array<string,array<string,mixed>|null> */
    private array $cache = [];

    /** @return array<string,mixed>|null */
    public function resolve(string $country, string $ruleCode, ?int $organisationId = null): ?array
    {
        $cacheKey = $country . '|' . $ruleCode . '|' . ($organisationId ?? 0);

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $today = $this->clock->now()->format('Y-m-d');

        $row = null;

        if ($organisationId !== null) {
            $row = $this->connection->selectOne(
                'SELECT * FROM compliance_rules
                 WHERE organisation_id = ? AND country = ? AND rule_code = ? AND is_active = 1
                   AND effective_from <= ? AND (effective_until IS NULL OR effective_until >= ?)
                 ORDER BY version DESC LIMIT 1',
                [$organisationId, $country, $ruleCode, $today, $today]
            );
        }

        $row ??= $this->connection->selectOne(
            'SELECT * FROM compliance_rules
             WHERE organisation_id IS NULL AND country = ? AND rule_code = ? AND is_active = 1
               AND effective_from <= ? AND (effective_until IS NULL OR effective_until >= ?)
             ORDER BY version DESC LIMIT 1',
            [$country, $ruleCode, $today, $today]
        );

        return $this->cache[$cacheKey] = $row;
    }

    /**
     * The active rule for a country, whatever its code, falling back to '*'.
     *
     * @return array<string,mixed>|null
     */
    public function forCountry(string $country, ?int $organisationId = null): ?array
    {
        $today = $this->clock->now()->format('Y-m-d');

        foreach ([$country, '*'] as $candidate) {
            if ($organisationId !== null) {
                $row = $this->connection->selectOne(
                    'SELECT * FROM compliance_rules
                     WHERE organisation_id = ? AND country = ? AND is_active = 1
                       AND effective_from <= ? AND (effective_until IS NULL OR effective_until >= ?)
                     ORDER BY version DESC LIMIT 1',
                    [$organisationId, $candidate, $today, $today]
                );

                if ($row !== null) {
                    return $row;
                }
            }

            $row = $this->connection->selectOne(
                'SELECT * FROM compliance_rules
                 WHERE organisation_id IS NULL AND country = ? AND is_active = 1
                   AND effective_from <= ? AND (effective_until IS NULL OR effective_until >= ?)
                 ORDER BY version DESC LIMIT 1',
                [$candidate, $today, $today]
            );

            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        return $this->unscoped()->insert($this->withTimestamps($attributes));
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->unscoped()->orderBy('country')->orderBy('rule_code')->orderBy('version', 'desc')->get();
    }

    public function exists(?int $organisationId, string $country, string $ruleCode, int $version): bool
    {
        $query = $this->unscoped()
            ->where('country', '=', $country)
            ->where('rule_code', '=', $ruleCode)
            ->where('version', '=', $version);

        $organisationId === null
            ? $query->whereNull('organisation_id')
            : $query->where('organisation_id', '=', $organisationId);

        return $query->exists();
    }
}
