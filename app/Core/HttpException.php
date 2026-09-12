<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly string $errorCode = '',
    ) {
        parent::__construct($message === '' ? self::defaultMessage($statusCode) : $message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function notFound(string $message = ''): self
    {
        return new self(404, $message, 'NOT_FOUND');
    }

    public static function forbidden(string $message = ''): self
    {
        return new self(403, $message, 'FORBIDDEN');
    }

    public static function unauthorized(string $message = ''): self
    {
        return new self(401, $message, 'UNAUTHENTICATED');
    }

    public static function unprocessable(string $message = ''): self
    {
        return new self(422, $message, 'UNPROCESSABLE');
    }

    public static function tooManyRequests(string $message = ''): self
    {
        return new self(429, $message, 'RATE_LIMITED');
    }

    public static function tokenMismatch(string $message = ''): self
    {
        return new self(419, $message, 'CSRF_TOKEN_MISMATCH');
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400     => 'Bad request.',
            401     => 'Authentication required.',
            403     => 'You do not have permission to perform this action.',
            404     => 'The requested resource could not be found.',
            419     => 'Your session has expired. Please refresh and try again.',
            422     => 'The submitted data could not be processed.',
            429     => 'Too many requests. Please slow down.',
            default => 'An unexpected error occurred.',
        };
    }
}
