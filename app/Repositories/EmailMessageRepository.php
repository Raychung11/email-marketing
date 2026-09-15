<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * One row per message handed to a provider.
 *
 * Stores addressing and status, never the rendered body: this table would
 * otherwise become a permanent copy of every marketing email ever sent to every
 * customer, which is a liability with no operational value.
 */
final class EmailMessageRepository extends Repository
{
    protected function table(): string
    {
        return 'email_messages';
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $attributes['uuid']       = $attributes['uuid'] ?? uuid4();
        $attributes['created_at'] = $attributes['created_at'] ?? $this->now();

        if (isset($attributes['email'])) {
            $attributes['email_normalized'] = normalize_email((string) $attributes['email']);
        }

        return $this->scoped()->insert($this->withTenant($attributes));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        return $this->scoped()->where('id', '=', $id)->update($attributes);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->scoped()->where('uuid', '=', $uuid)->first();
    }

    /**
     * How many marketing messages this organisation has handed to a provider
     * today, in its own timezone.
     *
     * The daily limit is a calendar-day limit as the customer understands it, not
     * a rolling 24 hours, so the window is computed in their timezone.
     */
    public function sentToday(string $timezone): int
    {
        $start = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))
            ->setTime(0, 0)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        return (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM email_messages
             WHERE organisation_id = ? AND message_class = 'marketing' AND created_at >= ?",
            [$this->organisationId(), $start]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function forContact(int $contactId, int $limit = 50): array
    {
        return $this->scoped()
            ->where('contact_id', '=', $contactId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /** @return array<int,array<string,mixed>> */
    public function forCampaign(int $campaignId, int $limit = 100, int $offset = 0): array
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }
}
