<?php

declare(strict_types=1);

namespace App\Automation;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Logger;
use App\Core\ValidationException;
use App\Database\Connection;
use App\Repositories\AutomationRepository;
use App\Repositories\AutomationRunRepository;
use App\Repositories\OrganisationRepository;
use App\Services\AuditService;
use App\Services\AuthManager;
use App\Support\TenantContext;
use Throwable;

/**
 * Journeys: building them, switching them on, and keeping them moving.
 *
 * The cross-tenant methods at the bottom are the scheduler's, and they follow
 * the same rule as everything else that crosses tenants in this codebase: bind
 * each organisation explicitly, do the work, put back whatever was bound before.
 */
final class AutomationService
{
    public function __construct(
        private readonly AutomationRepository $automations,
        private readonly AutomationRunRepository $runs,
        private readonly OrganisationRepository $organisations,
        private readonly JourneyRunner $runner,
        private readonly TriggerDispatcher $triggers,
        private readonly Connection $connection,
        private readonly AuthManager $auth,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly AuditService $audit,
    ) {
    }

    // ------------------------------------------------------------ authoring

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->automations->all();
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $automation = $this->automations->findDecoded($id);

        if ($automation === null) {
            throw \App\Core\HttpException::notFound();
        }

        $automation['nodes']       = $this->automations->nodes($id);
        $automation['connections'] = $this->automations->connections($id);

        return $automation;
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $this->auth->authorise('automations.create');

        $triggers = (array) $this->config->get('automation.triggers', []);
        $trigger  = (string) ($attributes['trigger_type'] ?? '');

        if (!isset($triggers[$trigger])) {
            throw new ValidationException(['trigger_type' => ['Choose what should start this journey.']]);
        }

        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name === '') {
            throw new ValidationException(['name' => ['Give this journey a name.']]);
        }

        $id = $this->automations->create([
            'name'                   => mb_substr($name, 0, 200),
            'description'            => mb_substr((string) ($attributes['description'] ?? ''), 0, 255) ?: null,
            'trigger_type'           => $trigger,
            'trigger_config'         => $this->sanitiseTriggerConfig($trigger, (array) ($attributes['trigger_config'] ?? [])),
            // Always a draft. A journey that went live the moment it was created
            // would send email nobody had looked at.
            'status'                 => 'draft',
            'allow_reentry'          => (int) ($attributes['allow_reentry'] ?? 0) === 1 ? 1 : 0,
            'reentry_cooldown_hours' => max(0, (int) ($attributes['reentry_cooldown_hours'] ?? 720)),
            'max_runs_per_contact'   => max(1, (int) ($attributes['max_runs_per_contact'] ?? 1)),
            'template_key'           => $attributes['template_key'] ?? null,
            'created_by_user_id'     => $this->auth->id(),
        ]);

        // Every journey starts with a trigger node, so the runner always has an
        // entry point and the editor always has something to attach to.
        $this->addNode($id, ['node_key' => 'start', 'node_type' => 'trigger']);

        $this->audit->log('automation_created', 'automation', $id, null, ['name' => $name, 'trigger' => $trigger]);

        return $id;
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): void
    {
        $this->auth->authorise('automations.edit');

        $automation = $this->find($id);

        $payload = [];

        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $payload[$field] = mb_substr((string) $attributes[$field], 0, 200) ?: null;
            }
        }

        foreach (['allow_reentry', 'reentry_cooldown_hours', 'max_runs_per_contact'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $payload[$field] = max(0, (int) $attributes[$field]);
            }
        }

        if (isset($attributes['trigger_config'])) {
            $payload['trigger_config'] = $this->sanitiseTriggerConfig(
                (string) $automation['trigger_type'],
                (array) $attributes['trigger_config']
            );
        }

        if ($payload !== []) {
            $this->automations->update($id, $payload);
            $this->audit->log('automation_updated', 'automation', $id, null, $payload);
        }
    }

    /**
     * Add a step.
     *
     * @param array<string,mixed> $attributes
     */
    public function addNode(int $automationId, array $attributes): int
    {
        $this->auth->authorise('automations.edit');

        $type    = (string) ($attributes['node_type'] ?? 'action');
        $allowed = ['trigger', 'condition', 'action', 'wait', 'split', 'exit'];

        if (!in_array($type, $allowed, true)) {
            throw new ValidationException(['node_type' => ['That is not a kind of step this system has.']]);
        }

        $actionType = $attributes['action_type'] ?? null;

        if ($type === 'action') {
            $actions = (array) $this->config->get('automation.actions', []);

            if (!isset($actions[(string) $actionType])) {
                throw new ValidationException(['action_type' => ['Choose what this step should do.']]);
            }
        }

        $now = $this->clock->nowString();

        return $this->connection->table('automation_nodes')->insert([
            'organisation_id' => $this->tenant->organisationId(),
            'automation_id'   => $automationId,
            'node_key'        => mb_substr((string) ($attributes['node_key'] ?? uniqid('n', false)), 0, 40),
            'node_type'       => $type,
            'action_type'     => $type === 'action' ? (string) $actionType : null,
            'config'          => json_encode((array) ($attributes['config'] ?? []), JSON_UNESCAPED_SLASHES),
            'wait_minutes'    => $type === 'wait' ? max(1, (int) ($attributes['wait_minutes'] ?? 60)) : null,
            'position_x'      => (int) ($attributes['position_x'] ?? 0),
            'position_y'      => (int) ($attributes['position_y'] ?? 0),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    public function connect(int $automationId, int $fromNodeId, int $toNodeId, string $branch = 'default'): void
    {
        $this->auth->authorise('automations.edit');

        if ($fromNodeId === $toNodeId) {
            throw new ValidationException(['connection' => ['A step cannot lead to itself.']]);
        }

        $this->connection->table('automation_connections')->insert([
            'organisation_id' => $this->tenant->organisationId(),
            'automation_id'   => $automationId,
            'from_node_id'    => $fromNodeId,
            'to_node_id'      => $toNodeId,
            'branch'          => in_array($branch, ['default', 'yes', 'no'], true) ? $branch : 'default',
            'created_at'      => $this->clock->nowString(),
        ]);
    }

    // ------------------------------------------------------------ lifecycle

    /**
     * Switch a journey on.
     *
     * Checked first, because an active journey sends email unattended and the
     * moment to catch a missing template is now, not at 3am with two hundred
     * contacts already in it.
     */
    public function activate(int $id): void
    {
        $this->auth->authorise('automations.activate');

        $problems = $this->problems($id);

        if ($problems !== []) {
            throw new ValidationException(['automation' => $problems]);
        }

        $this->automations->update($id, [
            'status'                => 'active',
            'activated_by_user_id'  => $this->auth->id(),
            'activated_at'          => $this->clock->nowString(),
        ]);

        $this->audit->log('automation_activated', 'automation', $id);
    }

    public function pause(int $id): void
    {
        $this->auth->authorise('automations.activate');

        $this->automations->update($id, ['status' => 'paused']);
        $this->audit->log('automation_paused', 'automation', $id);
    }

    public function delete(int $id): void
    {
        $this->auth->authorise('automations.edit');

        $this->automations->softDelete($id);
        $this->audit->log('automation_deleted', 'automation', $id);
    }

    /**
     * What would stop this journey working, in words.
     *
     * @return array<int,string>
     */
    public function problems(int $id): array
    {
        $automation = $this->find($id);
        $problems   = [];

        $nodes = $automation['nodes'];
        $edges = $automation['connections'];

        $trigger = null;

        foreach ($nodes as $node) {
            if ((string) $node['node_type'] === 'trigger') {
                $trigger = $node;
            }
        }

        if ($trigger === null) {
            $problems[] = 'This journey has no starting point.';

            return $problems;
        }

        $hasFirstStep = false;

        foreach ($edges as $edge) {
            if ((int) $edge['from_node_id'] === (int) $trigger['id']) {
                $hasFirstStep = true;
            }
        }

        if (!$hasFirstStep) {
            $problems[] = 'Nothing happens after the start. Add at least one step.';
        }

        foreach ($nodes as $node) {
            if ((string) $node['node_type'] !== 'action') {
                continue;
            }

            $config = is_array($node['config']) ? $node['config'] : [];

            if ((string) $node['action_type'] === 'send_email'
                && ((int) ($config['template_id'] ?? 0) <= 0 || trim((string) ($config['subject'] ?? '')) === '')
            ) {
                $problems[] = 'One of the email steps has no email or no subject line on it.';
            }

            if (in_array((string) $node['action_type'], ['add_tag', 'remove_tag'], true)
                && (int) ($config['tag_id'] ?? 0) <= 0
            ) {
                $problems[] = 'One of the tag steps has no tag chosen on it.';
            }
        }

        return array_values(array_unique($problems));
    }

    // ------------------------------------------------------------- the runs

    /** @return array<int,array<string,mixed>> */
    public function runsFor(int $automationId, int $limit = 50): array
    {
        return $this->runs->forAutomation($automationId, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    public function runLog(int $runId): array
    {
        return $this->runs->logsFor($runId);
    }

    // ------------------------------------------------------ the scheduler's

    /**
     * Move every run whose timer has elapsed, across every tenant.
     *
     * Binds each organisation before touching a row, and puts back whatever was
     * bound on the way in.
     */
    public function advanceDueRuns(): int
    {
        $limit    = (int) $this->config->get('automation.runs_per_tick', 200);
        $due      = $this->runs->dueEverywhere($this->clock->nowString(), $limit);
        $captured = $this->tenant->capture();
        $moved    = 0;

        foreach ($this->groupByOrganisation($due) as $organisationId => $runIds) {
            $organisation = $this->organisations->findById($organisationId);

            if ($organisation === null) {
                continue;
            }

            $this->tenant->clear();
            $this->tenant->bind($organisationId, null, $organisation);

            foreach ($runIds as $runId) {
                try {
                    $this->runner->advance($runId);
                    $moved++;
                } catch (Throwable $e) {
                    // One stuck run must not stop the rest of the queue moving.
                    $this->logger->error('Automation run failed to advance', [
                        'run'   => $runId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->tenant->clear();
        }

        $this->tenant->restore($captured);

        return $moved;
    }

    /**
     * Start the journeys nothing else fires: inactivity and birthdays.
     *
     * Both are questions about a date, so somebody has to go and ask.
     */
    public function fireScheduledTriggers(): int
    {
        $captured = $this->tenant->capture();
        $started  = 0;

        foreach (['customer_inactive', 'birthday'] as $triggerType) {
            foreach ($this->automations->activeEverywhereFor($triggerType) as $automation) {
                $organisation = $this->organisations->findById((int) $automation['organisation_id']);

                if ($organisation === null) {
                    continue;
                }

                $this->tenant->clear();
                $this->tenant->bind((int) $automation['organisation_id'], null, $organisation);

                try {
                    $started += count($this->enterMatching($automation, $triggerType));
                } catch (Throwable $e) {
                    $this->logger->error('Scheduled journey failed', [
                        'automation' => (int) $automation['id'],
                        'error'      => $e->getMessage(),
                    ]);
                }

                $this->tenant->clear();
            }
        }

        $this->tenant->restore($captured);

        return $started;
    }

    /**
     * Contacts who match a date-based trigger today.
     *
     * Bounded: a journey that suddenly matches ten thousand people should trickle
     * rather than flood, because every one of them is an email.
     *
     * @param array<string,mixed> $automation
     * @return array<int,int>
     */
    private function enterMatching(array $automation, string $triggerType): array
    {
        $config = json_decode((string) ($automation['trigger_config'] ?? '{}'), true);
        $config = is_array($config) ? $config : [];

        $organisationId = (int) $automation['organisation_id'];
        $batch          = 200;

        if ($triggerType === 'customer_inactive') {
            $days   = max(1, (int) ($config['days'] ?? 180));
            $cutoff = $this->clock->agoString($days);

            $rows = $this->connection->select(
                "SELECT id FROM contacts
                 WHERE organisation_id = ? AND deleted_at IS NULL
                   AND marketing_consent_cache = 1 AND is_suppressed_cache = 0
                   AND (last_engagement_at IS NULL OR last_engagement_at < ?)
                   AND (last_purchase_at IS NULL OR last_purchase_at < ?)
                 ORDER BY id LIMIT " . $batch,
                [$organisationId, $cutoff, $cutoff]
            );
        } else {
            // Birthdays: month and day, ignoring the year. Stored as a date, so
            // a string comparison on the last five characters works on both
            // MySQL and SQLite.
            $today = $this->clock->now()->format('m-d');

            $rows = $this->connection->select(
                "SELECT id FROM contacts
                 WHERE organisation_id = ? AND deleted_at IS NULL AND date_of_birth IS NOT NULL
                   AND marketing_consent_cache = 1 AND is_suppressed_cache = 0
                   AND SUBSTR(date_of_birth, 6, 5) = ?
                 ORDER BY id LIMIT " . $batch,
                [$organisationId, $today]
            );
        }

        $started = [];

        foreach ($rows as $row) {
            $runId = $this->runner->enter(
                array_merge($automation, ['trigger_config' => $config]),
                (int) $row['id'],
                $triggerType
            );

            if ($runId !== null) {
                $started[] = $runId;
            }
        }

        return $started;
    }

    // ------------------------------------------------------------ internals

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<int,int>>
     */
    private function groupByOrganisation(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['organisation_id']][] = (int) $row['id'];
        }

        return $grouped;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function sanitiseTriggerConfig(string $trigger, array $config): array
    {
        return match ($trigger) {
            'tag_added'         => ['tag_id' => (int) ($config['tag_id'] ?? 0)],
            'list_joined'       => ['list_id' => (int) ($config['list_id'] ?? 0)],
            'api_event'         => ['event_name' => mb_substr((string) ($config['event_name'] ?? ''), 0, 60)],
            'customer_inactive' => ['days' => max(1, min(3650, (int) ($config['days'] ?? 180)))],
            default             => [],
        };
    }
}
