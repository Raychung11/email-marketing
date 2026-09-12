<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Email providers are interchangeable.
 *
 * Amazon SES is the primary implementation; SendGrid, Brevo and Mailgun can be
 * added without touching the campaign engine, the queue or the compliance layer.
 * Nothing outside app/Mail refers to a provider by name.
 */
interface EmailProviderInterface extends ChannelProviderInterface
{
    public function send(OutboundMessage $message): SendResult;

    /** Is this sending identity (domain or address) usable? */
    public function validateIdentity(string $identity): IdentityStatus;

    /**
     * Live sending quota.
     *
     * Quotas differ per account and per Region, and sandbox accounts are heavily
     * restricted, so the worker throttles to whatever this reports rather than to
     * a number baked into config.
     */
    public function getQuota(): SendQuota;

    public function getReputationMetrics(): ReputationMetrics;

    /** DNS records the customer must publish to verify a domain. */
    public function dnsRecordsFor(string $domain): array;
}
