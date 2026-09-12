<?php

declare(strict_types=1);

namespace App\Database;

use Generator;

/**
 * Fluent query builder.
 *
 * Identifiers (table, column) are whitelisted against a strict pattern and
 * quoted; values are always bound. There is no code path that interpolates a
 * user-supplied value into SQL.
 */
final class QueryBuilder
{
    /** @var array<int,string> */
    private array $columns = ['*'];

    /** @var array<int,array{type:string,boolean:string,sql?:string,bindings?:array<int,mixed>,nested?:QueryBuilder}> */
    private array $wheres = [];

    /** @var array<int,array{table:string,first:string,operator:string,second:string,type:string}> */
    private array $joins = [];

    /** @var array<int,string> */
    private array $groups = [];

    /** @var array<int,array{column:string,direction:string}> */
    private array $orders = [];

    /** @var array<int,array{sql:string,bindings:array<int,mixed>}> */
    private array $havings = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $distinct = false;

    private const OPERATORS = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'like', 'not like', 'in', 'not in', 'is null', 'is not null', 'between',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly string $alias = '',
    ) {
        $this->assertIdentifier($table);
    }

    public function select(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->assertColumnExpression($column);
        }

        $this->columns = $columns === [] ? ['*'] : $columns;

        return $this;
    }

    public function addSelect(string $column): self
    {
        $this->assertColumnExpression($column);

        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $this->columns[] = $column;

        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    public function where(string $column, string $operator, mixed $value = null, string $boolean = 'and'): self
    {
        // where('col', $value) shorthand
        if ($value === null && !in_array(strtolower($operator), ['is null', 'is not null'], true)) {
            $value    = $operator;
            $operator = '=';
        }

        $this->assertColumnExpression($column);
        $operator = $this->assertOperator($operator);

        $this->wheres[] = [
            'type'     => 'basic',
            'boolean'  => $boolean,
            'sql'      => $this->wrap($column) . ' ' . strtoupper($operator) . ' ?',
            'bindings' => [$value],
        ];

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value = null): self
    {
        return $this->where($column, $operator, $value, 'or');
    }

    /** @param array<int,mixed> $values */
    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        $this->assertColumnExpression($column);

        if ($values === []) {
            // An empty IN () is always false (or always true when negated).
            $this->wheres[] = [
                'type'     => 'basic',
                'boolean'  => $boolean,
                'sql'      => $not ? '1 = 1' : '1 = 0',
                'bindings' => [],
            ];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->wheres[] = [
            'type'     => 'basic',
            'boolean'  => $boolean,
            'sql'      => $this->wrap($column) . ($not ? ' NOT IN (' : ' IN (') . $placeholders . ')',
            'bindings' => array_values($values),
        ];

        return $this;
    }

    /** @param array<int,mixed> $values */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function whereNull(string $column, string $boolean = 'and', bool $not = false): self
    {
        $this->assertColumnExpression($column);

        $this->wheres[] = [
            'type'     => 'basic',
            'boolean'  => $boolean,
            'sql'      => $this->wrap($column) . ($not ? ' IS NOT NULL' : ' IS NULL'),
            'bindings' => [],
        ];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'and'): self
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'and'): self
    {
        $this->assertColumnExpression($column);

        $this->wheres[] = [
            'type'     => 'basic',
            'boolean'  => $boolean,
            'sql'      => $this->wrap($column) . ' BETWEEN ? AND ?',
            'bindings' => [$from, $to],
        ];

        return $this;
    }

    /**
     * Nested group: ->whereGroup(fn ($q) => $q->where(...)->orWhere(...))
     *
     * This is what the segment compiler uses to build arbitrarily nested
     * AND/OR trees without ever writing SQL strings itself.
     */
    public function whereGroup(callable $callback, string $boolean = 'and'): self
    {
        $nested = new self($this->connection, $this->table, $this->alias);
        $callback($nested);

        if ($nested->wheres !== []) {
            $this->wheres[] = [
                'type'    => 'nested',
                'boolean' => $boolean,
                'nested'  => $nested,
            ];
        }

        return $this;
    }

    public function orWhereGroup(callable $callback): self
    {
        return $this->whereGroup($callback, 'or');
    }

    /**
     * Correlated EXISTS subquery against another table, used by tag/list/
     * engagement segment rules so they do not need joins that multiply rows.
     *
     * @param array<int,mixed> $bindings
     */
    public function whereExistsRaw(string $sql, array $bindings = [], string $boolean = 'and', bool $not = false): self
    {
        $this->wheres[] = [
            'type'     => 'basic',
            'boolean'  => $boolean,
            'sql'      => ($not ? 'NOT EXISTS (' : 'EXISTS (') . $sql . ')',
            'bindings' => $bindings,
        ];

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): self
    {
        $this->assertIdentifier($table);
        $this->assertColumnExpression($first);
        $this->assertColumnExpression($second);

        $this->joins[] = [
            'table'    => $table,
            'first'    => $first,
            'operator' => $this->assertOperator($operator),
            'second'   => $second,
            'type'     => in_array($type, ['inner', 'left', 'right'], true) ? $type : 'inner',
        ];

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->assertColumnExpression($column);
            $this->groups[] = $column;
        }

        return $this;
    }

    /** @param array<int,mixed> $bindings */
    public function havingRaw(string $sql, array $bindings = []): self
    {
        $this->havings[] = ['sql' => $sql, 'bindings' => $bindings];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->assertColumnExpression($column);

        $this->orders[] = [
            'column'    => $column,
            'direction' => strtolower($direction) === 'desc' ? 'DESC' : 'ASC',
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function forPage(int $page, int $perPage): self
    {
        return $this->limit($perPage)->offset(max(0, ($page - 1) * $perPage));
    }

    // ---------------------------------------------------------------- reads

    /** @return array<int,array<string,mixed>> */
    public function get(): array
    {
        return $this->connection->select($this->toSql(), $this->bindings());
    }

    /** @return array<string,mixed>|null */
    public function first(): ?array
    {
        $clone = clone $this;
        $clone->limit(1);

        return $this->connection->selectOne($clone->toSql(), $clone->bindings());
    }

    public function value(string $column): mixed
    {
        $clone = clone $this;
        $clone->select($column)->limit(1);

        return $this->connection->scalar($clone->toSql(), $clone->bindings());
    }

    /** @return array<int,mixed> */
    public function pluck(string $column): array
    {
        $clone = clone $this;
        $clone->select($column);

        return array_map(
            static fn (array $row): mixed => reset($row),
            $this->connection->select($clone->toSql(), $clone->bindings())
        );
    }

    public function count(string $column = '*'): int
    {
        $clone          = clone $this;
        $clone->orders  = [];
        $clone->columns = ['COUNT(' . ($column === '*' ? '*' : $this->wrap($column)) . ') AS aggregate'];
        $clone->limit   = null;
        $clone->offset  = null;

        return (int) $this->connection->scalar($clone->toSql(), $clone->bindings());
    }

    public function sum(string $column): float
    {
        $clone          = clone $this;
        $clone->orders  = [];
        $clone->columns = ['COALESCE(SUM(' . $this->wrap($column) . '), 0) AS aggregate'];

        return (float) $this->connection->scalar($clone->toSql(), $clone->bindings());
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /** @return Generator<int,array<string,mixed>> */
    public function cursor(): Generator
    {
        yield from $this->connection->cursor($this->toSql(), $this->bindings());
    }

    /**
     * Keyset-paginated chunking. Uses a monotonically increasing key column
     * rather than OFFSET so the cost does not grow with depth on large tables.
     *
     * @param callable(array<int,array<string,mixed>>):void $callback
     */
    public function chunkById(int $size, callable $callback, string $keyColumn = 'id'): void
    {
        $lastId = 0;

        do {
            $clone = clone $this;
            $clone->where($keyColumn, '>', $lastId)
                ->orderBy($keyColumn)
                ->limit($size);

            $rows = $clone->get();

            if ($rows === []) {
                return;
            }

            $callback($rows);

            $last   = $rows[array_key_last($rows)];
            $lastId = (int) ($last[$keyColumn] ?? 0);
        } while (count($rows) === $size);
    }

    // --------------------------------------------------------------- writes

    /** @param array<string,mixed> $values */
    public function insert(array $values): int
    {
        $columns      = array_keys($values);
        array_map([$this, 'assertIdentifier'], $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = 'INSERT INTO ' . $this->wrap($this->table)
            . ' (' . implode(', ', array_map([$this, 'wrap'], $columns)) . ')'
            . ' VALUES (' . $placeholders . ')';

        $this->connection->execute($sql, array_values($values));

        return $this->connection->lastInsertId();
    }

    /**
     * Batched multi-row insert.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function insertMany(array $rows, int $batchSize = 500): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        array_map([$this, 'assertIdentifier'], $columns);

        $inserted = 0;

        foreach (array_chunk($rows, $batchSize) as $batch) {
            $bindings = [];
            $tuples   = [];

            foreach ($batch as $row) {
                $tuples[] = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

                foreach ($columns as $column) {
                    $bindings[] = $row[$column] ?? null;
                }
            }

            $sql = 'INSERT INTO ' . $this->wrap($this->table)
                . ' (' . implode(', ', array_map([$this, 'wrap'], $columns)) . ')'
                . ' VALUES ' . implode(', ', $tuples);

            $inserted += $this->connection->execute($sql, $bindings);
        }

        return $inserted;
    }

    /** @param array<string,mixed> $values */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $assignments = [];
        $bindings    = [];

        foreach ($values as $column => $value) {
            $this->assertIdentifier($column);
            $assignments[] = $this->wrap($column) . ' = ?';
            $bindings[]    = $value;
        }

        [$whereSql, $whereBindings] = $this->compileWheres();

        if ($whereSql === '') {
            // Refuse a table-wide UPDATE: in a multi-tenant schema that is
            // always a bug, never an intention.
            throw new \RuntimeException('Refusing to run an unfiltered UPDATE on ' . $this->table . '.');
        }

        $sql = 'UPDATE ' . $this->wrap($this->table) . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $whereSql;

        return $this->connection->execute($sql, array_merge($bindings, $whereBindings));
    }

    public function delete(): int
    {
        [$whereSql, $bindings] = $this->compileWheres();

        if ($whereSql === '') {
            throw new \RuntimeException('Refusing to run an unfiltered DELETE on ' . $this->table . '.');
        }

        return $this->connection->execute(
            'DELETE FROM ' . $this->wrap($this->table) . ' WHERE ' . $whereSql,
            $bindings
        );
    }

    // -------------------------------------------------------------- compile

    public function toSql(): string
    {
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '')
            . implode(', ', array_map([$this, 'wrapSelect'], $this->columns))
            . ' FROM ' . $this->wrap($this->table)
            . ($this->alias !== '' ? ' AS ' . $this->wrap($this->alias) : '');

        foreach ($this->joins as $join) {
            $sql .= ' ' . strtoupper($join['type']) . ' JOIN ' . $this->wrap($join['table'])
                . ' ON ' . $this->wrap($join['first']) . ' ' . $join['operator'] . ' ' . $this->wrap($join['second']);
        }

        [$whereSql] = $this->compileWheres();

        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map([$this, 'wrap'], $this->groups));
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', array_column($this->havings, 'sql'));
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(
                fn (array $order): string => $this->wrap($order['column']) . ' ' . $order['direction'],
                $this->orders
            ));
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ($this->limit === null ? ' LIMIT -1' : '') . ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /** @return array<int,mixed> */
    public function bindings(): array
    {
        [, $whereBindings] = $this->compileWheres();

        $havingBindings = [];

        foreach ($this->havings as $having) {
            $havingBindings = array_merge($havingBindings, $having['bindings']);
        }

        return array_merge($whereBindings, $havingBindings);
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function compileWheres(): array
    {
        $sql      = '';
        $bindings = [];

        foreach ($this->wheres as $index => $where) {
            $prefix = $index === 0 ? '' : strtoupper($where['boolean']) . ' ';

            if ($where['type'] === 'nested') {
                /** @var self $nested */
                $nested                   = $where['nested'];
                [$nestedSql, $nestedBind] = $nested->compileWheres();

                if ($nestedSql === '') {
                    continue;
                }

                $sql     .= $prefix . '(' . $nestedSql . ') ';
                $bindings = array_merge($bindings, $nestedBind);
                continue;
            }

            $sql     .= $prefix . $where['sql'] . ' ';
            $bindings = array_merge($bindings, $where['bindings'] ?? []);
        }

        return [trim($sql), $bindings];
    }

    private function wrapSelect(string $column): string
    {
        if ($column === '*') {
            return '*';
        }

        // Allow "COUNT(x) AS y" style expressions produced internally only.
        if (str_contains($column, '(') || stripos($column, ' as ') !== false) {
            return $column;
        }

        return $this->wrap($column);
    }

    private function wrap(string $identifier): string
    {
        if ($identifier === '*') {
            return '*';
        }

        $quote = $this->connection->driver() === 'mysql' ? '`' : '"';

        return implode('.', array_map(
            static fn (string $part): string => $part === '*' ? '*' : $quote . $part . $quote,
            explode('.', $identifier)
        ));
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException("Invalid SQL identifier [{$identifier}].");
        }
    }

    private function assertColumnExpression(string $column): void
    {
        if ($column === '*') {
            return;
        }

        // table.column or column, optionally with an internal aggregate.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_*]*)?$/', $column) === 1) {
            return;
        }

        if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX|COALESCE)\(/i', $column) === 1) {
            return;
        }

        throw new \InvalidArgumentException("Invalid column expression [{$column}].");
    }

    private function assertOperator(string $operator): string
    {
        $normalised = strtolower(trim($operator));

        if (!in_array($normalised, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Unsupported SQL operator [{$operator}].");
        }

        return $normalised;
    }
}
