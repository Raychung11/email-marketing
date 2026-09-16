<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Schema\Schema;

/**
 * Renders the full schema as DDL for a chosen driver by running the migration
 * files through a grammar without executing anything.
 *
 * Two uses: hand a DBA the exact production statements, and let the test suite
 * prove the MySQL grammar still compiles every migration while the suite itself
 * runs on SQLite.
 */
final class SchemaDumper
{
    public function __construct(
        private readonly string $migrationsPath,
        private readonly string $charset = 'utf8mb4',
        private readonly string $collation = 'utf8mb4_unicode_ci',
    ) {
    }

    /** @return array<int,string> */
    public function dump(string $driver = 'mysql'): array
    {
        // The connection is never queried: dryRun() intercepts every statement
        // before it reaches PDO. It exists only so Schema can pick the grammar.
        $target = Connection::fromPdo(
            $this->nullPdo(),
            $driver === 'mysql' ? 'mysql' : 'sqlite',
            ['charset' => $this->charset, 'collation' => $this->collation]
        );
        $schema = new Schema($target);

        return $target->dryRun(function () use ($schema, $target): void {
            foreach ($this->files() as $file) {
                /** @var Migration $migration */
                $migration = require $file;
                $migration->up($schema, $target);
            }
        });
    }

    public function toSql(string $driver = 'mysql'): string
    {
        $statements = array_map(
            static fn (string $sql): string => rtrim($sql, ";\n") . ';',
            $this->dump($driver)
        );

        return implode("\n\n", $statements) . "\n";
    }

    /** @return array<int,string> */
    private function files(): array
    {
        $files = glob($this->migrationsPath . '/*.php') ?: [];
        sort($files);

        return $files;
    }

    private function nullPdo(): \PDO
    {
        return new \PDO('sqlite::memory:');
    }
}
