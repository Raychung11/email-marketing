<?php

declare(strict_types=1);

namespace App\AI;

/**
 * AI providers are interchangeable. OpenAI is the first implementation; nothing
 * outside app/AI names a model or a vendor.
 */
interface AiProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Ask for a structured JSON object matching $schemaHint.
     *
     * Structured output is the only mode the application uses: free prose would
     * have to be parsed, and a parser that guesses is a parser that eventually
     * puts model output somewhere it should not be.
     *
     * @param array<string,mixed> $schemaHint
     */
    public function completeJson(AiRequest $request, array $schemaHint = []): AiResponse;

    public function completeText(AiRequest $request): AiResponse;
}
