<?php

declare(strict_types=1);

namespace App\Database\Grammars;

/** Marks a default that must be emitted verbatim, e.g. CURRENT_TIMESTAMP. */
final class RawDefault
{
    public function __construct(public readonly string $expression)
    {
    }
}
