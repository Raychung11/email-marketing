<?php

declare(strict_types=1);

namespace App\Database\Schema;

final class Column
{
    public bool $nullable = false;

    public mixed $default = null;

    public bool $hasDefault = false;

    public bool $autoIncrement = false;

    public bool $unsigned = false;

    public ?string $comment = null;

    public ?string $after = null;

    /** @param array<int,string> $allowed */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly array $allowed = [],
    ) {
    }

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default    = $value;
        $this->hasDefault = true;

        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;

        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }
}
