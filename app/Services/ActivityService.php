<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Repositories\ActivityLogRepository;

/**
 * Operational activity feed — what powers the Customer 360 timeline. Kept apart
 * from the audit trail on purpose: engagement volume must never push security
 * records out of view.
 */
final class ActivityService
{
    private ?int $userId = null;

    public function __construct(
        private readonly ActivityLogRepository $logs,
        private readonly Clock $clock,
    ) {
    }

    public function setActor(?int $userId): void
    {
        $this->userId = $userId;
    }

    /** @param array<string,mixed> $metadata */
    public function record(
        string $activityType,
        ?int $contactId = null,
        ?string $description = null,
        array $metadata = [],
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $occurredAt = null,
    ): int {
        return $this->logs->record([
            'contact_id'    => $contactId,
            'user_id'       => $this->userId,
            'activity_type' => $activityType,
            'subject_type'  => $subjectType,
            'subject_id'    => $subjectId,
            'description'   => $description,
            'metadata'      => $metadata === [] ? null : $metadata,
            'occurred_at'   => $occurredAt ?? $this->clock->nowString(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function timeline(int $contactId, int $limit = 100): array
    {
        return $this->logs->timelineForContact($contactId, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 30): array
    {
        return $this->logs->recent($limit);
    }
}
