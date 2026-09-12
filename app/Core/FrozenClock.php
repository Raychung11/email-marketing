<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Test double: a clock that does not move unless told to. */
final class FrozenClock extends Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-01-01 00:00:00')
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function travel(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function setTo(string $absolute): void
    {
        $this->now = new DateTimeImmutable($absolute, new DateTimeZone('UTC'));
    }
}
