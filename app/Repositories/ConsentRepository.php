<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * contact_consents is APPEND-ONLY.
 *
 * This class intentionally exposes no update() and no delete(). A change of
 * consent is a new row; the history is the evidence, and evidence you can edit
 * is not evidence.
 */
final class ConsentRepository extends Repository
{
    protected function table(): string
    {
        return 'contact_consents';
    }

    /** @param array<string,mixed> $attributes */
    public function record(array $attributes): int
    {
        $attributes['created_at'] = $attributes['created_at'] ?? $this->now();

        return $this->scoped()->insert($this->withTenant($attributes));
    }

    /**
     * Current consent state = the most recent row for the channel.
     *
     * @return array<string,mixed>|null
     */
    public function current(int $contactId, string $channel = 'email'): ?array
    {
        return $this->scoped()
            ->where('contact_id', '=', $contactId)
            ->where('channel', '=', $channel)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Current state for a specific topic, falling back to the channel-wide row
     * when the contact has never expressed a topic-level preference.
     *
     * @return array<string,mixed>|null
     */
    public function currentForTopic(int $contactId, string $topic, string $channel = 'email'): ?array
    {
        $topicRow = $this->scoped()
            ->where('contact_id', '=', $contactId)
            ->where('channel', '=', $channel)
            ->where('topic', '=', $topic)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $topicRow ?? $this->current($contactId, $channel);
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $contactId, ?string $channel = null): array
    {
        $query = $this->scoped()->where('contact_id', '=', $contactId);

        if ($channel !== null) {
            $query->where('channel', '=', $channel);
        }

        return $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get();
    }

    /**
     * Latest consent rows for many contacts at once, so a list screen or a
     * campaign snapshot does not issue one query per contact.
     *
     * @param array<int,int> $contactIds
     * @return array<int,array<string,mixed>> keyed by contact_id
     */
    public function currentForMany(array $contactIds, string $channel = 'email'): array
    {
        if ($contactIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));

        $bindings = array_merge(
            [$this->organisationId(), $channel],
            $contactIds,
            [$this->organisationId(), $channel]
        );

        $rows = $this->connection->select(
            "SELECT c.* FROM contact_consents c
             INNER JOIN (
                SELECT contact_id, MAX(id) AS max_id
                FROM contact_consents
                WHERE organisation_id = ? AND channel = ? AND contact_id IN ({$placeholders})
                GROUP BY contact_id
             ) latest ON latest.max_id = c.id
             WHERE c.organisation_id = ? AND c.channel = ?",
            $bindings
        );

        $byContact = [];

        foreach ($rows as $row) {
            $byContact[(int) $row['contact_id']] = $row;
        }

        return $byContact;
    }

    /** @return array<string,int> */
    public function statusBreakdown(string $channel = 'email'): array
    {
        $rows = $this->connection->select(
            'SELECT c.status, COUNT(*) AS total FROM contact_consents c
             INNER JOIN (
                SELECT contact_id, MAX(id) AS max_id FROM contact_consents
                WHERE organisation_id = ? AND channel = ? GROUP BY contact_id
             ) latest ON latest.max_id = c.id
             GROUP BY c.status',
            [$this->organisationId(), $channel]
        );

        $breakdown = [];

        foreach ($rows as $row) {
            $breakdown[(string) $row['status']] = (int) $row['total'];
        }

        return $breakdown;
    }
}
