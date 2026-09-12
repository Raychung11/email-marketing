<?php

declare(strict_types=1);

namespace App\Queue;

use RuntimeException;

/**
 * Thrown by a job that cannot succeed on retry — a suppressed recipient, a
 * deleted campaign, a rejected address. The worker dead-letters it immediately
 * rather than burning four attempts and provider reputation.
 */
final class PermanentFailure extends RuntimeException
{
}
