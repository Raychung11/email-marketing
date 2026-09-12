<?php

declare(strict_types=1);

namespace App\AI;

final class AiRequest
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $feature,
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly array $context = [],
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 2000,
        public readonly ?int $userId = null,
        public readonly ?string $relatedEntityType = null,
        public readonly ?int $relatedEntityId = null,
    ) {
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->systemPrompt . "\n" . $this->userPrompt);
    }
}
