<?php

declare(strict_types=1);

namespace App\Database\Grammars;

use App\Database\Schema\Blueprint;
use App\Database\Schema\Column;

final class MySqlGrammar extends Grammar
{
    public function __construct(
        private readonly string $charset = 'utf8mb4',
        private readonly string $collation = 'utf8mb4_0900_ai_ci',
    ) {
    }

    public function wrap(string $identifier): string
    {
        return '`' . str_replace('`', '', $identifier) . '`';
    }

    public function typeFor(Column $column): string
    {
        return match ($column->type) {
            'bigint'   => 'BIGINT' . ($column->unsigned ? ' UNSIGNED' : ''),
            'integer'  => 'INT' . ($column->unsigned ? ' UNSIGNED' : ''),
            'smallint' => 'SMALLINT' . ($column->unsigned ? ' UNSIGNED' : ''),
            'tinyint'  => 'TINYINT' . ($column->unsigned ? ' UNSIGNED' : ''),
            'varchar'  => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'char'     => 'CHAR(' . ($column->length ?? 36) . ')',
            'text'     => 'TEXT',
            'longtext' => 'LONGTEXT',
            'json'     => 'JSON',
            'boolean'  => 'TINYINT(1)',
            'decimal'  => 'DECIMAL(' . ($column->precision ?? 14) . ',' . ($column->scale ?? 2) . ')',
            'datetime' => 'DATETIME',
            'date'     => 'DATE',
            'enum'     => 'ENUM(' . implode(', ', array_map(
                static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'",
                $column->allowed
            )) . ')',
            default => throw new \RuntimeException("Unknown column type [{$column->type}]."),
        };
    }

    /** @return array<int,string> */
    public function compileCreate(Blueprint $blueprint): array
    {
        $definitions = [];

        foreach ($blueprint->columns as $column) {
            $definitions[] = $this->columnDefinition($column);
        }

        if ($blueprint->primary !== []) {
            $definitions[] = 'PRIMARY KEY (' . implode(', ', array_map([$this, 'wrap'], $blueprint->primary)) . ')';
        }

        foreach ($blueprint->indexes as $index) {
            $definitions[] = ($index['type'] === 'unique' ? 'UNIQUE KEY ' : 'KEY ')
                . $this->wrap($index['name'])
                . ' (' . implode(', ', array_map([$this, 'wrap'], $index['columns'])) . ')';
        }

        foreach ($blueprint->foreignKeys as $foreign) {
            $definitions[] = 'CONSTRAINT ' . $this->wrap($foreign['name'])
                . ' FOREIGN KEY (' . implode(', ', array_map([$this, 'wrap'], $foreign['columns'])) . ')'
                . ' REFERENCES ' . $this->wrap($foreign['on']) . ' (' . $this->wrap($foreign['references']) . ')'
                . ' ON DELETE ' . strtoupper($foreign['onDelete'])
                . ' ON UPDATE ' . strtoupper($foreign['onUpdate']);
        }

        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->wrap($blueprint->table) . " (\n  "
            . implode(",\n  ", $definitions)
            . "\n) ENGINE=InnoDB DEFAULT CHARSET={$this->charset} COLLATE={$this->collation}";

        return [$sql];
    }

    private function columnDefinition(Column $column): string
    {
        $sql = $this->wrap($column->name) . ' ' . $this->typeFor($column);

        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';

        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->formatDefault($column->default);
        }

        if ($column->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }

        if ($column->comment !== null) {
            $sql .= " COMMENT '" . str_replace("'", "''", $column->comment) . "'";
        }

        return $sql;
    }
}
