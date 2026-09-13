<?php

declare(strict_types=1);

namespace App\Automation\Actions;

use App\Core\Config;
use App\Repositories\ContactRepository;
use App\Services\ActivityService;

/**
 * Change one field on a contact.
 *
 * The field must be on the whitelist in config/automation.php. That list has
 * four entries and deliberately excludes the email address, the consent state
 * and the suppression cache: an unattended process at 3am is the worst possible
 * place to move those, and each of them has a path that knows how to do it
 * properly, with evidence.
 */
final class UpdateContactAction implements Action
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly ActivityService $activity,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $context
     */
    public function perform(string $actionType, array $config, array $contact, array $context): ActionResult
    {
        /** @var array<int,string> $allowed */
        $allowed = (array) $this->config->get('automation.updatable_fields', []);

        $field = (string) ($config['field'] ?? '');
        $value = $config['value'] ?? null;

        if (!in_array($field, $allowed, true)) {
            return ActionResult::failed('An automation is not allowed to change "' . $field . '".');
        }

        $contactId = (int) $contact['id'];

        if ((string) ($contact[$field] ?? '') === (string) $value) {
            return ActionResult::skipped('Already set to that.');
        }

        $this->contacts->update($contactId, [$field => $value]);

        $this->activity->record(
            'contact_updated',
            $contactId,
            'An automation set ' . str_replace('_', ' ', $field) . ' to "' . (string) $value . '"'
        );

        return ActionResult::done('Set ' . str_replace('_', ' ', $field) . '.');
    }
}
