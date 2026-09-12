<?php

declare(strict_types=1);

namespace App\Mail;

final class SendResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
        /** Whether retrying could plausibly succeed. */
        public readonly bool $retryable = false,
        public readonly ?string $errorCode = null,
    ) {
    }

    public static function accepted(string $providerMessageId): self
    {
        return new self(true, $providerMessageId);
    }

    /** A permanent failure. Do NOT retry: the address or the request is wrong. */
    public static function rejected(string $error, ?string $code = null): self
    {
        return new self(false, null, $error, false, $code);
    }

    /** A transient failure (throttling, a 5xx, a timeout). Retry with backoff. */
    public static function failed(string $error, ?string $code = null): self
    {
        return new self(false, null, $error, true, $code);
    }
}
