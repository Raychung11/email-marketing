<?php

declare(strict_types=1);

namespace App\Repositories;

use Generator;

/**
 * The recipient snapshot.
 *
 * Written once when a campaign starts sending and never re-derived from the
 * segment afterwards. That is deliberate: a segment is a live query, and if it
 * were re-evaluated mid-send, a contact who stopped matching would be half-sent
 * and a contact who started matching would get a message the sender never
 * reviewed the audience for. The snapshot is also what the campaign report is
 * built from, so "who did this go to" has an answer that does not drift.
 */
final class CampaignRecipientRepository extends Repository
{
    protected function table(): string
    {
        return 'campaign_recipients';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $now      = $this->now();
        $prepared = [];

        foreach ($rows as $row) {
            $prepared[] = array_merge([
                'organisation_id'    => $this->organisationId(),
                'campaign_id'        => 0,
                'contact_id'         => 0,
                'email'              => '',
                'email_normalized'   => '',
                'eligibility_status' => 'eligible',
                'eligibility_reason' => null,
                'send_status'        => 'pending',
                'email_message_id'   => null,
                'ab_variant'         => null,
                'attempt_count'      => 0,
                'last_error'         => null,
                'queued_at'          => null,
                'sent_at'            => null,
                'created_at'         => $now,
            ], $row);
        }

        return $this->scoped()->insertMany($prepared, 500);
    }

    /**
     * The highest contact id already captured in this campaign's snapshot.
     *
     * The snapshot is built in contact-id order, so this is the watermark a
     * resumed build starts from — which is what makes building it restartable
     * after a crash without duplicating rows or skipping anyone.
     */
    public function lastSnapshottedContactId(int $campaignId): int
    {
        return (int) $this->connection->scalar(
            'SELECT COALESCE(MAX(contact_id), 0) FROM campaign_recipients
             WHERE organisation_id = ? AND campaign_id = ?',
            [$this->organisationId(), $campaignId]
        );
    }

    public function countFor(int $campaignId, ?string $eligibility = null): int
    {
        $query = $this->scoped()->where('campaign_id', '=', $campaignId);

        if ($eligibility !== null) {
            $query->where('eligibility_status', '=', $eligibility);
        }

        return $query->count();
    }

    /** @return array<string,int> */
    public function eligibilityBreakdown(int $campaignId): array
    {
        $rows = $this->connection->select(
            'SELECT eligibility_status, COUNT(*) AS total FROM campaign_recipients
             WHERE organisation_id = ? AND campaign_id = ? GROUP BY eligibility_status',
            [$this->organisationId(), $campaignId]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['eligibility_status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The eligibility breakdown in the vocabulary the UI and the campaign
     * counters use.
     *
     * One mapping, shared by the dispatcher (which writes the counters) and the
     * campaign page (which reads them), so the page can never disagree with the
     * number stored on the campaign.
     *
     * @return array{total:int,eligible:int,suppressed:int,no_consent:int,invalid:int,blocked:int}
     */
    public function eligibilitySummary(int $campaignId): array
    {
        $breakdown = $this->eligibilityBreakdown($campaignId);

        return [
            'total'      => array_sum($breakdown),
            'eligible'   => $breakdown['eligible'] ?? 0,
            'suppressed' => $breakdown['suppressed'] ?? 0,
            'no_consent' => $breakdown['no_consent'] ?? 0,
            'invalid'    => $breakdown['invalid'] ?? 0,
            'blocked'    => ($breakdown['blocked'] ?? 0) + ($breakdown['duplicate'] ?? 0),
        ];
    }

    /** @return array<string,int> send_status => count */
    public function sendStatusBreakdown(int $campaignId): array
    {
        $rows = $this->connection->select(
            'SELECT send_status, COUNT(*) AS total FROM campaign_recipients
             WHERE organisation_id = ? AND campaign_id = ? GROUP BY send_status',
            [$this->organisationId(), $campaignId]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['send_status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Why recipients were skipped, for the campaign report.
     *
     * @return array<string,int> reason code => count
     */
    public function skipReasons(int $campaignId): array
    {
        $rows = $this->connection->select(
            "SELECT eligibility_reason, COUNT(*) AS total FROM campaign_recipients
             WHERE organisation_id = ? AND campaign_id = ? AND eligibility_status != 'eligible'
               AND eligibility_reason IS NOT NULL
             GROUP BY eligibility_reason ORDER BY total DESC",
            [$this->organisationId(), $campaignId]
        );

        $reasons = [];

        foreach ($rows as $row) {
            $reasons[(string) $row['eligibility_reason']] = (int) $row['total'];
        }

        return $reasons;
    }

    /**
     * Stream the recipients still waiting to be queued.
     *
     * Keyset pagination on id, so cost does not grow as the campaign progresses
     * and a 100k snapshot never lands in PHP memory at once.
     *
     * @return Generator<int,array<string,mixed>>
     */
    public function pendingBatches(int $campaignId, int $batchSize = 500): Generator
    {
        $lastId = 0;

        do {
            $rows = $this->scoped()
                ->where('campaign_id', '=', $campaignId)
                ->where('eligibility_status', '=', 'eligible')
                ->where('send_status', '=', 'pending')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($rows === []) {
                return;
            }

            yield $rows;

            $lastId = (int) $rows[array_key_last($rows)]['id'];
        } while (count($rows) === $batchSize);
    }

    /**
     * Claim a recipient for sending.
     *
     * The conditional UPDATE is what makes a job idempotent: if the same job runs
     * twice — a redelivered queue message, a worker that died after sending but
     * before acknowledging — the second attempt claims nothing and sends nothing.
     */
    public function claimForSending(int $recipientId): bool
    {
        return $this->scoped()
            ->where('id', '=', $recipientId)
            ->whereIn('send_status', ['pending', 'queued'])
            ->update([
                'send_status'   => 'sent',
                'attempt_count' => 1,
                'sent_at'       => $this->now(),
            ]) > 0;
    }

    /** @param array<int,int> $recipientIds */
    public function markQueued(array $recipientIds): int
    {
        if ($recipientIds === []) {
            return 0;
        }

        return $this->scoped()
            ->whereIn('id', $recipientIds)
            ->where('send_status', '=', 'pending')
            ->update(['send_status' => 'queued', 'queued_at' => $this->now()]);
    }

    public function markSent(int $recipientId, int $messageId): void
    {
        $this->scoped()->where('id', '=', $recipientId)->update([
            'email_message_id' => $messageId,
            'sent_at'          => $this->now(),
        ]);
    }

    /**
     * A recipient that became ineligible between the snapshot and the send.
     *
     * This is the path the second compliance check takes, and it is recorded
     * rather than silently dropped so the campaign report can explain it.
     */
    public function markSkipped(int $recipientId, string $eligibilityStatus, string $reason): void
    {
        $this->scoped()->where('id', '=', $recipientId)->update([
            'send_status'        => 'skipped',
            'eligibility_status' => $eligibilityStatus,
            'eligibility_reason' => $reason,
        ]);
    }

    public function markFailed(int $recipientId, string $error): void
    {
        $this->scoped()->where('id', '=', $recipientId)->update([
            'send_status' => 'failed',
            'last_error'  => substr($error, 0, 255),
        ]);
    }

    /**
     * Return a recipient to the pool so a retry can claim it again.
     *
     * The message link is cleared too: the retry creates its own
     * `email_messages` row, and leaving the recipient pointing at the failed
     * handoff would make the campaign report cite a message that never went.
     */
    public function release(int $recipientId): void
    {
        $this->scoped()->where('id', '=', $recipientId)->update([
            'send_status'      => 'pending',
            'sent_at'          => null,
            'email_message_id' => null,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findForCampaign(int $campaignId, int $recipientId): ?array
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->where('id', '=', $recipientId)
            ->first();
    }

    public function remaining(int $campaignId): int
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->where('eligibility_status', '=', 'eligible')
            ->whereIn('send_status', ['pending', 'queued'])
            ->count();
    }

    public function deleteForCampaign(int $campaignId): int
    {
        return $this->scoped()->where('campaign_id', '=', $campaignId)->delete();
    }
}
