<?php

declare(strict_types=1);

namespace App\Messaging;

/**
 * One text message, on its way out.
 *
 * Deliberately small. A text has a number, some words, and nothing else — no
 * subject, no HTML, no tracking pixel. Resisting the urge to give it those is
 * most of what keeps SMS from becoming email with a worse interface.
 */
final class OutboundText
{
    public function __construct(
        public readonly string $toNumber,
        public readonly string $body,
        public readonly string $channel = 'sms',
        public readonly ?string $senderId = null,
        public readonly ?string $messageUuid = null,
        /** For WhatsApp, which will not let you say anything unprompted. */
        public readonly ?string $templateName = null,
        /** @var array<int,string> */
        public readonly array $templateVariables = [],
    ) {
    }
}
