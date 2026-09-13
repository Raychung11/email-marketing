<?php

declare(strict_types=1);

namespace App\Automation\Actions;

/**
 * One thing a journey can do to, or about, a contact.
 *
 * Actions return an outcome rather than throwing for ordinary refusals: "this
 * contact unsubscribed, so we did not email them" is a normal result that
 * belongs in the run log, not an exception that fails the whole journey. A
 * thrown exception means something is actually broken.
 */
interface Action
{
    /**
     * @param array<string,mixed> $config  the node's stored settings
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $context the run's accumulated context
     */
    public function perform(string $actionType, array $config, array $contact, array $context): ActionResult;
}
