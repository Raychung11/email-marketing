<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\ValidationException;
use App\Database\Connection;
use App\Database\QueryBuilder;
use App\Support\TenantContext;
use InvalidArgumentException;

/**
 * Compiles a segment definition into a bound SQL query.
 *
 * A definition is a nested tree:
 *
 *   {
 *     "match": "all",
 *     "rules": [
 *       {"field": "country", "operator": "equals", "value": "AU"},
 *       {"field": "last_purchase", "operator": "before", "value": "now-180days"},
 *       {"match": "any", "rules": [
 *         {"field": "tag", "operator": "in", "value": [4]},
 *         {"field": "customer_value", "operator": "greater_than", "value": 1000}
 *       ]}
 *     ]
 *   }
 *
 * THE SAFETY PROPERTY: field keys and operators are looked up in
 * config/segments.php. Anything not in that registry is rejected before any SQL
 * is produced, and every value is bound. That is what makes it safe to accept a
 * definition from a browser POST or from the AI layer — the payload is data, and
 * the whitelist is the trust boundary.
 */
final class SegmentCompiler
{
    /** @var array<string,array<string,mixed>> */
    private array $fields;

    /** @var array<string,string> */
    private array $operators;

    /** @var array<string,array<int,string>> */
    private array $operatorsByType;

    public function __construct(
        private readonly Connection $connection,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
        /** @var array<string,array<string,mixed>> $fields */
        $fields          = $this->config->get('segments.fields', []);
        $this->fields    = $fields;
        /** @var array<string,string> $operators */
        $operators       = $this->config->get('segments.operators', []);
        $this->operators = $operators;
        /** @var array<string,array<int,string>> $byType */
        $byType                = $this->config->get('segments.operators_by_type', []);
        $this->operatorsByType = $byType;
    }

    /**
     * Validate a definition and normalise it for storage.
     *
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function validate(array $definition): array
    {
        $errors = [];
        $count  = 0;

        $normalised = $this->validateNode($definition, 0, $errors, $count);

        $maxRules = (int) $this->config->get('segments.max_rules', 50);

        if ($count > $maxRules) {
            $errors['rules'][] = "A segment may contain at most {$maxRules} conditions.";
        }

        if ($count === 0) {
            $errors['rules'][] = 'A segment needs at least one condition.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $normalised;
    }

    /**
     * @param array<string,mixed>                 $node
     * @param array<string,array<int,string>>     $errors
     * @return array<string,mixed>
     */
    private function validateNode(array $node, int $depth, array &$errors, int &$count): array
    {
        $maxDepth = (int) $this->config->get('segments.max_nesting_depth', 5);

        if ($depth > $maxDepth) {
            $errors['rules'][] = "Segment conditions may not be nested more than {$maxDepth} levels deep.";

            return ['match' => 'all', 'rules' => []];
        }

        // Group node
        if (isset($node['rules']) && is_array($node['rules'])) {
            $match = (string) ($node['match'] ?? 'all');

            if (!in_array($match, ['all', 'any'], true)) {
                $errors['match'][] = 'Match type must be "all" or "any".';
                $match             = 'all';
            }

            $children = [];

            foreach ($node['rules'] as $child) {
                if (is_array($child)) {
                    $children[] = $this->validateNode($child, $depth + 1, $errors, $count);
                }
            }

            return ['match' => $match, 'rules' => $children];
        }

        // Condition node
        $count++;

        $fieldKey = (string) ($node['field'] ?? '');
        $operator = (string) ($node['operator'] ?? '');

        if (!isset($this->fields[$fieldKey])) {
            $errors['field'][] = "Unknown segment field \"{$fieldKey}\".";

            return ['field' => $fieldKey, 'operator' => $operator, 'value' => null];
        }

        $field = $this->fields[$fieldKey];
        $type  = (string) $field['type'];

        if (!isset($this->operators[$operator])) {
            $errors['operator'][] = "Unknown operator \"{$operator}\".";
        } elseif (isset($this->operatorsByType[$type]) && !in_array($operator, $this->operatorsByType[$type], true)) {
            $errors['operator'][] = "Operator \"{$operator}\" cannot be used with field \"{$fieldKey}\".";
        }

        $needsValue = !in_array($operator, ['exists', 'not_exists'], true);
        $value      = $node['value'] ?? null;

        if ($needsValue && ($value === null || $value === '' || $value === [])) {
            $errors['value'][] = "A value is required for \"{$fieldKey}\".";
        }

        if ($operator === 'between' && ($node['value2'] ?? null) === null) {
            $errors['value2'][] = "A second value is required for a \"between\" condition on \"{$fieldKey}\".";
        }

        $normalised = [
            'field'    => $fieldKey,
            'operator' => $operator,
            'value'    => $value,
        ];

        if (array_key_exists('value2', $node)) {
            $normalised['value2'] = $node['value2'];
        }

        if ($fieldKey === 'custom_field') {
            $normalised['custom_field_key'] = (string) ($node['custom_field_key'] ?? '');

            if ($normalised['custom_field_key'] === '') {
                $errors['custom_field_key'][] = 'A custom field must be selected.';
            }
        }

        return $normalised;
    }

    /**
     * Build the contacts query for a validated definition.
     *
     * @param array<string,mixed> $definition
     */
    public function compile(array $definition): QueryBuilder
    {
        $query = $this->connection->table('contacts')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->whereNull('deleted_at');

        $this->applyNode($query, $definition, 'and');

        return $query;
    }

    /** @param array<string,mixed> $node */
    private function applyNode(QueryBuilder $query, array $node, string $boolean): void
    {
        if (isset($node['rules']) && is_array($node['rules'])) {
            $childBoolean = (string) ($node['match'] ?? 'all') === 'any' ? 'or' : 'and';

            $query->whereGroup(function (QueryBuilder $group) use ($node, $childBoolean): void {
                foreach ($node['rules'] as $index => $child) {
                    if (!is_array($child)) {
                        continue;
                    }

                    $this->applyNode($group, $child, $index === 0 ? 'and' : $childBoolean);
                }
            }, $boolean);

            return;
        }

        $this->applyCondition($query, $node, $boolean);
    }

    /** @param array<string,mixed> $condition */
    private function applyCondition(QueryBuilder $query, array $condition, string $boolean): void
    {
        $fieldKey = (string) ($condition['field'] ?? '');
        $operator = (string) ($condition['operator'] ?? '');

        if (!isset($this->fields[$fieldKey]) || !isset($this->operators[$operator])) {
            // validate() rejects these; reaching here means a stored definition
            // predates a config change. Fail closed rather than widening the
            // audience by silently dropping the condition.
            throw new InvalidArgumentException(
                "Segment references an unavailable field or operator ({$fieldKey}/{$operator})."
            );
        }

        $field = $this->fields[$fieldKey];

        if (isset($field['special'])) {
            $this->applySpecial($query, (string) $field['special'], $condition, $boolean);

            return;
        }

        if (($field['type'] ?? '') === 'relation') {
            $this->applyRelation($query, $field, $condition, $boolean);

            return;
        }

        $column   = (string) $field['column'];
        $type     = (string) $field['type'];
        $sqlOp    = $this->operators[$operator];
        $value    = $condition['value'] ?? null;

        switch ($operator) {
            case 'exists':
                $query->whereNotNull($column, $boolean);

                return;

            case 'not_exists':
                $query->whereNull($column, $boolean);

                return;

            case 'contains':
                $query->where($column, 'like', '%' . $this->escapeLike((string) $value) . '%', $boolean);

                return;

            case 'not_contains':
                $query->where($column, 'not like', '%' . $this->escapeLike((string) $value) . '%', $boolean);

                return;

            case 'in':
                $query->whereIn($column, $this->toList($value), $boolean);

                return;

            case 'not_in':
                $query->whereNotIn($column, $this->toList($value), $boolean);

                return;

            case 'between':
                $query->whereBetween(
                    $column,
                    $this->castValue($type, $value),
                    $this->castValue($type, $condition['value2'] ?? null),
                    $boolean
                );

                return;

            default:
                $query->where($column, $sqlOp, $this->castValue($type, $value), $boolean);
        }
    }

    /**
     * Tag and list membership, resolved with a correlated EXISTS rather than a
     * join — a join against a many-to-many would multiply rows and corrupt both
     * the count and any aggregate.
     *
     * @param array<string,mixed> $field
     * @param array<string,mixed> $condition
     */
    private function applyRelation(QueryBuilder $query, array $field, array $condition, string $boolean): void
    {
        /** @var array<string,string> $relation */
        $relation = $field['relation'];
        $operator = (string) $condition['operator'];
        $ids      = array_values(array_filter(array_map('intval', $this->toList($condition['value'] ?? []))));

        $table   = $relation['table'];
        $foreign = $relation['foreign'];
        $match   = $relation['match'];

        // Identifiers come from config, never from the request.
        $this->assertIdentifier($table);
        $this->assertIdentifier($foreign);
        $this->assertIdentifier($match);

        if (in_array($operator, ['exists', 'not_exists'], true)) {
            $sql = "SELECT 1 FROM {$table} r WHERE r.{$foreign} = contacts.id AND r.organisation_id = ?";

            $query->whereExistsRaw(
                $sql,
                [$this->tenant->organisationId()],
                $boolean,
                $operator === 'not_exists'
            );

            return;
        }

        if ($ids === []) {
            // An empty set must not silently match everyone.
            $query->whereIn('contacts.id', [], $boolean, $operator === 'not_in');

            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql          = "SELECT 1 FROM {$table} r
             WHERE r.{$foreign} = contacts.id
               AND r.organisation_id = ?
               AND r.{$match} IN ({$placeholders})";

        $query->whereExistsRaw(
            $sql,
            array_merge([$this->tenant->organisationId()], $ids),
            $boolean,
            $operator === 'not_in'
        );
    }

    /**
     * Fields that are not plain columns: live consent state, live suppression
     * state and typed custom field values.
     *
     * Note that consent and suppression here read the AUTHORITATIVE tables, not
     * the denormalised mirrors on contacts, so a segment preview cannot disagree
     * with what the send path will decide.
     *
     * @param array<string,mixed> $condition
     */
    private function applySpecial(QueryBuilder $query, string $special, array $condition, string $boolean): void
    {
        $organisationId = $this->tenant->organisationId();

        switch ($special) {
            case 'marketing_consent':
                $wants = $this->toBool($condition['value'] ?? true);

                $sql = "SELECT 1 FROM contact_consents cc
                        WHERE cc.contact_id = contacts.id
                          AND cc.organisation_id = ?
                          AND cc.channel = 'email'
                          AND cc.status = 'granted'
                          AND cc.id = (
                              SELECT MAX(cc2.id) FROM contact_consents cc2
                              WHERE cc2.contact_id = contacts.id
                                AND cc2.organisation_id = cc.organisation_id
                                AND cc2.channel = 'email'
                          )";

                $query->whereExistsRaw($sql, [$organisationId], $boolean, !$wants);

                return;

            case 'suppressed':
                $wants = $this->toBool($condition['value'] ?? true);

                $sql = 'SELECT 1 FROM suppressions s
                        WHERE s.organisation_id = ?
                          AND s.email_normalized = contacts.email_normalized
                          AND s.removed_at IS NULL';

                $query->whereExistsRaw($sql, [$organisationId], $boolean, !$wants);

                return;

            case 'custom_field':
                $this->applyCustomField($query, $condition, $boolean);

                return;
        }

        throw new InvalidArgumentException("Unsupported special segment field [{$special}].");
    }

    /** @param array<string,mixed> $condition */
    private function applyCustomField(QueryBuilder $query, array $condition, string $boolean): void
    {
        $key = (string) ($condition['custom_field_key'] ?? '');

        if ($key === '') {
            throw new InvalidArgumentException('A custom field condition requires a field key.');
        }

        $definition = $this->connection->table('custom_field_definitions')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('entity_type', '=', 'contact')
            ->where('key', '=', $key)
            ->first();

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown custom field [{$key}].");
        }

        $type     = (string) $definition['type'];
        $operator = (string) $condition['operator'];
        $value    = $condition['value'] ?? null;

        // Compare in the typed column so numbers and dates order correctly.
        $column = match ($type) {
            'number'  => 'value_number',
            'date'    => 'value_date',
            'boolean' => 'value_boolean',
            default   => 'value_text',
        };

        $bindings = [$this->tenant->organisationId(), (int) $definition['id']];
        $clause   = '';

        switch ($operator) {
            case 'exists':
                $clause = " AND v.{$column} IS NOT NULL";
                break;

            case 'not_exists':
                $clause = " AND v.{$column} IS NULL";
                break;

            case 'contains':
                $clause     = " AND v.{$column} LIKE ?";
                $bindings[] = '%' . $this->escapeLike((string) $value) . '%';
                break;

            case 'in':
                $list = $this->toList($value);

                if ($list === []) {
                    $query->whereIn('contacts.id', [], $boolean);

                    return;
                }

                $clause   = " AND v.{$column} IN (" . implode(', ', array_fill(0, count($list), '?')) . ')';
                $bindings = array_merge($bindings, $list);
                break;

            case 'between':
                $clause     = " AND v.{$column} BETWEEN ? AND ?";
                $bindings[] = $this->castValue($type === 'number' ? 'number' : 'date', $value);
                $bindings[] = $this->castValue($type === 'number' ? 'number' : 'date', $condition['value2'] ?? null);
                break;

            default:
                $clause     = ' AND v.' . $column . ' ' . $this->operators[$operator] . ' ?';
                $bindings[] = $this->castValue($type, $value);
        }

        $sql = 'SELECT 1 FROM contact_custom_fields v
                WHERE v.contact_id = contacts.id
                  AND v.organisation_id = ?
                  AND v.custom_field_definition_id = ?' . $clause;

        $query->whereExistsRaw($sql, $bindings, $boolean, $operator === 'not_contains' || $operator === 'not_in');
    }

    /**
     * Relative dates are stored as an expression ("now-180days") rather than an
     * absolute timestamp, so a saved segment means "inactive for 180 days" for
     * ever rather than "inactive since the day I created this".
     */
    private function castValue(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'number', 'money' => is_numeric($value) ? (float) $value : 0.0,
            'date'            => $this->resolveDate((string) $value),
            'boolean'         => $this->toBool($value) ? 1 : 0,
            default           => is_scalar($value) ? (string) $value : '',
        };
    }

    public function resolveDate(string $expression): string
    {
        $expression = trim($expression);

        if (preg_match('/^now\s*([+-])\s*(\d+)\s*(day|days|week|weeks|month|months|year|years)$/i', $expression, $m) === 1) {
            $sign   = $m[1];
            $amount = (int) $m[2];
            $unit   = strtolower($m[3]);

            return $this->clock->now()->modify("{$sign}{$amount} {$unit}")->format('Y-m-d H:i:s');
        }

        if (strtolower($expression) === 'now' || strtolower($expression) === 'today') {
            return $this->clock->nowString();
        }

        $timestamp = strtotime($expression);

        return $timestamp === false ? $this->clock->nowString() : date('Y-m-d H:i:s', $timestamp);
    }

    /** @return array<int,mixed> */
    private function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (is_string($value) && $value !== '') {
            return array_map('trim', explode(',', $value));
        }

        return $value === null ? [] : [$value];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Invalid identifier in segment field registry [{$identifier}].");
        }
    }

    /**
     * Human-readable rendering of a definition, for the UI and for audit records.
     *
     * @param array<string,mixed> $definition
     */
    public function describe(array $definition, int $depth = 0): string
    {
        if (isset($definition['rules']) && is_array($definition['rules'])) {
            $joiner = (string) ($definition['match'] ?? 'all') === 'any' ? ' OR ' : ' AND ';

            $parts = [];

            foreach ($definition['rules'] as $child) {
                if (is_array($child)) {
                    $parts[] = $this->describe($child, $depth + 1);
                }
            }

            $joined = implode($joiner, array_filter($parts));

            return $depth > 0 && count($parts) > 1 ? '(' . $joined . ')' : $joined;
        }

        $fieldKey = (string) ($definition['field'] ?? '');
        $label    = (string) ($this->fields[$fieldKey]['label'] ?? $fieldKey);
        $operator = str_replace('_', ' ', (string) ($definition['operator'] ?? ''));
        $value    = $definition['value'] ?? null;

        $rendered = is_array($value) ? implode(', ', array_map(static fn ($v): string => (string) $v, $value)) : (string) $value;

        if (isset($definition['value2'])) {
            $rendered .= ' and ' . (string) $definition['value2'];
        }

        return trim($label . ' ' . $operator . ' ' . $rendered);
    }

    /** @return array<string,array<string,mixed>> */
    public function availableFields(): array
    {
        return $this->fields;
    }

    /** @return array<string,array<int,string>> */
    public function operatorsForTypes(): array
    {
        return $this->operatorsByType;
    }
}
