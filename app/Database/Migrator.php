<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * File-ordered migration runner with a batch log, so `migrate:rollback` undoes
 * exactly the last batch.
 */
final class Migrator
{
    private const TABLE = 'migrations';

    private Schema $schema;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $path,
    ) {
        $this->schema = new Schema($connection);
    }

    public function ensureRepository(): void
    {
        if ($this->schema->hasTable(self::TABLE)) {
            return;
        }

        $this->schema->create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('migration', 255);
            $table->integer('batch');
            $table->dateTime('ran_at');
            $table->unique('migration');
        });
    }

    /** @return array<int,string> names of migrations that were applied */
    public function run(?callable $output = null): array
    {
        $this->ensureRepository();

        $applied = $this->applied();
        $pending = array_values(array_diff($this->files(), $applied));

        if ($pending === []) {
            $output && $output('Nothing to migrate.');

            return [];
        }

        $batch = $this->nextBatch();
        $ran   = [];

        foreach ($pending as $name) {
            $migration = $this->resolve($name);

            $output && $output("Migrating: {$name}");

            // DDL is not transactional in MySQL, so each migration is applied
            // independently and the log records exactly what succeeded.
            $migration->up($this->schema, $this->connection);

            $this->connection->table(self::TABLE)->insert([
                'migration' => $name,
                'batch'     => $batch,
                'ran_at'    => gmdate('Y-m-d H:i:s'),
            ]);

            $output && $output("Migrated:  {$name}");
            $ran[] = $name;
        }

        return $ran;
    }

    /** @return array<int,string> */
    public function rollback(?callable $output = null): array
    {
        $this->ensureRepository();

        $batch = (int) $this->connection->scalar('SELECT MAX(batch) FROM ' . $this->quoted());

        if ($batch === 0) {
            $output && $output('Nothing to roll back.');

            return [];
        }

        $rows = $this->connection->table(self::TABLE)
            ->where('batch', '=', $batch)
            ->orderBy('migration', 'desc')
            ->get();

        $rolled = [];

        foreach ($rows as $row) {
            $name      = (string) $row['migration'];
            $migration = $this->resolve($name);

            $output && $output("Rolling back: {$name}");

            $migration->down($this->schema, $this->connection);

            $this->connection->table(self::TABLE)->where('migration', '=', $name)->delete();

            $rolled[] = $name;
        }

        return $rolled;
    }

    /** @return array<int,array{migration:string,batch:int,applied:bool}> */
    public function status(): array
    {
        $this->ensureRepository();

        $applied = [];

        foreach ($this->connection->table(self::TABLE)->get() as $row) {
            $applied[(string) $row['migration']] = (int) $row['batch'];
        }

        $status = [];

        foreach ($this->files() as $name) {
            $status[] = [
                'migration' => $name,
                'batch'     => $applied[$name] ?? 0,
                'applied'   => isset($applied[$name]),
            ];
        }

        return $status;
    }

    /** @return array<int,string> */
    public function files(): array
    {
        $files = glob($this->path . '/*.php') ?: [];
        $names = array_map(static fn (string $file): string => basename($file, '.php'), $files);

        sort($names);

        return $names;
    }

    /** @return array<int,string> */
    private function applied(): array
    {
        return array_map(
            static fn (mixed $value): string => (string) $value,
            $this->connection->table(self::TABLE)->pluck('migration')
        );
    }

    private function nextBatch(): int
    {
        return ((int) $this->connection->scalar('SELECT MAX(batch) FROM ' . $this->quoted())) + 1;
    }

    private function resolve(string $name): Migration
    {
        $file = $this->path . '/' . $name . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("Migration file not found: {$file}");
        }

        /** @var mixed $migration */
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new \RuntimeException("Migration [{$name}] must return an instance of " . Migration::class . '.');
        }

        return $migration;
    }

    private function quoted(): string
    {
        return $this->connection->driver() === 'mysql' ? '`' . self::TABLE . '`' : '"' . self::TABLE . '"';
    }
}
