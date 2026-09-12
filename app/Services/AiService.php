<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\AiGuard;
use App\AI\AiProviderInterface;
use App\AI\AiRequest;
use App\AI\AiResponse;
use App\Core\Clock;
use App\Core\Config;
use App\Database\Connection;
use App\Support\TenantContext;

/**
 * The application's entry point to AI.
 *
 * Every call is metered into ai_requests (for billing and abuse control), capped
 * per organisation per month, and passes through AiGuard so the safety boundary
 * cannot be bypassed by calling a provider directly.
 */
final class AiService
{
    /**
     * The feature vocabulary `ai_requests.feature` declares.
     *
     * Kept here so a caller that invents a name records an 'other' row and a log
     * line rather than putting a database constraint violation in front of a
     * customer who only asked for a subject line.
     */
    private const FEATURES = [
        'campaign_studio', 'subject_lines', 'content', 'segment_generator',
        'campaign_analysis', 'assistant', 'recommendations', 'insights', 'other',
    ];

    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly AiGuard $guard,
        private readonly Connection $connection,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
        private readonly \App\Core\Logger $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * Ask for structured output.
     *
     * @param array<string,mixed> $schema
     */
    public function structured(AiRequest $request, array $schema): AiResponse
    {
        if (!$this->withinCap()) {
            $this->record($request, AiResponse::failure('Monthly AI allowance reached.'), 'capped');

            return AiResponse::failure(
                'Your monthly AI allowance has been reached. It resets at the start of next month.'
            );
        }

        $hardened = new AiRequest(
            $request->feature,
            $this->guard->systemPreamble() . "\n\n" . $request->systemPrompt,
            $request->userPrompt,
            $request->context,
            $request->temperature,
            $request->maxTokens,
            $request->userId,
            $request->relatedEntityType,
            $request->relatedEntityId,
        );

        $response = $this->provider->completeJson($hardened, $schema);

        $this->record($request, $response, $response->ok ? 'success' : 'failed');

        return $response;
    }

    public function text(AiRequest $request): AiResponse
    {
        if (!$this->withinCap()) {
            return AiResponse::failure('Your monthly AI allowance has been reached.');
        }

        $response = $this->provider->completeText(new AiRequest(
            $request->feature,
            $this->guard->systemPreamble() . "\n\n" . $request->systemPrompt,
            $request->userPrompt,
            $request->context,
            $request->temperature,
            $request->maxTokens,
            $request->userId,
        ));

        $this->record($request, $response, $response->ok ? 'success' : 'failed');

        return $response;
    }

    public function tokensUsedThisMonth(): int
    {
        return (int) $this->connection->scalar(
            'SELECT COALESCE(SUM(total_tokens), 0) FROM ai_requests
             WHERE organisation_id = ? AND created_at >= ?',
            [$this->tenant->organisationId(), $this->clock->now()->format('Y-m-01 00:00:00')]
        );
    }

    public function monthlyCap(): int
    {
        return (int) $this->config->get('ai.monthly_token_cap', 2_000_000);
    }

    private function withinCap(): bool
    {
        return $this->tokensUsedThisMonth() < $this->monthlyCap();
    }

    private function record(AiRequest $request, AiResponse $response, string $status): void
    {
        $feature = $request->feature;

        if (!in_array($feature, self::FEATURES, true)) {
            $this->logger->warning('Unknown AI feature name; recording it as "other".', [
                'feature' => $feature,
            ]);

            $feature = 'other';
        }

        $this->connection->table('ai_requests')->insert([
            'organisation_id'     => $this->tenant->organisationId(),
            'user_id'             => $request->userId,
            'uuid'                => uuid4(),
            'provider'            => $this->provider->name(),
            'model'               => $response->model,
            'feature'             => $feature,
            'prompt_tokens'       => $response->promptTokens,
            'completion_tokens'   => $response->completionTokens,
            'total_tokens'        => $response->totalTokens(),
            'latency_ms'          => $response->latencyMs,
            'status'              => $status,
            'error'               => $response->error === null ? null : substr($response->error, 0, 500),
            // A hash, not the prompt: enough to correlate and de-duplicate without
            // retaining customer content indefinitely.
            'prompt_hash'         => $request->fingerprint(),
            'request_summary'     => json_encode([
                'feature'     => $request->feature,
                'temperature' => $request->temperature,
                'max_tokens'  => $request->maxTokens,
            ], JSON_UNESCAPED_SLASHES),
            'response_summary'    => json_encode([
                'ok'    => $response->ok,
                'keys'  => array_slice(array_keys($response->data), 0, 20),
            ], JSON_UNESCAPED_SLASHES),
            'related_entity_type' => $request->relatedEntityType,
            'related_entity_id'   => $request->relatedEntityId,
            'created_at'          => $this->clock->nowString(),
        ]);
    }
}
