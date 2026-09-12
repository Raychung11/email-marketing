<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * All storage is UTC. Display conversion happens at the edge using the
 * organisation's configured timezone — never in SQL, never in business logic.
 *
 * The clock is injectable so time-dependent rules (consent age, inactivity
 * windows, retry backoff) are testable without sleeping.
 */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function nowString(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public function timestamp(): int
    {
        return $this->now()->getTimestamp();
    }

    public function agoString(int $days): string
    {
        return $this->now()->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    public function toOrganisationTime(string $utc, string $timezone): DateTimeImmutable
    {
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return $date->setTimezone(new DateTimeZone($timezone));
    }

    public function display(string $utc, string $timezone, string $format = 'j M Y, g:ia'): string
    {
        if ($utc === '') {
            return '';
        }

        try {
            return $this->toOrganisationTime($utc, $timezone)->format($format);
        } catch (\Throwable) {
            return $utc;
        }
    }
}
