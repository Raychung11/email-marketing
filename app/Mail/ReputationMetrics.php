<?php

declare(strict_types=1);

namespace App\Mail;

final class ReputationMetrics
{
    public function __construct(
        public readonly float $bounceRate = 0.0,
        public readonly float $complaintRate = 0.0,
        public readonly bool $sendingEnabled = true,
        public readonly ?string $enforcementStatus = null,
    ) {
    }
}
