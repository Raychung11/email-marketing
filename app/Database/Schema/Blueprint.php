<?php

declare(strict_types=1);

namespace App\Database\Schema;

/**
 * Table definition. Rendered to SQL by a driver-specific grammar so the same
 * migration file produces MySQL 8 in production and SQLite in the test suite.
 */
final class Blueprint
{
    /** @var array<int,Column> */
    public array $columns = [];

    /** @var array<int,array{type:string,columns:array<int,string>,name:string}> */
    public array $indexes = [];

    /** @var array<int,array{columns:array<int,string>,on:string,references:string,onDelete:string,onUpdate:string,name:string}> */
    public array $foreignKeys = [];

    /** @var array<int,string> */
    public array $primary = [];

    public function __construct(public readonly string $table)
    {
    }

    public function id(string $name = 'id'): Column
    {
        $column                = new Column($name, 'bigint');
        $column->autoIncrement = true;
        $column->unsigned      = true;
        $this->columns[]       = $column;
        $this->primary         = [$name];

        return $column;
    }

    public function bigInteger(string $name): Column
    {
        return $this->columns[] = new Column($name, 'bigint');
    }

    public function integer(string $name): Column
    {
        return $this->columns[] = new Column($name, 'integer');
    }

    public function smallInteger(string $name): Column
    {
        return $this->columns[] = new Column($name, 'smallint');
    }

    public function tinyInteger(string $name): Column
    {
        return $this->columns[] = new Column($name, 'tinyint');
    }

    public function string(string $name, int $length = 255): Column
    {
        return $this->columns[] = new Column($name, 'varchar', $length);
    }

    public function char(string $name, int $length = 36): Column
    {
        return $this->columns[] = new Column($name, 'char', $length);
    }

    public function text(string $name): Column
    {
        return $this->columns[] = new Column($name, 'text');
    }

    public function longText(string $name): Column
    {
        return $this->columns[] = new Column($name, 'longtext');
    }

    /** JSON document column. MySQL uses native JSON; SQLite falls back to TEXT. */
    public function json(string $name): Column
    {
        return $this->columns[] = new Column($name, 'json');
    }

    public function boolean(string $name): Column
    {
        return $this->columns[] = new Column($name, 'boolean');
    }

    /** Money. NEVER a float — currency is a separate column, never hardcoded. */
    public function decimal(string $name, int $precision = 14, int $scale = 2): Column
    {
        return $this->columns[] = new Column($name, 'decimal', null, $precision, $scale);
    }

    public function dateTime(string $name): Column
    {
        return $this->columns[] = new Column($name, 'datetime');
    }

    public function date(string $name): Column
    {
        return $this->columns[] = new Column($name, 'date');
    }

    /** @param array<int,string> $values */
    public function enum(string $name, array $values): Column
    {
        return $this->columns[] = new Column($name, 'enum', null, null, null, $values);
    }

    public function uuid(string $name = 'uuid'): Column
    {
        return $this->char($name, 36);
    }

    public function ipAddress(string $name = 'ip_address'): Column
    {
        return $this->string($name, 45);
    }

    /** created_at / updated_at, stored in UTC. */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    public function softDeletes(): void
    {
        $this->dateTime('deleted_at')->nullable();
    }

    /**
     * Tenant key. Every tenant-scoped table calls this, and every composite
     * index on such a table leads with organisation_id.
     */
    public function organisationId(): Column
    {
        $column           = new Column('organisation_id', 'bigint');
        $column->unsigned = true;
        $this->columns[]  = $column;

        return $column;
    }

    public function foreignId(string $name): Column
    {
        $column           = new Column($name, 'bigint');
        $column->unsigned = true;

        return $this->columns[] = $column;
    }

    public function index(string|array $columns, string $name = ''): self
    {
        $columns = (array) $columns;

        $this->indexes[] = [
            'type'    => 'index',
            'columns' => $columns,
            'name'    => $name !== '' ? $name : $this->indexName('idx', $columns),
        ];

        return $this;
    }

    public function unique(string|array $columns, string $name = ''): self
    {
        $columns = (array) $columns;

        $this->indexes[] = [
            'type'    => 'unique',
            'columns' => $columns,
            'name'    => $name !== '' ? $name : $this->indexName('uniq', $columns),
        ];

        return $this;
    }

    /** @param array<int,string> $columns */
    public function primaryKey(array $columns): self
    {
        $this->primary = $columns;

        return $this;
    }

    public function foreign(
        string|array $columns,
        string $on,
        string $references = 'id',
        string $onDelete = 'cascade',
        string $onUpdate = 'cascade',
    ): self {
        $columns = (array) $columns;

        $this->foreignKeys[] = [
            'columns'    => $columns,
            'on'         => $on,
            'references' => $references,
            'onDelete'   => $onDelete,
            'onUpdate'   => $onUpdate,
            'name'       => $this->indexName('fk', $columns),
        ];

        return $this;
    }

    /** @param array<int,string> $columns */
    private function indexName(string $prefix, array $columns): string
    {
        $name = $prefix . '_' . $this->table . '_' . implode('_', $columns);

        // MySQL caps identifiers at 64 characters.
        return strlen($name) <= 64 ? $name : substr($name, 0, 54) . '_' . substr(md5($name), 0, 8);
    }
}
