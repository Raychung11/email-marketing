<?php

declare(strict_types=1);

namespace App\Database;

use Generator;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO wrapper. Every query goes through a prepared statement — there is no API
 * on this class that concatenates user input into SQL.
 */
final class Connection
{
    private ?PDO $pdo = null;

    private int $transactionLevel = 0;

    /** @var array<int,array{sql:string,bindings:array<mixed>,time:float}> */
    private array $queryLog = [];

    private bool $logging = false;

    private bool $collecting = false;

    /** @var array<int,string> */
    private array $collected = [];

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @param array<string,mixed> $config */
    public static function fromPdo(PDO $pdo, string $driver = 'sqlite', array $config = []): self
    {
        $connection      = new self(['driver' => $driver] + $config);
        $connection->pdo = $pdo;

        return $connection;
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    public function charset(): string
    {
        return (string) ($this->config['charset'] ?? 'utf8mb4');
    }

    /**
     * The collation the schema builder stamps on every CREATE TABLE.
     *
     * This has to come from configuration rather than a constant: MySQL 8 and
     * MariaDB do not share a collation name. MariaDB has never had
     * utf8mb4_0900_ai_ci and rejects it outright, so a hardcoded value makes the
     * application undeployable on the shared hosting most small businesses run.
     */
    public function collation(): string
    {
        return (string) ($this->config['collation'] ?? 'utf8mb4_unicode_ci');
    }

    private function connect(): PDO
    {
        $driver = $this->driver();

        $dsn = match ($driver) {
            'sqlite' => 'sqlite:' . ($this->config['database'] ?? ':memory:'),
            'mysql'  => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config['host'] ?? '127.0.0.1',
                (int) ($this->config['port'] ?? 3306),
                $this->config['database'] ?? '',
                $this->config['charset'] ?? 'utf8mb4'
            ),
            default => throw new \RuntimeException("Unsupported database driver [{$driver}]."),
        };

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements: the server never sees interpolated values.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        try {
            $pdo = $driver === 'sqlite'
                ? new PDO($dsn, null, null, $options)
                : new PDO(
                    $dsn,
                    (string) ($this->config['username'] ?? ''),
                    (string) ($this->config['password'] ?? ''),
                    $options
                );
        } catch (PDOException $e) {
            // Never leak credentials from the DSN into an exception trace.
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }

        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            $pdo->exec("SET time_zone = '+00:00'");
        }

        return $pdo;
    }

    /** @param array<int|string,mixed> $bindings */
    public function statement(string $sql, array $bindings = []): PDOStatement
    {
        $start     = microtime(true);
        $statement = $this->pdo()->prepare($sql);

        foreach ($this->normalise($bindings) as $key => $value) {
            $statement->bindValue(
                is_int($key) ? $key + 1 : $key,
                $value,
                match (true) {
                    is_int($value)  => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    default         => PDO::PARAM_STR,
                }
            );
        }

        $statement->execute();

        if ($this->logging) {
            $this->queryLog[] = ['sql' => $sql, 'bindings' => $bindings, 'time' => microtime(true) - $start];
        }

        return $statement;
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->statement($sql, $bindings)->fetchAll();
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->statement($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $bindings */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->statement($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Stream rows one at a time.
     *
     * Batch work (campaign recipients, imports, exports) uses this rather than
     * select(): a 100k-recipient campaign must never materialise in PHP memory.
     *
     * @param array<int|string,mixed> $bindings
     * @return Generator<int,array<string,mixed>>
     */
    public function cursor(string $sql, array $bindings = []): Generator
    {
        $statement = $this->statement($sql, $bindings);

        while (($row = $statement->fetch()) !== false) {
            yield $row;
        }
    }

    /** @param array<int|string,mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings)->rowCount();
    }

    public function raw(string $sql): void
    {
        if ($this->collecting) {
            $this->collected[] = $sql;

            return;
        }

        $this->pdo()->exec($sql);
    }

    /**
     * Capture DDL instead of executing it.
     *
     * This is how `schema:sql` renders the production MySQL DDL from the same
     * migration files without needing a MySQL server — and how the test suite
     * asserts that the MySQL grammar stays valid while running on SQLite.
     *
     * @return array<int,string>
     */
    public function dryRun(callable $callback): array
    {
        $previousCollecting = $this->collecting;
        $previousCollected  = $this->collected;

        $this->collecting = true;
        $this->collected  = [];

        try {
            $callback($this);

            return $this->collected;
        } finally {
            $this->collecting = $previousCollecting;
            $this->collected  = $previousCollected;
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * Nested transactions via savepoints so a service can open one without
     * caring whether a caller already did.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT trans' . ($this->transactionLevel + 1));
        }

        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel === 1) {
            $this->pdo()->commit();
        } elseif ($this->transactionLevel > 1) {
            $this->pdo()->exec('RELEASE SAVEPOINT trans' . $this->transactionLevel);
        }

        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel === 1) {
            $this->pdo()->rollBack();
        } elseif ($this->transactionLevel > 1) {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactionLevel);
        }

        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    public function enableQueryLog(): void
    {
        $this->logging = true;
    }

    /** @return array<int,array{sql:string,bindings:array<mixed>,time:float}> */
    public function queryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return array<int|string,mixed>
     */
    private function normalise(array $bindings): array
    {
        foreach ($bindings as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $bindings[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_bool($value)) {
                $bindings[$key] = $value ? 1 : 0;
            } elseif (is_array($value)) {
                $bindings[$key] = json_encode($value, JSON_UNESCAPED_SLASHES);
            }
        }

        return $bindings;
    }
}
