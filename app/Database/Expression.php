<?php

declare(strict_types=1);

namespace App\Database;

/**
 * A fragment of SQL that must not be bound as a parameter.
 *
 * It exists for one job: `attempt_count = attempt_count + 1` inside a
 * conditional UPDATE. Reading the value, adding one and writing it back would
 * be a read-modify-write across two statements, and two workers racing on the
 * same row would both read 3 and both write 4.
 *
 * Nothing user-supplied may reach this class — the SQL is checked against a
 * deliberately narrow pattern on construction rather than trusted, because an
 * unbound string in an UPDATE is exactly the shape of an injection.
 */
final class Expression
{
    public function __construct(public readonly string $sql)
    {
        // column, column + n, column - n. Anything else is refused rather than
        // passed through on the assumption that the caller knew what it meant.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\s*[+\-]\s*\d+)?$/', $sql) !== 1) {
            throw new \InvalidArgumentException("Unsupported SQL expression [{$sql}].");
        }
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
