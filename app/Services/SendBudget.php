<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Repositories\CampaignRecipientRepository;
use App\Repositories\EmailMessageRepository;
use DateTimeZone;
use Throwable;

/**
 * How much of today's sending allowance an organisation has left.
 *
 * WHY THIS EXISTS AS ONE OBJECT.
 *
 * The question was answered in two places with two different answers.
 *
 * CampaignDispatcher counted messages created since midnight in the
 * organisation's timezone, converted to UTC. CampaignValidator counted messages
 * created since `now()->format('Y-m-d 00:00:00')` — the clock is UTC, so for a
 * tenant in Kuala Lumpur (+8) the two windows differed by eight hours. The
 * validator could refuse a campaign as over the limit that the dispatcher would
 * happily send, or clear one the dispatcher then stalled on. Both readings
 * cannot be right and neither was reliably the one the customer sees in the UI.
 *
 * WHY "USED" IS MORE THAN "SENT".
 *
 * The dispatcher's budget was the daily limit minus the messages already handed
 * to the provider. But the dispatcher does not send — it enqueues, and the
 * message row is written later by the worker. So a tick could enqueue a full
 * day's allowance, and the next tick fifteen minutes later would look at a
 * counter that had barely moved and enqueue the allowance again. Every tick
 * until the workers caught up handed out the same budget. A tenant limited to
 * 500 a day could have tens of thousands of jobs on the queue, and the workers
 * would send all of them: the limit bounded one tick rather than one day.
 *
 * So the allowance is spent at the moment it is committed, not at the moment it
 * is used. A recipient sitting in `queued` has a job that will send it; that is
 * as spent as a message already handed over.
 *
 * TWO DELIBERATE IMPRECISIONS, both in the direction of sending less.
 *
 *  1. A recipient that is `sending` is counted by its message row, which the
 *     worker writes before calling the provider. Between claiming the recipient
 *     and writing that row it is counted by neither. The gap is milliseconds
 *     per in-flight send and bounded by worker concurrency.
 *
 *  2. Outstanding commitments are counted whatever day they were made on. Work
 *     enqueued yesterday and still queued is still going to be sent, and the
 *     honest question is "how much more may I commit right now", not "how much
 *     was committed since midnight". A queue that is backed up past a whole
 *     day's allowance stops new work, which is the correct response to it.
 *
 * A failed handoff still counts. The limit is an anti-abuse ceiling on
 * attempts, not a delivery guarantee, and the other reading — count only what
 * succeeded — lets a tenant with a failing provider retry past their limit.
 */
final class SendBudget
{
    /** Returned when the organisation has no daily limit configured. */
    public const UNLIMITED = PHP_INT_MAX;

    public function __construct(
        private readonly EmailMessageRepository $messages,
        private readonly CampaignRecipientRepository $recipients,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Midnight in the organisation's own timezone, as a UTC timestamp.
     *
     * The daily limit is a calendar-day limit as the customer understands it —
     * what their plan says and what the UI shows them — so the window has to be
     * their day, not the server's.
     *
     * @param array<string,mixed> $organisation
     */
    public function dayStartUtc(array $organisation): string
    {
        $name = (string) ($organisation['timezone'] ?? '') ?: 'UTC';

        try {
            $zone = new DateTimeZone($name);
        } catch (Throwable) {
            // A stored timezone the platform no longer recognises must not stop
            // an organisation sending. UTC is the same value the column
            // defaults to.
            $zone = new DateTimeZone('UTC');
        }

        return $this->clock->now()
            ->setTimezone($zone)
            ->setTime(0, 0)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Today's allowance, broken down so an error message can explain itself.
     *
     * Requires the organisation to be the bound tenant: both counts are
     * organisation-scoped through the repositories rather than by a filter
     * passed in here.
     *
     * @param  array<string,mixed> $organisation
     * @return array{handed_off:int,committed:int,total:int}
     */
    public function usedToday(array $organisation): array
    {
        $handedOff = $this->messages->marketingSentSince($this->dayStartUtc($organisation));
        $committed = $this->recipients->committedSends();

        return [
            'handed_off' => $handedOff,
            'committed'  => $committed,
            'total'      => $handedOff + $committed,
        ];
    }

    /**
     * How many more sends this organisation may commit to today.
     *
     * self::UNLIMITED when no daily limit is set, so callers can min() this
     * against the provider's quota without special-casing it.
     *
     * @param array<string,mixed> $organisation
     */
    public function remainingToday(array $organisation): int
    {
        $limit = (int) ($organisation['daily_send_limit'] ?? 0);

        if ($limit <= 0) {
            return self::UNLIMITED;
        }

        return max(0, $limit - $this->usedToday($organisation)['total']);
    }
}
