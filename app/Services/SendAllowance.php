<?php

declare(strict_types=1);

namespace App\Services;

/**
 * How much one scheduler tick may enqueue for one organisation.
 *
 * Three numbers that are routinely confused and are not interchangeable:
 *
 *  - $tickCap is the provider's rate, converted into "how many jobs it is
 *    sensible to put on the queue in one minute". It is not a send rate: the
 *    workers decide when the messages actually leave, and the worker paces
 *    itself separately. Bounding the queue only keeps the provider's limit
 *    roughly intact; it does not enforce it.
 *  - $dailyLimit is the tenant's own allowance for their calendar day, which is
 *    what their plan promises and what their dashboard shows.
 *  - $dayKey names that calendar day in the tenant's timezone, because at 09:00
 *    in Kuala Lumpur yesterday's UTC date is still running.
 */
final class SendAllowance
{
    public function __construct(
        public readonly int $tickCap,
        public readonly int $dailyLimit,
        public readonly string $dayKey,
    ) {
    }

    public function minus(int $queued): self
    {
        return new self(max(0, $this->tickCap - $queued), $this->dailyLimit, $this->dayKey);
    }

    public function exhausted(): bool
    {
        return $this->tickCap <= 0;
    }
}
