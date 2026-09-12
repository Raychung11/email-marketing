<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Logger;

/**
 * Development and test provider. Writes messages to a log file instead of
 * sending them, so the whole pipeline — compliance gate, queue, worker, status
 * transitions — can be exercised without touching a real mailbox.
 */
final class LogEmailProvider implements EmailProviderInterface
{
    /** @var array<int,OutboundMessage> */
    private array $sent = [];

    public function __construct(
        private readonly string $path,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function channel(): string
    {
        return 'email';
    }

    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): SendResult
    {
        $this->sent[] = $message;

        $id = 'log-' . bin2hex(random_bytes(12));

        $record = [
            'ts'            => gmdate('c'),
            'provider_id'   => $id,
            'to'            => $message->toEmail,
            'from'          => $message->fromEmail,
            'subject'       => $message->subject,
            'message_class' => $message->messageClass,
            'headers'       => $message->allHeaders(),
            'tags'          => $message->tags,
            // Body length only: dumping every rendered marketing email into a log
            // file would turn the log into a copy of the customer database.
            'html_bytes'    => strlen($message->htmlBody),
            'text_bytes'    => strlen($message->textBody),
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

        $this->logger?->debug('Email logged instead of sent', ['to' => $message->toEmail, 'id' => $id]);

        return SendResult::accepted($id);
    }

    public function validateIdentity(string $identity): IdentityStatus
    {
        return new IdentityStatus($identity, true, 'verified', 'verified', 'verified');
    }

    public function getQuota(): SendQuota
    {
        return new SendQuota(1_000_000.0, 100.0, 0.0, false);
    }

    public function getReputationMetrics(): ReputationMetrics
    {
        return new ReputationMetrics();
    }

    public function dnsRecordsFor(string $domain): array
    {
        return [];
    }

    /**
     * Messages captured in this process. Used by tests to assert what would have
     * been sent — and, just as importantly, what would not.
     *
     * @return array<int,OutboundMessage>
     */
    public function sentMessages(): array
    {
        return $this->sent;
    }

    public function flush(): void
    {
        $this->sent = [];
    }
}
