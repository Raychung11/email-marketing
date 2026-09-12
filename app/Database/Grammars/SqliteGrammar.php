<?php

declare(strict_types=1);

namespace App\Database\Grammars;

use App\Database\Schema\Blueprint;
use App\Database\Schema\Column;

/**
 * SQLite rendering of the same blueprints, used by the test suite so that
 * migrations, repositories and services are exercised against real SQL without
 * requiring a MySQL server. MySQL remains the canonical production target.
 */
final class SqliteGrammar extends Grammar
{
    public function wrap(string $identifier): string
    {
        return '"' . str_replace('"', '', $identifier) . '"';
    }

    public function typeFor(Column $column): string
    {
        return match ($column->type) {
            'bigint', 'integer', 'smallint', 'tinyint' => 'INTEGER',
            'varchar', 'char', 'text', 'longtext', 'json', 'enum' => 'TEXT',
            'boolean'  => 'INTEGER',
            'decimal'  => 'NUMERIC',
            'datetime' => 'TEXT',
            'date'     => 'TEXT',
            default    => throw new \RuntimeException("Unknown column type [{$column->type}]."),
        };
    }

    /** @return array<int,string> */
    public function compileCreate(Blueprint $blueprint): array
    {
        $definitions   = [];
        $singleAutoInc = null;

        foreach ($blueprint->columns as $column) {
            if ($column->autoIncrement) {
                $singleAutoInc = $column->name;
            }
        }

        foreach ($blueprint->columns as $column) {
            $sql = $this->wrap($column->name) . ' ' . $this->typeFor($column);

            if ($column->name === $singleAutoInc) {
                // SQLite requires exactly this spelling for a rowid alias.
                $sql .= ' PRIMARY KEY AUTOINCREMENT';
            } else {
                $sql .= $column->nullable ? ' NULL' : ' NOT NULL';
            }

            if ($column->hasDefault) {
                $sql .= ' DEFAULT ' . $this->formatDefault($column->default);
            }

            // ENUM has no SQLite equivalent; emulate it with a CHECK so tests
            // catch invalid values exactly as MySQL strict mode would.
            if ($column->type === 'enum' && $column->allowed !== []) {
                $values = implode(', ', array_map(
                    static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'",
                    $column->allowed
                ));
                $sql .= ' CHECK (' . $this->wrap($column->name) . ' IN (' . $values . '))';
            }

            $definitions[] = $sql;
        }

        if ($singleAutoInc === null && $blueprint->primary !== []) {
            $definitions[] = 'PRIMARY KEY (' . implode(', ', array_map([$this, 'wrap'], $blueprint->primary)) . ')';
        }

        foreach ($blueprint->foreignKeys as $foreign) {
            $definitions[] = 'FOREIGN KEY (' . implode(', ', array_map([$this, 'wrap'], $foreign['columns'])) . ')'
                . ' REFERENCES ' . $this->wrap($foreign['on']) . ' (' . $this->wrap($foreign['references']) . ')'
                . ' ON DELETE ' . strtoupper($foreign['onDelete'])
                . ' ON UPDATE ' . strtoupper($foreign['onUpdate']);
        }

        $statements = [
            'CREATE TABLE IF NOT EXISTS ' . $this->wrap($blueprint->table) . " (\n  "
            . implode(",\n  ", $definitions) . "\n)",
        ];

        // SQLite declares indexes outside the CREATE TABLE statement.
        foreach ($blueprint->indexes as $index) {
            $statements[] = 'CREATE ' . ($index['type'] === 'unique' ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS '
                . $this->wrap($index['name']) . ' ON ' . $this->wrap($blueprint->table)
                . ' (' . implode(', ', array_map([$this, 'wrap'], $index['columns'])) . ')';
        }

        return $statements;
    }
}
