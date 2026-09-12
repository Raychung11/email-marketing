<?php

declare(strict_types=1);

namespace App\Database\Grammars;

use App\Database\Schema\Blueprint;
use App\Database\Schema\Column;

abstract class Grammar
{
    abstract public function typeFor(Column $column): string;

    abstract public function wrap(string $identifier): string;

    /** @return array<int,string> */
    abstract public function compileCreate(Blueprint $blueprint): array;

    public function compileDrop(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->wrap($table);
    }

    protected function formatDefault(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof RawDefault) {
            return $value->expression;
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}
