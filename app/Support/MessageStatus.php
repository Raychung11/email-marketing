<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Plain English for the `email_messages.status` column.
 *
 * The raw values are provider vocabulary — "soft_bounced", "complained",
 * "suppressed" — and a small business owner reading their outbox should not
 * have to learn them. The codes stay in the database and in the export; only
 * the screen translates.
 */
final class MessageStatus
{
    /** Every status the column can hold, in the order a send moves through them. */
    public const ALL = [
        'queued', 'sending', 'sent', 'delivered',
        'bounced', 'soft_bounced', 'complained', 'rejected', 'failed', 'suppressed',
    ];

    /**
     * A message counts as successfully handed to the provider once sent_at is
     * set. That is a different question from whether it arrived, which only the
     * provider can tell us, and only later.
     */
    public const HANDED_OVER = ['sent', 'delivered', 'bounced', 'soft_bounced', 'complained'];

    /** Nothing left this server for these, and nothing will. */
    public const NEVER_SENT = ['rejected', 'failed', 'suppressed'];

    public static function label(string $status): string
    {
        return match ($status) {
            'queued'       => 'Waiting to go out',
            'sending'      => 'Going out now',
            'sent'         => 'Sent — no word back yet',
            'delivered'    => 'Arrived',
            'bounced'      => 'Address does not exist',
            'soft_bounced' => 'Could not get in right now',
            'complained'   => 'Marked as spam',
            'rejected'     => 'Refused by the email service',
            'failed'       => 'Failed to send',
            'suppressed'   => 'Skipped — on your do-not-email list',
            default        => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /** A one-line explanation of what the customer should do about it, if anything. */
    public static function advice(string $status): string
    {
        return match ($status) {
            'queued', 'sending' => 'Nothing to do. The worker picks these up every minute.',
            'sent'              => 'Amazon accepted it. Delivery confirmation usually follows within a minute or two.',
            'delivered'         => 'Nothing to do.',
            'bounced'           => 'The address is dead. It has been added to your do-not-email list automatically.',
            'soft_bounced'      => 'A full mailbox or a temporary block. Repeated soft bounces turn into a suppression.',
            'complained'        => 'They pressed "spam". They are now suppressed. Too many of these puts your sending at risk.',
            'rejected'          => 'Amazon refused the message itself — usually an unverified sender or a sandbox restriction.',
            'failed'            => 'The send errored. The reason is shown next to the message.',
            'suppressed'        => 'Already on your do-not-email list, so it was never sent.',
            default             => '',
        };
    }

    /** The badge modifier the UI should use, so a failure never looks like a success. */
    public static function tone(string $status): string
    {
        return match ($status) {
            'delivered'                                     => 'badge--success',
            'bounced', 'complained', 'rejected', 'failed'   => 'badge--danger',
            'soft_bounced', 'suppressed'                    => 'badge--warning',
            default                                         => '',
        };
    }
}
