<?php

declare(strict_types=1);

namespace App\Automation\Actions;

use App\Core\Clock;
use App\Database\Connection;
use App\Support\TenantContext;

/**
 * Put a job on somebody's list.
 *
 * The action that makes automation useful to a trade business: the interesting
 * follow-up to an enquiry is usually a phone call, not another email, and this
 * is how a journey asks for one.
 */
final class CreateTaskAction implements Action
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TenantContext $tenant,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $context
     */
    public function perform(string $actionType, array $config, array $contact, array $context): ActionResult
    {
        $title = trim((string) ($config['title'] ?? ''));

        if ($title === '') {
            return ActionResult::failed('This step has no job description on it.');
        }

        $dueInHours = max(0, min(24 * 365, (int) ($config['due_in_hours'] ?? 24)));

        $assignee = (int) ($config['assigned_user_id'] ?? 0);

        // An unassigned job is one nobody does. Falling back to the contact's
        // owner keeps it in front of the person who already knows them.
        if ($assignee <= 0) {
            $assignee = (int) ($contact['owner_user_id'] ?? 0);
        }

        $now = $this->clock->nowString();

        $id = $this->connection->table('lead_tasks')->insert([
            'organisation_id'  => $this->tenant->organisationId(),
            'contact_id'       => (int) $contact['id'],
            'title'            => mb_substr($title, 0, 200),
            'description'      => mb_substr((string) ($config['description'] ?? ''), 0, 2000) ?: null,
            'task_type'        => in_array($config['task_type'] ?? '', ['call', 'email', 'meeting', 'quote', 'followup', 'other'], true)
                ? (string) $config['task_type'] : 'followup',
            'priority'         => in_array($config['priority'] ?? '', ['low', 'normal', 'high', 'urgent'], true)
                ? (string) $config['priority'] : 'normal',
            'assigned_user_id' => $assignee > 0 ? $assignee : null,
            'due_at'           => $this->clock->now()->modify('+' . $dueInHours . ' hours')->format('Y-m-d H:i:s'),
            'status'           => 'open',
            'created_via'      => 'automation',
            'automation_id'    => isset($context['automation_id']) ? (int) $context['automation_id'] : null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return ActionResult::done('Job created: "' . $title . '".', ['task_id' => $id]);
    }
}
