<?php

declare(strict_types=1);

namespace App\Database\Schema;

use App\Database\Connection;
use App\Database\Grammars\Grammar;
use App\Database\Grammars\MySqlGrammar;
use App\Database\Grammars\SqliteGrammar;

final class Schema
{
    private Grammar $grammar;

    public function __construct(private readonly Connection $connection)
    {
        $this->grammar = match ($connection->driver()) {
            'sqlite' => new SqliteGrammar(),
            default  => new MySqlGrammar(),
        };
    }

    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        foreach ($this->grammar->compileCreate($blueprint) as $sql) {
            $this->connection->raw($sql);
        }
    }

    public function drop(string $table): void
    {
        $this->connection->raw($this->grammar->compileDrop($table));
    }

    public function hasTable(string $table): bool
    {
        if ($this->connection->driver() === 'sqlite') {
            return $this->connection->selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            ) !== null;
        }

        return $this->connection->selectOne(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        ) !== null;
    }

    public function hasColumn(string $table, string $column): bool
    {
        if ($this->connection->driver() === 'sqlite') {
            foreach ($this->connection->select('PRAGMA table_info(' . $this->grammar->wrap($table) . ')') as $row) {
                if (($row['name'] ?? null) === $column) {
                    return true;
                }
            }

            return false;
        }

        return $this->connection->selectOne(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        ) !== null;
    }

    /** Emit the whole schema as SQL text without executing it (for docs/review). */
    public function preview(string $table, callable $callback): string
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        return implode(";\n", $this->grammar->compileCreate($blueprint)) . ';';
    }

    public function grammar(): Grammar
    {
        return $this->grammar;
    }
}
