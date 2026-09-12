<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Logger;

/**
 * OpenAI chat completions over cURL — no SDK dependency.
 *
 * The API key is read from the environment and never leaves the server: it is not
 * stored in the database and never reaches the browser.
 */
final class OpenAiProvider implements AiProviderInterface
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return (string) ($this->config['api_key'] ?? '') !== '' && function_exists('curl_init');
    }

    /** @param array<string,mixed> $schemaHint */
    public function completeJson(AiRequest $request, array $schemaHint = []): AiResponse
    {
        $system = $request->systemPrompt;

        if ($schemaHint !== []) {
            $system .= "\n\nRespond with a single JSON object only, with no commentary "
                . "and no markdown fences. It must match this shape:\n"
                . (json_encode($schemaHint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        $response = $this->call($system, $request, true);

        if (!$response->ok) {
            return $response;
        }

        $decoded = $this->decodeJson($response->text);

        if ($decoded === null) {
            return AiResponse::failure('The AI response was not valid JSON.', $response->model);
        }

        return AiResponse::success(
            $response->text,
            $decoded,
            $response->promptTokens,
            $response->completionTokens,
            $response->model,
            $response->latencyMs
        );
    }

    public function completeText(AiRequest $request): AiResponse
    {
        return $this->call($request->systemPrompt, $request, false);
    }

    private function call(string $systemPrompt, AiRequest $request, bool $jsonMode): AiResponse
    {
        if (!$this->isConfigured()) {
            return AiResponse::failure('OpenAI is not configured (OPENAI_API_KEY is empty).');
        }

        $model   = (string) ($this->config['model'] ?? 'gpt-4o-mini');
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? 'https://api.openai.com/v1'), '/');

        $payload = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $request->userPrompt],
            ],
            'temperature' => $request->temperature,
            'max_tokens'  => $request->maxTokens,
        ];

        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $started = microtime(true);

        $handle = curl_init($baseUrl . '/chat/completions');

        if ($handle === false) {
            return AiResponse::failure('Unable to initialise an HTTP request to the AI provider.', $model);
        }

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) ($this->config['timeout'] ?? 60),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . (string) $this->config['api_key'],
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        $latency = (int) round((microtime(true) - $started) * 1000);

        if ($body === false || $error !== '') {
            $this->logger?->warning('AI request failed', ['error' => $error, 'model' => $model]);

            return AiResponse::failure('AI request failed: ' . $error, $model);
        }

        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode((string) $body, true);

        if (!is_array($decoded)) {
            return AiResponse::failure('The AI provider returned an unreadable response.', $model);
        }

        if ($status >= 400) {
            $message = (string) ($decoded['error']['message'] ?? 'HTTP ' . $status);

            // Never surface the provider's raw error verbatim to an end user; the
            // caller decides what to show, and the detail goes to the log.
            $this->logger?->warning('AI provider error', ['status' => $status, 'message' => $message]);

            return AiResponse::failure($message, $model);
        }

        $text  = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        $usage = $decoded['usage'] ?? [];

        return AiResponse::success(
            $text,
            [],
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            (string) ($decoded['model'] ?? $model),
            $latency
        );
    }

    /** @return array<string,mixed>|null */
    private function decodeJson(string $text): ?array
    {
        $text = trim($text);

        // Strip a markdown fence if the model added one despite instructions.
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text);
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }
}
