<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * A unit of queued work.
 *
 * Payloads are plain arrays, never serialised objects: a job enqueued by one
 * release must still be readable by the next, and an object graph in a queue is a
 * deployment hazard.
 */
final class Job
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $queue,
        public readonly string $jobClass,
        public readonly array $payload,
        public readonly ?int $organisationId = null,
        public readonly int $attemptCount = 0,
        public readonly ?int $id = null,
        public readonly int $priority = 0,
        public readonly ?string $availableAt = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $payload = $data['payload'] ?? [];

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return new self(
            (string) ($data['queue'] ?? 'default'),
            (string) ($data['job_class'] ?? ''),
            $payload,
            isset($data['organisation_id']) ? (int) $data['organisation_id'] : null,
            (int) ($data['attempt_count'] ?? 0),
            isset($data['id']) ? (int) $data['id'] : null,
            (int) ($data['priority'] ?? 0),
            $data['available_at'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'queue'           => $this->queue,
            'job_class'       => $this->jobClass,
            'payload'         => $this->payload,
            'organisation_id' => $this->organisationId,
            'attempt_count'   => $this->attemptCount,
            'priority'        => $this->priority,
            'available_at'    => $this->availableAt,
        ];
    }

    public function withAttempt(int $attemptCount): self
    {
        return new self(
            $this->queue,
            $this->jobClass,
            $this->payload,
            $this->organisationId,
            $attemptCount,
            $this->id,
            $this->priority,
            $this->availableAt,
        );
    }
}
