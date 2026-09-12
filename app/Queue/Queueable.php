<?php

declare(strict_types=1);

namespace App\Queue;

interface Queueable
{
    /**
     * Run the job.
     *
     * @param array<string,mixed> $payload
     * @throws PermanentFailure when retrying cannot help
     */
    public function handle(array $payload): void;
}
