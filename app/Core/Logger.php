<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Line-oriented JSON logger with a per-request correlation id.
 *
 * The correlation id is also surfaced on user-facing error pages so a support
 * request can be tied to a log entry without exposing a stack trace.
 */
final class Logger
{
    public const DEBUG   = 'debug';
    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    private const LEVELS = [self::DEBUG => 0, self::INFO => 1, self::WARNING => 2, self::ERROR => 3];

    private string $correlationId;

    public function __construct(
        private readonly string $path,
        private readonly string $minLevel = self::INFO,
    ) {
        $this->correlationId = bin2hex(random_bytes(8));
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function setCorrelationId(string $id): void
    {
        $this->correlationId = $id;
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 1) < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }

        $record = [
            'ts'             => gmdate('c'),
            'level'          => $level,
            'message'        => $message,
            'correlation_id' => $this->correlationId,
            'context'        => $this->scrub($context),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Defence in depth: never let a credential reach the log file, even if a
     * caller passes a whole config or request array by mistake.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function scrub(array $context): array
    {
        $sensitive = [
            'password', 'password_confirmation', 'secret', 'token', 'api_key',
            'apikey', 'authorization', 'aws_secret_access_key', 'openai_api_key',
            'credit_card', 'cvv',
        ];

        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                $context[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->scrub($value);
            }
        }

        return $context;
    }
}
