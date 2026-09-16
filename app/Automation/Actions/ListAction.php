<?php

declare(strict_types=1);

namespace App\Automation\Actions;

use App\Repositories\ListRepository;

/**
 * Add to or remove from a list.
 *
 * Worth saying plainly: joining a list is not consent. A contact added to a list
 * by a journey is still checked for permission and suppression before anything
 * is sent to them, because a list is a grouping and consent is a promise, and
 * conflating the two is how people end up emailed after they said no.
 */
final class ListAction implements Action
{
    public function __construct(private readonly ListRepository $lists)
    {
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $context
     */
    public function perform(string $actionType, array $config, array $contact, array $context): ActionResult
    {
        $listId = (int) ($config['list_id'] ?? 0);

        if ($listId <= 0) {
            return ActionResult::failed('This step has no list chosen on it.');
        }

        $list = $this->lists->find($listId);

        if ($list === null) {
            return ActionResult::failed('The list this step used has been deleted.');
        }

        $contactId = (int) $contact['id'];
        $name      = (string) $list['name'];

        if ($actionType === 'add_to_list') {
            return $this->lists->addContact($listId, $contactId, 'automation')
                ? ActionResult::done('Added to "' . $name . '".')
                : ActionResult::skipped('Already on "' . $name . '".');
        }

        return $this->lists->removeContact($listId, $contactId)
            ? ActionResult::done('Removed from "' . $name . '".')
            : ActionResult::skipped('They were not on "' . $name . '" anyway.');
    }
}
