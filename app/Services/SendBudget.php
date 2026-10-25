<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Database\Connection;
use DateTimeZone;
use Throwable;

/**
 * The daily send allowance, as a reservation rather than a tally.
 *
 * The distinction is the whole point. Counting messages that have already been
 * created answers "how much have we sent", and the scheduler needs the answer to
 * a different question: "how much have we already committed to sending". Work
 * sitting on the queue is committed and not yet counted, so a tally lets two
 * consecutive ticks hand out the same thousand sends, and the tenant's limit is
 * only honoured if the workers happen to keep up with the scheduler.
 *
 * TWO THINGS TO KNOW ABOUT THE COUNTER.
 *
 * It is keyed by the organisation's own calendar day, not UTC, because that is
 * the day their plan is written in and the day their dashboard shows.
 *
 * It is reconciled from campaign_recipients at the start of every tick rather
 * than only adjusted incrementally. A reservation taken by a process that then
 * died would otherwise be held forever, and a tenant would lose allowance a
 * little at a time with nothing to show for it. Reconciling first and then
 * reserving atomically within the tick gives both safety and self-healing.
 *
 * WHAT IT DOES NOT COVER. This counter tracks campaign sends. Transactional mail
 * is not marketing and is not subject to the limit; other marketing paths that
 * do not go through campaign_recipients are charged against the same limit by
 * `consumedOutsideCampaigns()`, which reads them from email_messages. Keeping
 * the two apart is deliberate: one of them has a row to reserve against and the
 * other does not, and pretending otherwise would make the counter unreconcilable.
 */
final class SendBudget
{
    /**
     * Recipient states that are holding a reservation.
     *
     * `pending` is in the list because a recipient only carries a budget_day
     * once it has been dispatched at least once, so a pending row with a day on
     * it is a retry waiting its turn — the same send, still owed, still holding
     * the allowance it was given. Only `skipped` and `failed` give it back:
     * those are the two outcomes where no email will ever be sent.
     */
    private const HOLDING = ['pending', 'queued', 'sending', 'sent', 'outcome_unknown'];

    private const MAX_CAS_ATTEMPTS = 8;

    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
    ) {
    }

    /** The organisation's own calendar day, as YYYY-MM-DD. */
    public function dayKey(string $timezone): string
    {
        try {
            return $this->clock->now()->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
        } catch (Throwable) {
            return $this->clock->now()->format('Y-m-d');
        }
    }

    /** Midnight of that day, back in UTC, for querying tables stored in UTC. */
    public function dayStartUtc(string $timezone): string
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable) {
            $zone = new DateTimeZone('UTC');
        }

        return $this->clock->now()
            ->setTimezone($zone)
            ->setTime(0, 0)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Take up to $want of the remaining allowance.
     *
     * Compare-and-set rather than a lock: read the counter, then increment it
     * only if it still holds what was read. Two schedulers racing produce one
     * winner and one retry, never two grants of the same allowance, and it needs
     * nothing from the engine that MySQL, MariaDB and SQLite do not all have.
     *
     * @return int how many were actually granted; 0 means the day is spent
     */
    public function reserve(int $organisationId, string $dayKey, int $limit, int $want): int
    {
        if ($want <= 0 || $limit <= 0) {
            return 0;
        }

        $this->ensureRow($organisationId, $dayKey);

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $current = $this->reserved($organisationId, $dayKey);
            $grant   = min($want, max(0, $limit - $current));

            if ($grant === 0) {
                return 0;
            }

            $taken = $this->connection->execute(
                'UPDATE send_reservations SET reserved = reserved + ?, updated_at = ?
                 WHERE organisation_id = ? AND day_key = ? AND reserved = ?',
                [$grant, $this->clock->nowString(), $organisationId, $dayKey, $current]
            );

            if ($taken > 0) {
                return $grant;
            }
        }

        // Losing the race eight times running means something is hammering this
        // row. Granting nothing is the safe answer: the next tick will try again.
        return 0;
    }

    /** Hand back an allowance that was taken but will not be used. */
    public function release(int $organisationId, string $dayKey, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $this->connection->execute(
            'UPDATE send_reservations SET reserved = CASE WHEN reserved < ? THEN 0 ELSE reserved - ? END,
                    updated_at = ?
             WHERE organisation_id = ? AND day_key = ?',
            [$count, $count, $this->clock->nowString(), $organisationId, $dayKey]
        );
    }

    public function reserved(int $organisationId, string $dayKey): int
    {
        return (int) $this->connection->scalar(
            'SELECT COALESCE(reserved, 0) FROM send_reservations WHERE organisation_id = ? AND day_key = ?',
            [$organisationId, $dayKey]
        );
    }

    /**
     * Rebuild the counter from the recipients that are actually holding it.
     *
     * Run at the start of a tick, before anything is dispatched, so the window
     * where a recipient has been enqueued but not yet marked is closed.
     */
    public function reconcile(int $organisationId, string $dayKey): int
    {
        $held = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM campaign_recipients
             WHERE organisation_id = ? AND budget_day = ?
               AND send_status IN (' . $this->placeholders(self::HOLDING) . ')',
            array_merge([$organisationId, $dayKey], self::HOLDING)
        );

        $this->ensureRow($organisationId, $dayKey);

        $this->connection->execute(
            'UPDATE send_reservations SET reserved = ?, updated_at = ?
             WHERE organisation_id = ? AND day_key = ?',
            [$held, $this->clock->nowString(), $organisationId, $dayKey]
        );

        return $held;
    }

    /**
     * Move yesterday's unfinished holds onto today.
     *
     * A send enqueued at 23:58 and still waiting at 00:02 is going out today,
     * and today's limit is what the customer will be told applies to it.
     *
     * Re-keyed rather than cleared, which is the version that actually works:
     * clearing the day would leave a queued recipient holding real work and
     * counting towards nothing, so the new day would hand its whole allowance
     * out again on top of it. Carrying the key forward keeps every outstanding
     * send attached to exactly one day's budget.
     *
     * Rows already `sent` keep yesterday's key, because that is the day they
     * were sent on and rewriting it would falsify the count for both days.
     */
    public function carryOverHolds(int $organisationId, string $dayKey): int
    {
        return $this->connection->execute(
            "UPDATE campaign_recipients SET budget_day = ?
             WHERE organisation_id = ? AND budget_day IS NOT NULL AND budget_day < ?
               AND send_status IN ('pending', 'queued', 'sending', 'outcome_unknown')",
            [$dayKey, $organisationId, $dayKey]
        );
    }

    /**
     * Marketing email charged against the same daily limit that never had a
     * campaign_recipients row to reserve against — automations, one-off sends.
     */
    public function consumedOutsideCampaigns(int $organisationId, string $timezone): int
    {
        return (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM email_messages
             WHERE organisation_id = ? AND message_class = 'marketing'
               AND campaign_id IS NULL AND created_at >= ?",
            [$organisationId, $this->dayStartUtc($timezone)]
        );
    }

    /** Drop counters for days nobody will ask about again. */
    public function prune(int $keepDays = 30): int
    {
        $cutoff = $this->clock->now()->modify('-' . max(1, $keepDays) . ' days')->format('Y-m-d');

        return $this->connection->execute(
            'DELETE FROM send_reservations WHERE day_key < ?',
            [$cutoff]
        );
    }

    private function ensureRow(int $organisationId, string $dayKey): void
    {
        if ($this->connection->scalar(
            'SELECT id FROM send_reservations WHERE organisation_id = ? AND day_key = ?',
            [$organisationId, $dayKey]
        ) !== null) {
            return;
        }

        try {
            $now = $this->clock->nowString();

            $this->connection->table('send_reservations')->insert([
                'organisation_id' => $organisationId,
                'day_key'         => $dayKey,
                'reserved'        => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        } catch (Throwable) {
            // Another process inserted it between the check and the insert. The
            // unique index did its job; there is nothing to repair.
        }
    }

    /** @param array<int,string> $values */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
