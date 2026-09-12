<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A single message ready to hand to a provider.
 *
 * One recipient per message, always. Bulk marketing is never sent with multiple
 * To or with BCC: it breaks per-recipient personalisation, per-recipient
 * unsubscribe links, and per-recipient delivery tracking, and it leaks
 * recipients to each other.
 */
final class OutboundMessage
{
    /**
     * @param array<string,string> $headers
     * @param array<string,string> $tags provider message tags for reporting
     */
    public function __construct(
        public readonly string $toEmail,
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly string $textBody = '',
        public readonly string $fromEmail = '',
        public readonly string $fromName = '',
        public readonly string $replyTo = '',
        public readonly ?string $toName = null,
        public readonly array $headers = [],
        public readonly array $tags = [],
        public readonly string $messageClass = 'marketing',
        public readonly ?string $configurationSet = null,
        public readonly ?string $messageUuid = null,
    ) {
    }

    public function fromHeader(): string
    {
        if ($this->fromName === '') {
            return $this->fromEmail;
        }

        // Encode the display name so a name containing a comma or a quote cannot
        // alter the header structure.
        return sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($this->fromName), $this->fromEmail);
    }

    /** @return array<string,string> */
    public function allHeaders(): array
    {
        return $this->headers;
    }

    public function withHeaders(array $headers): self
    {
        return new self(
            $this->toEmail,
            $this->subject,
            $this->htmlBody,
            $this->textBody,
            $this->fromEmail,
            $this->fromName,
            $this->replyTo,
            $this->toName,
            array_merge($this->headers, $headers),
            $this->tags,
            $this->messageClass,
            $this->configurationSet,
            $this->messageUuid,
        );
    }
}
