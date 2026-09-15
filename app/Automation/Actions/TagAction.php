<?php

declare(strict_types=1);

namespace App\Automation\Actions;

use App\Repositories\TagRepository;
use App\Services\ActivityService;

final class TagAction implements Action
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly ActivityService $activity,
    ) {
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $context
     */
    public function perform(string $actionType, array $config, array $contact, array $context): ActionResult
    {
        $tagId = (int) ($config['tag_id'] ?? 0);

        if ($tagId <= 0) {
            return ActionResult::failed('This step has no tag chosen on it.');
        }

        $tag = $this->tags->find($tagId);

        if ($tag === null) {
            return ActionResult::failed('The tag this step used has been deleted.');
        }

        $contactId = (int) $contact['id'];
        $name      = (string) $tag['name'];

        // Already-tagged is a normal outcome, not a failure: a journey somebody
        // re-enters should not error just because the first run did its job.
        if ($actionType === 'add_tag') {
            $changed = $this->tags->attach($contactId, $tagId);

            if ($changed) {
                $this->activity->record('tag_added', $contactId, 'Tagged "' . $name . '" by an automation');
            }

            return $changed
                ? ActionResult::done('Tagged "' . $name . '".')
                : ActionResult::skipped('Already tagged "' . $name . '".');
        }

        $changed = $this->tags->detach($contactId, $tagId);

        if ($changed) {
            $this->activity->record('tag_removed', $contactId, 'Removed the tag "' . $name . '" by an automation');
        }

        return $changed
            ? ActionResult::done('Removed the tag "' . $name . '".')
            : ActionResult::skipped('They did not have "' . $name . '" anyway.');
    }
}
