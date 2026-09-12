<?php

declare(strict_types=1);

namespace Tests\Support;

use App\AI\AiProviderInterface;
use App\AI\AiRequest;
use App\AI\AiResponse;

/**
 * An AI provider whose answers a test writes.
 *
 * Most of what matters about the AI layer is what happens to output we did not
 * choose: a model that returns a script tag, a made-up merge field, a link to
 * somewhere we never mentioned, or a claim to have sent something. None of that
 * can be tested against a real model, because a real model mostly behaves. So
 * the tests script the misbehaviour and assert the code refuses it.
 */
final class ScriptedAiProvider implements AiProviderInterface
{
    /** @var array<int,AiResponse> */
    private array $script = [];

    /** @var array<int,AiRequest> */
    private array $received = [];

    private bool $configured = true;

    public function name(): string
    {
        return 'scripted';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function unconfigured(): void
    {
        $this->configured = false;
    }

    /** @param array<string,mixed> ...$payloads each becomes one JSON reply */
    public function willReturn(array ...$payloads): void
    {
        foreach ($payloads as $payload) {
            $this->script[] = AiResponse::success(
                (string) json_encode($payload),
                $payload,
                120,
                340,
                'scripted-model',
                42
            );
        }
    }

    public function willFail(string $error): void
    {
        $this->script[] = AiResponse::failure($error, 'scripted-model');
    }

    public function completeJson(AiRequest $request, array $schemaHint = []): AiResponse
    {
        $this->received[] = $request;

        return array_shift($this->script)
            ?? AiResponse::failure('The test did not script a reply for ' . $request->feature . '.');
    }

    public function completeText(AiRequest $request): AiResponse
    {
        return $this->completeJson($request);
    }

    /** @return array<int,AiRequest> */
    public function received(): array
    {
        return $this->received;
    }

    public function lastPrompt(): string
    {
        $last = end($this->received);

        return $last === false ? '' : $last->systemPrompt . "\n" . $last->userPrompt;
    }
}
