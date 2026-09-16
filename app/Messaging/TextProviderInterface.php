<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Mail\ChannelProviderInterface;
use App\Mail\SendResult;

/**
 * SMS and WhatsApp providers are interchangeable, exactly as email ones are.
 *
 * Nothing outside app/Messaging names a vendor.
 */
interface TextProviderInterface extends ChannelProviderInterface
{
    public function send(OutboundText $message): SendResult;

    /**
     * What one message will cost, in the organisation's currency.
     *
     * Email is effectively free per message and texts are not, so the product
     * has to be able to say "this will cost about £34" before somebody presses
     * send on a list of 900 people. A channel that hides its cost until the
     * invoice is a channel that loses customers.
     */
    public function costPerMessage(string $countryCode): float;

    /** Longest body the provider will take without splitting or truncating. */
    public function maximumLength(): int;
}
