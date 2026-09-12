<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * The channel abstraction that keeps the automation engine independent of email.
 *
 * When SMS and WhatsApp arrive (phase 7) they implement this, and automation
 * actions continue to call CommunicationService rather than an email provider.
 */
interface ChannelProviderInterface
{
    /** 'email' | 'sms' | 'whatsapp' */
    public function channel(): string;

    public function name(): string;

    /** Whether the provider is configured well enough to attempt a send. */
    public function isConfigured(): bool;
}
