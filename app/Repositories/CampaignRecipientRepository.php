<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Expression;
use App\Database\QueryBuilder;
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
     * Stream the recipients ready to be queued.
     *
     * Keyset pagination on id, so cost does not grow as the campaign progresses
     * and a 100k snapshot never lands in PHP memory at once.
     *
     * `available_at` is honoured here: a recipient released after a transient
     * provider failure carries the queue's backoff on its row, and without this
     * clause the next scheduler tick would enqueue it again immediately and
     * route straight around the delay the worker just set.
     *
     * @return Generator<int,array<string,mixed>>
     */
    public function pendingBatches(int $campaignId, int $batchSize = 500): Generator
    {
        $lastId = 0;
        $now    = $this->now();

        do {
            $rows = $this->scoped()
                ->where('campaign_id', '=', $campaignId)
                ->where('eligibility_status', '=', 'eligible')
                ->where('send_status', '=', 'pending')
                ->whereGroup(static function (QueryBuilder $query) use ($now): void {
                    $query->whereNull('available_at')->where('available_at', '<=', $now, 'or');
                })
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
     * Mark a batch as queued, and record whose daily allowance they hold.
     *
     * $budgetDay is the calendar day, in the organisation's own timezone, whose
     * reservation these rows have already consumed. Carrying it on the row is
     * what stops a retry spending the allowance a second time.
     *
     * @param array<int,int> $recipientIds
     */
    public function markQueued(array $recipientIds, ?string $budgetDay = null): int
    {
        if ($recipientIds === []) {
            return 0;
        }

        $attributes = ['send_status' => 'queued', 'queued_at' => $this->now()];

        if ($budgetDay !== null) {
            $attributes['budget_day'] = $budgetDay;
        }

        return $this->scoped()
            ->whereIn('id', $recipientIds)
            ->where('send_status', '=', 'pending')
            ->update($attributes);
    }

    // ------------------------------------------------------------- the claim

    /**
     * Take ownership of a recipient for one send attempt.
     *
     * This is the most important statement in the send path, and what it must
     * NOT do is say "sent". It moves the row to `sending` and stamps an
     * ownership token; only the holder of that token may advance it afterwards.
     * A redelivered job, a second worker, or a scheduler that enqueued the same
     * recipient twice all lose the conditional UPDATE and do nothing.
     *
     * attempt_count is incremented rather than set. The column is meant to
     * answer "how many times have we tried this address"; a fixed 1 answered
     * "at least once", which is a different and much less useful question.
     *
     * An expired claim is deliberately NOT stealable here. Taking a lease over
     * while its previous holder may still be inside a provider call is how one
     * customer gets two copies of the same email. Reclaiming is a separate,
     * explicit sweep that first works out whether the provider was reached.
     *
     * @return array<string,mixed>|null the claimed row, or null if someone else holds it
     */
    public function claim(int $recipientId, string $token, int $leaseSeconds): ?array
    {
        $claimed = $this->scoped()
            ->where('id', '=', $recipientId)
            ->whereIn('send_status', ['pending', 'queued'])
            ->update([
                'send_status'           => 'sending',
                'claim_token'           => $token,
                'claimed_at'            => $this->now(),
                'claim_expires_at'      => $this->clock->now()
                    ->modify('+' . max(1, $leaseSeconds) . ' seconds')
                    ->format('Y-m-d H:i:s'),
                'provider_attempted_at' => null,
                'available_at'          => null,
                'attempt_count'         => new Expression('attempt_count + 1'),
            ]);

        if ($claimed === 0) {
            return null;
        }

        return $this->owned($recipientId, $token)->first();
    }

    /**
     * Record that the provider is about to be called.
     *
     * Written before the request goes out, never after. It is the difference
     * between "this worker died before it asked anybody to send anything",
     * which is safe to retry, and "a request was in flight when the worker
     * died", which is not — and once the process is gone there is no other way
     * to tell those two apart.
     */
    public function markProviderAttempt(int $recipientId, string $token): bool
    {
        return $this->owned($recipientId, $token)
            ->update(['provider_attempted_at' => $this->now()]) > 0;
    }

    /**
     * The provider accepted the message.
     *
     * `sent` means exactly that from here on: accepted by the provider for
     * delivery. It is not a delivery confirmation — that arrives later, from the
     * provider's own notifications, and lands on email_messages rather than here.
     */
    public function markSent(int $recipientId, string $token, int $messageId): bool
    {
        return $this->owned($recipientId, $token)->update([
            'send_status'      => 'sent',
            'email_message_id' => $messageId,
            'sent_at'          => $this->now(),
            'claim_token'      => null,
            'claim_expires_at' => null,
        ]) > 0;
    }

    /**
     * We asked the provider to send, and never found out whether it did.
     *
     * A timeout, a connection reset, or an acceptance we then failed to write
     * down. The row is parked rather than retried: resending risks a duplicate
     * to a real customer, and marking it failed would under-report a message
     * that may well have arrived. Somebody has to look, and `send:doctor` is
     * where they are told to look.
     */
    public function markOutcomeUnknown(int $recipientId, string $token, string $note): bool
    {
        return $this->owned($recipientId, $token)->update([
            'send_status'      => 'outcome_unknown',
            'last_error'       => substr($note, 0, 255),
            'claim_token'      => null,
            'claim_expires_at' => null,
        ]) > 0;
    }

    /**
     * A recipient that became ineligible between the snapshot and the send, or
     * whose campaign was cancelled underneath it.
     *
     * Recorded rather than silently dropped, so the campaign report can explain
     * it. The token is optional because the dispatcher also closes rows out in
     * bulk, where there is no claim to hold.
     */
    public function markSkipped(
        int $recipientId,
        string $eligibilityStatus,
        string $reason,
        ?string $token = null,
    ): bool {
        return $this->guarded($recipientId, $token)->update([
            'send_status'        => 'skipped',
            'eligibility_status' => $eligibilityStatus,
            'eligibility_reason' => $reason,
            'claim_token'        => null,
            'claim_expires_at'   => null,
        ]) > 0;
    }

    public function markFailed(int $recipientId, string $error, ?string $token = null): bool
    {
        return $this->guarded($recipientId, $token)->update([
            'send_status'      => 'failed',
            'last_error'       => substr($error, 0, 255),
            'claim_token'      => null,
            'claim_expires_at' => null,
        ]) > 0;
    }

    /**
     * Give the recipient back, for a reason that leaves no doubt nothing was
     * sent: a pause, or a provider that refused the message with a transient
     * error.
     *
     * `provider_attempted_at` is cleared along with the claim. Leaving it set
     * would make a later lease expiry read this row as "a request may have gone
     * out", and park a recipient we know perfectly well was never sent to.
     *
     * $availableAt carries the retry backoff so the scheduler cannot re-enqueue
     * ahead of it. attempt_count is left alone: the attempt did happen.
     */
    public function release(int $recipientId, string $token, ?string $availableAt = null): bool
    {
        return $this->owned($recipientId, $token)->update([
            'send_status'           => 'pending',
            'sent_at'               => null,
            'email_message_id'      => null,
            'claim_token'           => null,
            'claimed_at'            => null,
            'claim_expires_at'      => null,
            'provider_attempted_at' => null,
            'available_at'          => $availableAt,
        ]) > 0;
    }

    /**
     * Deal with claims whose holder never came back.
     *
     * Two populations, and conflating them is how a campaign sends twice:
     *
     *  - the provider was never reached (`provider_attempted_at` is NULL), so
     *    nothing went out and the row is safe to return to the pool;
     *  - a request was in flight, so the outcome is genuinely unknown and the
     *    row is parked for a person to reconcile. It is never retried
     *    automatically, however long it sits there.
     *
     * @return array{released:int,parked:int}
     */
    public function reclaimExpiredClaims(?int $campaignId = null): array
    {
        $now = $this->now();

        $scope = function () use ($campaignId, $now): QueryBuilder {
            $query = $this->scoped()
                ->where('send_status', '=', 'sending')
                ->where('claim_expires_at', '<', $now);

            if ($campaignId !== null) {
                $query->where('campaign_id', '=', $campaignId);
            }

            return $query;
        };

        $released = $scope()->whereNull('provider_attempted_at')->update([
            'send_status'      => 'pending',
            'claim_token'      => null,
            'claimed_at'       => null,
            'claim_expires_at' => null,
            'available_at'     => null,
        ]);

        $parked = $scope()->whereNotNull('provider_attempted_at')->update([
            'send_status'      => 'outcome_unknown',
            'last_error'       => 'Worker stopped while the provider request was in flight.',
            'claim_token'      => null,
            'claim_expires_at' => null,
        ]);

        return ['released' => $released, 'parked' => $parked];
    }

    /** Only the worker still holding the claim may advance the row. */
    private function owned(int $recipientId, string $token): QueryBuilder
    {
        return $this->scoped()
            ->where('id', '=', $recipientId)
            ->where('claim_token', '=', $token);
    }

    private function guarded(int $recipientId, ?string $token): QueryBuilder
    {
        return $token === null
            ? $this->scoped()->where('id', '=', $recipientId)
            : $this->owned($recipientId, $token);
    }

    /** @return array<string,mixed>|null */
    public function findForCampaign(int $campaignId, int $recipientId): ?array
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->where('id', '=', $recipientId)
            ->first();
    }

    /**
     * Work this campaign still owes.
     *
     * `sending` and `outcome_unknown` count as outstanding. A campaign with a
     * recipient mid-flight has not finished, and one with a recipient nobody can
     * account for has not finished either — reporting either as complete puts a
     * number in front of a customer that the data does not support.
     */
    public function remaining(int $campaignId): int
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->where('eligibility_status', '=', 'eligible')
            ->whereIn('send_status', ['pending', 'queued', 'sending', 'outcome_unknown'])
            ->count();
    }

    /** Recipients nobody can account for. Only a person can resolve these. */
    public function unreconciled(int $campaignId): int
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->where('send_status', '=', 'outcome_unknown')
            ->count();
    }

    /**
     * Put everything still outstanding back in the pool, so the dispatcher
     * enqueues it again.
     *
     * Used when a campaign resumes. A recipient can be sitting at `queued` with
     * no job behind it — the job was dead-lettered, the queue was flushed, or it
     * was acknowledged during the pause — and the dispatcher only ever looks at
     * `pending`, so without this those recipients are owed an email that nothing
     * will ever send. Where a job does still exist the result is a duplicate
     * job, which costs one atomic UPDATE and sends nothing.
     *
     * `budget_day` is deliberately left alone: this is the same send, and it
     * already holds its allowance.
     */
    public function requeueOutstanding(int $campaignId): int
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->where('eligibility_status', '=', 'eligible')
            ->where('send_status', '=', 'queued')
            ->update([
                'send_status'  => 'pending',
                'available_at' => null,
            ]);
    }

    /**
     * Close out everything still waiting, for a campaign that was cancelled.
     *
     * Rows already handed to the provider are left exactly as they are. Those
     * emails are gone, and no status written here can recall them.
     */
    public function cancelOutstanding(int $campaignId, string $reason): int
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->whereIn('send_status', ['pending', 'queued'])
            ->update([
                'send_status'        => 'skipped',
                'eligibility_status' => 'blocked',
                'eligibility_reason' => $reason,
                'claim_token'        => null,
                'claim_expires_at'   => null,
                'available_at'       => null,
            ]);
    }

    public function deleteForCampaign(int $campaignId): int
    {
        return $this->scoped()->where('campaign_id', '=', $campaignId)->delete();
    }
}
