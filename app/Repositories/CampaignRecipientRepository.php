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
    public function pendingBatches(int $campaignId, int $batchSize = 500, int $maxAttempts = 0): Generator
    {
        $lastId = 0;
        $now    = $this->now();

        do {
            $query = $this->scoped()
                ->where('campaign_id', '=', $campaignId)
                ->where('eligibility_status', '=', 'eligible')
                ->where('send_status', '=', 'pending')
                // A recipient waiting out its backoff is not work yet. Omitting
                // this is what let the scheduler walk straight through the
                // retry delay the queue had just imposed.
                ->whereGroup(static function ($group) use ($now): void {
                    $group->whereNull('retry_after')
                          ->where('retry_after', '<=', $now, 'or');
                })
                ->where('id', '>', $lastId);

            if ($maxAttempts > 0) {
                // Belt as well as braces: failExhausted() closes these out, and
                // this makes sure one is never handed out in the window before
                // it runs.
                $query->where('attempt_count', '<', $maxAttempts);
            }

            $rows = $query
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
     * How long a worker may hold a recipient before the lease lapses.
     *
     * Longer than any realistic provider call plus rendering, and short enough
     * that a worker killed by the shared host does not strand the row for an
     * afternoon. A send that genuinely takes longer than this is a send whose
     * outcome nobody knows anyway, which is exactly what the sweep records.
     */
    public const LEASE_SECONDS = 300;

    /**
     * Claim a recipient for sending.
     *
     * WHAT THIS USED TO DO, AND WHY IT LOST EMAIL.
     *
     * It set `send_status = 'sent'` here — before rendering, before the message
     * row, before the provider had been called at all. Anything that threw in
     * between left a row claiming an email had been sent that nobody had tried
     * to send; and since the claim only accepts `pending` or `queued`, the
     * redelivered job could never pick it up again. The send was lost in
     * silence and the campaign reported success.
     *
     * Now it moves the row to `sending` and stamps an ownership token. `sent`
     * is written in one place only: after the provider has accepted the
     * message.
     *
     * The UPDATE is the whole concurrency control. Two workers racing the same
     * recipient both run it; the status predicate means exactly one row is
     * affected, and the loser is told so by the return value rather than by
     * sending a second copy.
     *
     * attempt_count is incremented rather than set to 1 — it used to be a
     * constant, so "how many times have we tried this" was unanswerable.
     */
    public function claimForSending(int $recipientId, string $token, ?int $leaseSeconds = null): bool
    {
        $now = $this->now();

        return $this->connection->execute(
            "UPDATE campaign_recipients
                SET send_status = 'sending',
                    lock_token = ?,
                    claimed_at = ?,
                    lease_expires_at = ?,
                    last_attempt_at = ?,
                    attempt_count = attempt_count + 1,
                    provider_attempted_at = NULL,
                    last_error = NULL
              WHERE organisation_id = ?
                AND id = ?
                AND send_status IN ('pending', 'queued')",
            [
                $token,
                $now,
                $this->leaseUntil($leaseSeconds ?? self::LEASE_SECONDS),
                $now,
                $this->organisationId(),
                $recipientId,
            ]
        ) > 0;
    }

    /**
     * Record that the provider is about to be called.
     *
     * The one write that makes a dead worker's row interpretable. Everything
     * before this point is provably local: if the process disappears, nothing
     * left the building and the recipient can go back on the queue. After it,
     * the message may be in somebody's inbox and only a human may decide.
     */
    public function markProviderAttempted(int $recipientId, string $token): bool
    {
        return $this->ownedUpdate($recipientId, $token, ['provider_attempted_at' => $this->now()]);
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

    /**
     * The provider accepted the handoff.
     *
     * ACCEPTED, NOT DELIVERED. The provider has taken responsibility for the
     * message; whether it reached a mailbox is a separate fact that arrives
     * later by webhook. Nothing should read this column as proof of delivery.
     *
     * Guarded by the token: a worker whose lease lapsed and whose row has since
     * been taken over by somebody else must not be able to stamp `sent` over
     * the newer holder's work.
     */
    public function markSent(int $recipientId, string $token, int $messageId): bool
    {
        return $this->ownedUpdate($recipientId, $token, [
            'send_status'      => 'sent',
            'email_message_id' => $messageId,
            'sent_at'          => $this->now(),
            'lock_token'       => null,
            'lease_expires_at' => null,
        ]);
    }

    /**
     * A recipient that became ineligible between the snapshot and the send.
     *
     * This is the path the second compliance check takes, and it is recorded
     * rather than silently dropped so the campaign report can explain it.
     */
    public function markSkipped(int $recipientId, string $token, string $eligibilityStatus, string $reason): bool
    {
        return $this->ownedUpdate($recipientId, $token, [
            'send_status'        => 'skipped',
            'eligibility_status' => $eligibilityStatus,
            'eligibility_reason' => $reason,
            'lock_token'         => null,
            'lease_expires_at'   => null,
        ]);
    }

    public function markFailed(int $recipientId, string $token, string $error): bool
    {
        return $this->ownedUpdate($recipientId, $token, [
            'send_status'      => 'failed',
            'last_error'       => substr($error, 0, 255),
            'lock_token'       => null,
            'lease_expires_at' => null,
        ]);
    }

    /**
     * The request left this process and the answer never came back.
     *
     * A timeout, or a worker killed between the provider accepting and this
     * database hearing about it. The message may be in somebody's inbox and it
     * may not, and no amount of local state can tell which — so the row says
     * exactly that and stops. Nothing automatic resends it; the diagnostic
     * command lists it and a person reconciles it against the provider.
     *
     * Pretending otherwise is the failure mode this whole change exists to end:
     * guessing "probably sent" loses real email, guessing "probably not" sends
     * somebody a second copy.
     */
    public function markOutcomeUnknown(int $recipientId, string $token, string $note): bool
    {
        return $this->ownedUpdate($recipientId, $token, [
            'send_status'      => 'outcome_unknown',
            'last_error'       => substr($note, 0, 255),
            'lock_token'       => null,
            'lease_expires_at' => null,
        ]);
    }

    /**
     * Return a recipient to the pool so a retry — or a resumed campaign — can
     * claim it again.
     *
     * Only ever called where the provider demonstrably has not been given the
     * message: a transient failure reported before the handoff, or a pause.
     * The message link is cleared because the retry creates its own
     * `email_messages` row, and leaving the recipient pointing at a failed
     * handoff would make the campaign report cite a message that never went.
     */
    public function release(int $recipientId, string $token, int $retryAfterSeconds = 0): bool
    {
        return $this->ownedUpdate($recipientId, $token, [
            'send_status'           => 'pending',
            'sent_at'               => null,
            'email_message_id'      => null,
            'lock_token'            => null,
            'claimed_at'            => null,
            'lease_expires_at'      => null,
            'provider_attempted_at' => null,
            // The same backoff the queue is about to apply to the job. Without
            // it the scheduler re-queues this recipient on its next tick and
            // the wait the provider asked for never happens.
            'retry_after'           => $retryAfterSeconds > 0 ? $this->leaseUntil($retryAfterSeconds) : null,
        ]);
    }

    /**
     * Close out recipients that have used every attempt they are going to get.
     *
     * Without this the scheduler enqueues them for ever: the row is `pending`,
     * so it looks like work; the job claims it, fails, and releases it back.
     * The queue's own retry ceiling never applies, because each tick creates a
     * brand new first-attempt job.
     */
    public function failExhausted(int $campaignId, int $maxAttempts): int
    {
        return $this->connection->execute(
            "UPDATE campaign_recipients
                SET send_status = 'failed',
                    last_error = ?,
                    lock_token = NULL,
                    lease_expires_at = NULL
              WHERE organisation_id = ?
                AND campaign_id = ?
                AND send_status = 'pending'
                AND attempt_count >= ?",
            [
                'Gave up after ' . $maxAttempts . ' attempts.',
                $this->organisationId(),
                $campaignId,
                $maxAttempts,
            ]
        );
    }

    /**
     * Deal with leases nobody is coming back for.
     *
     * Runs across tenants, like the scheduler's own scan, because a worker that
     * died did not die per-organisation. It is the reason a row can never sit
     * in `sending` for ever.
     *
     * The split is the point, and it rests entirely on provider_attempted_at:
     *
     *   never called  →  back to `pending`. We know the request did not leave,
     *                    so re-queueing it cannot duplicate anything.
     *   may have been →  `outcome_unknown`. Automatic recovery here is how you
     *                    send somebody the same email twice.
     *
     * @return array{recovered:int,unknown:int}
     */
    public function sweepExpiredLeases(int $limit = 500): array
    {
        $now = $this->now();

        $recovered = $this->connection->execute(
            "UPDATE campaign_recipients
                SET send_status = 'pending',
                    lock_token = NULL,
                    claimed_at = NULL,
                    lease_expires_at = NULL,
                    last_error = 'Lease expired before the provider was called; returned to the queue.'
              WHERE send_status = 'sending'
                AND lease_expires_at IS NOT NULL
                AND lease_expires_at < ?
                AND provider_attempted_at IS NULL",
            [$now]
        );

        $unknown = $this->connection->execute(
            "UPDATE campaign_recipients
                SET send_status = 'outcome_unknown',
                    lock_token = NULL,
                    lease_expires_at = NULL,
                    last_error = 'Worker stopped after the provider was called; outcome never confirmed.'
              WHERE send_status = 'sending'
                AND lease_expires_at IS NOT NULL
                AND lease_expires_at < ?
                AND provider_attempted_at IS NOT NULL",
            [$now]
        );

        return ['recovered' => $recovered, 'unknown' => $unknown];
    }

    /**
     * Put a paused campaign's queued and in-flight work back where a resume can
     * find it.
     *
     * `queued` rows are the ones the old code lost: the job returned early, the
     * worker acknowledged it away, and the row sat in a state the dispatcher
     * never looks at again, because it only re-queues `pending`.
     *
     * ONLY `queued`. A `sending` row belongs to a worker that is running right
     * now, and taking it away from them is how pause causes the duplicate it
     * was pressed to prevent: the sweep would clear the lock, the worker would
     * carry on and hand the message to the provider anyway, and the row — back
     * at `pending` — would be sent a second time on resume. In-flight rows are
     * left to finish, or to the lease sweep if their worker never comes back.
     */
    public function releaseForPause(int $campaignId): int
    {
        return $this->connection->execute(
            "UPDATE campaign_recipients
                SET send_status = 'pending',
                    lock_token = NULL,
                    claimed_at = NULL,
                    lease_expires_at = NULL,
                    queued_at = NULL
              WHERE organisation_id = ?
                AND campaign_id = ?
                AND send_status = 'queued'",
            [$this->organisationId(), $campaignId]
        );
    }

    /**
     * Close out a cancelled campaign's outstanding work.
     *
     * Cancelled is not paused: nothing here may ever be sent again, so the rows
     * are closed rather than returned to the pool. Anything already handed to
     * the provider is left exactly as it is — it has gone, and no status here
     * can recall it.
     */
    public function skipForCancellation(int $campaignId, string $reason): int
    {
        return $this->connection->execute(
            "UPDATE campaign_recipients
                SET send_status = 'skipped',
                    eligibility_status = 'blocked',
                    eligibility_reason = ?,
                    lock_token = NULL,
                    lease_expires_at = NULL
              WHERE organisation_id = ?
                AND campaign_id = ?
                AND send_status IN ('pending', 'queued')",
            [$reason, $this->organisationId(), $campaignId]
        );
    }

    /**
     * One guarded write, used by every state change after the claim.
     *
     * The token is the whole guard. Without it a worker whose lease lapsed —
     * one paused by the host, or stuck in a provider call — could wake up and
     * stamp its stale conclusion over the row a second worker has since
     * legitimately taken and finished.
     *
     * @param array<string,mixed> $values
     */
    private function ownedUpdate(int $recipientId, string $token, array $values): bool
    {
        return $this->scoped()
            ->where('id', '=', $recipientId)
            ->where('lock_token', '=', $token)
            ->update($values) > 0;
    }

    private function leaseUntil(int $seconds): string
    {
        return $this->clock->now()
            ->modify('+' . max(1, $seconds) . ' seconds')
            ->format('Y-m-d H:i:s');
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
     * Recipients this campaign has not finished with.
     *
     * `sending` and `outcome_unknown` are counted alongside `pending` and
     * `queued`, and that is the whole point: a campaign is not finished while a
     * worker still holds a recipient, and it is certainly not finished while
     * one of its messages is in a state nobody can resolve. The old count knew
     * only about `pending` and `queued`, so a campaign could report itself
     * complete with work still in flight.
     *
     * An `outcome_unknown` row holds the campaign open until a person settles
     * it against the provider. That looks stuck, and it is — stuck is the
     * honest reading, and the diagnostic command exists to surface exactly
     * these. Once reconciled to `sent` or `failed` the count falls to zero and
     * the next tick completes the campaign normally.
     */
    public function remaining(int $campaignId): int
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->where('eligibility_status', '=', 'eligible')
            ->whereIn('send_status', ['pending', 'queued', 'sending', 'outcome_unknown'])
            ->count();
    }

    /**
     * The ones a person has to settle by hand, counted separately.
     *
     * `remaining()` says a campaign is not finished; this says why, and keeps
     * "still working" apart from "waiting for somebody" on the campaign page
     * and in the diagnostic report.
     */
    public function awaitingReconciliation(int $campaignId): int
    {
        return $this->scoped()
            ->where('campaign_id', '=', $campaignId)
            ->where('send_status', '=', 'outcome_unknown')
            ->count();
    }

    public function deleteForCampaign(int $campaignId): int
    {
        return $this->scoped()->where('campaign_id', '=', $campaignId)->delete();
    }
}
