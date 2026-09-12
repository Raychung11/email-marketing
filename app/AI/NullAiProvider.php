<?php

declare(strict_types=1);

namespace App\AI;

/**
 * Used in tests and when no AI key is configured.
 *
 * It returns a clear, non-fabricated refusal rather than plausible-looking made-up
 * content — an unconfigured AI must never produce something a user could mistake
 * for a real suggestion.
 */
final class NullAiProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function completeJson(AiRequest $request, array $schemaHint = []): AiResponse
    {
        return AiResponse::failure(
            'No AI provider is configured. Set OPENAI_API_KEY to enable AI features.'
        );
    }

    public function completeText(AiRequest $request): AiResponse
    {
        return $this->completeJson($request);
    }
}
