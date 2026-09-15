<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Core\Logger;
use App\Mail\SendResult;

/**
 * Development and test provider. Writes messages to a log instead of sending
 * them, so the whole path — consent, quiet hours, cost warning, the send itself
 * — can be exercised without anybody's phone buzzing.
 */
final class LogTextProvider implements TextProviderInterface
{
    /** @var array<int,OutboundText> */
    private array $sent = [];

    public function __construct(
        private readonly string $path,
        private readonly string $channel = 'sms',
        private readonly ?Logger $logger = null,
    ) {
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(OutboundText $message): SendResult
    {
        $this->sent[] = $message;

        $id = 'log-sms-' . bin2hex(random_bytes(10));

        $record = [
            'ts'      => gmdate('c'),
            'id'      => $id,
            'to'      => $message->toNumber,
            'channel' => $message->channel,
            // Length rather than content: a log that keeps every text a business
            // ever sent its customers is a liability with no operational value.
            'length'  => mb_strlen($message->body),
        ];

        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        @file_put_contents(
            $this->path,
            json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        return SendResult::accepted($id);
    }

    public function costPerMessage(string $countryCode): float
    {
        return 0.0;
    }

    public function maximumLength(): int
    {
        return 1600;
    }

    /** @return array<int,OutboundText> */
    public function sentMessages(): array
    {
        return $this->sent;
    }
}
