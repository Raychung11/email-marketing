<?php

declare(strict_types=1);

namespace App\Automation\Actions;

use App\Services\TextMessageService;

/**
 * Send a text from a journey.
 *
 * The same shape as SendEmailAction, and for the same reason: one service does
 * the checking, and there is no second path with looser rules. What differs is
 * which consent is checked — `sms`, not `email` — because agreeing to a
 * newsletter is not agreeing to be texted, and a journey is exactly where that
 * distinction would otherwise quietly get lost.
 */
final class SendTextAction implements Action
{
    public function __construct(private readonly TextMessageService $texts)
    {
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $context
     */
    public function perform(string $actionType, array $config, array $contact, array $context): ActionResult
    {
        $body = trim((string) ($config['body'] ?? ''));

        if ($body === '') {
            return ActionResult::failed('This step has no message written on it.');
        }

        if (!$this->texts->isAvailable()) {
            return ActionResult::skipped('Text messaging is not set up on this account.');
        }

        $result = $this->texts->send($contact, $body);

        if ($result['sent']) {
            return ActionResult::done('Text sent.');
        }

        // Quiet hours are a hold rather than a refusal: the run carries on, and
        // the message goes out when the window opens.
        if ($result['reason'] === 'QUIET_HOURS') {
            return ActionResult::skipped($result['message'], 'QUIET_HOURS');
        }

        return ActionResult::blocked((string) $result['reason'], $result['message']);
    }
}
