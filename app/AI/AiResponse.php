<?php

declare(strict_types=1);

namespace App\AI;

final class AiResponse
{
    /** @param array<string,mixed> $data */
    private function __construct(
        public readonly bool $ok,
        public readonly string $text = '',
        public readonly array $data = [],
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly ?string $model = null,
        public readonly ?string $error = null,
        public readonly int $latencyMs = 0,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function success(
        string $text,
        array $data = [],
        int $promptTokens = 0,
        int $completionTokens = 0,
        ?string $model = null,
        int $latencyMs = 0,
    ): self {
        return new self(true, $text, $data, $promptTokens, $completionTokens, $model, null, $latencyMs);
    }

    public static function failure(string $error, ?string $model = null): self
    {
        return new self(false, '', [], 0, 0, $model, $error);
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
