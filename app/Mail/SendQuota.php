<?php

declare(strict_types=1);

namespace App\Mail;

final class SendQuota
{
    public function __construct(
        public readonly float $max24HourSend,
        public readonly float $maxSendRate,
        public readonly float $sentLast24Hours,
        /** Sandbox accounts can only send to verified addresses. */
        public readonly bool $sandbox = false,
    ) {
    }

    public function remaining(): float
    {
        return max(0.0, $this->max24HourSend - $this->sentLast24Hours);
    }

    public function utilisation(): float
    {
        return $this->max24HourSend <= 0 ? 0.0 : $this->sentLast24Hours / $this->max24HourSend;
    }

    /** Delay in microseconds between sends to stay under the rate limit. */
    public function microsecondsBetweenSends(int $concurrency = 1): int
    {
        if ($this->maxSendRate <= 0) {
            return 0;
        }

        return (int) round((1_000_000 / $this->maxSendRate) * max(1, $concurrency));
    }
}
