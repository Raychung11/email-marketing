<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Logger;
use App\Repositories\AuditLogRepository;
use App\Support\TenantContext;

/**
 * Security audit trail.
 *
 * Request context (actor, IP, user agent, correlation id) is injected once per
 * request by ShareViewContext/Authenticate middleware, so call sites only have to
 * describe *what* happened.
 */
final class AuditService
{
    private ?int $userId = null;

    private string $actorType = 'system';

    private ?string $ip = null;

    private ?string $userAgent = null;

    private ?string $correlationId = null;

    public function __construct(
        private readonly AuditLogRepository $logs,
        private readonly TenantContext $tenant,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function setActor(?int $userId, string $actorType = 'user'): void
    {
        $this->userId    = $userId;
        $this->actorType = $actorType;
    }

    public function setRequestContext(?string $ip, ?string $userAgent, ?string $correlationId = null): void
    {
        $this->ip            = $ip;
        $this->userAgent     = $userAgent;
        $this->correlationId = $correlationId;
    }

    /**
     * @param array<string,mixed>|null $oldValues
     * @param array<string,mixed>|null $newValues
     */
    public function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $organisationId = null,
    ): int {
        $organisationId ??= $this->tenant->isBound() ? $this->tenant->organisationId() : null;

        $id = $this->logs->record([
            'organisation_id' => $organisationId,
            'user_id'         => $this->userId,
            'actor_type'      => $this->actorType,
            'action'          => $action,
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'old_values'      => $oldValues === null ? null : $this->redact($oldValues),
            'new_values'      => $newValues === null ? null : $this->redact($newValues),
            'ip_address'      => $this->ip,
            'user_agent'      => $this->userAgent,
            'correlation_id'  => $this->correlationId ?? $this->logger->correlationId(),
            'created_at'      => $this->clock->nowString(),
        ]);

        return $id;
    }

    /**
     * Audit an action attributed to the AI layer.
     *
     * AI actions are recorded with actor_type = 'ai' so that a reviewer can
     * always tell what a human decided from what a model suggested.
     *
     * @param array<string,mixed>|null $newValues
     */
    public function logAi(string $action, ?string $entityType = null, ?int $entityId = null, ?array $newValues = null): int
    {
        $previous        = $this->actorType;
        $this->actorType = 'ai';

        try {
            return $this->log($action, $entityType, $entityId, null, $newValues);
        } finally {
            $this->actorType = $previous;
        }
    }

    /**
     * Never let a credential or a password reach the audit trail, even if a
     * caller passes a whole record.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function redact(array $values): array
    {
        $sensitive = [
            'password', 'password_hash', 'password_confirmation', 'token',
            'token_hash', 'key_hash', 'secret', 'secret_encrypted', 'mfa_secret',
            'invite_token_hash', 'api_key',
        ];

        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                $values[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
