<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Actions\ActionResult;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Container;
use App\Core\Logger;
use App\Repositories\AutomationRepository;
use App\Repositories\AutomationRunRepository;
use App\Repositories\ContactRepository;
use App\Services\SegmentCompiler;
use App\Support\TenantContext;
use Throwable;

/**
 * Walks one contact through one journey, a step at a time.
 *
 * The shape that makes this safe is that a run is a row, not a call stack. Every
 * step reads where the run is, does one thing, writes down where it went, and
 * returns. Nothing is held in memory between steps, so a worker dying mid-journey
 * loses at most one step, and a journey that waits three weeks costs nothing
 * while it waits.
 *
 * Four properties, each with a test:
 *
 *  1. A RUN CANNOT LOOP FOREVER. Steps are counted and capped. A journey wired
 *     in a circle fails loudly at the cap instead of quietly consuming a worker
 *     and everybody's sending quota.
 *  2. A CONTACT CANNOT BE RE-ENTERED BY ACCIDENT. Re-entry is off unless the
 *     author turns it on, and even then there is a cooldown and a lifetime cap.
 *     A contact re-tagged nightly by an import must not be emailed nightly.
 *  3. EVERY STEP IS EXPLAINABLE. Including — especially — the ones that did
 *     nothing, because "why did my customer not get that email" is the question
 *     somebody actually asks, and a silent non-send is indistinguishable from a
 *     bug.
 *  4. A BROKEN STEP STOPS THAT RUN, NOT THE JOURNEY. One contact with a deleted
 *     tag should not stop three hundred others from progressing.
 */
final class JourneyRunner
{
    public function __construct(
        private readonly AutomationRepository $automations,
        private readonly AutomationRunRepository $runs,
        private readonly ContactRepository $contacts,
        private readonly SegmentCompiler $compiler,
        private readonly Container $container,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Start a contact on a journey, if the rules allow it.
     *
     * @param array<string,mixed> $automation
     * @return int|null the run id, or null when entry was refused
     */
    public function enter(array $automation, int $contactId, string $reference = ''): ?int
    {
        $automationId = (int) $automation['id'];

        if (!$this->mayEnter($automation, $contactId)) {
            return null;
        }

        $trigger = $this->triggerNode($automationId);

        if ($trigger === null) {
            $this->logger->warning('Journey has no starting point', ['automation' => $automationId]);

            return null;
        }

        $runId = $this->runs->create([
            'automation_id'     => $automationId,
            'contact_id'        => $contactId,
            'current_node_id'   => (int) $trigger['id'],
            'status'            => 'active',
            'context'           => ['automation_id' => $automationId],
            'trigger_reference' => mb_substr($reference, 0, 120),
            'started_at'        => $this->clock->nowString(),
        ]);

        $this->runs->log($runId, (int) $trigger['id'], 'trigger', null, 'entered', 'Started the journey.');
        $this->automations->refreshCounters($automationId);

        return $runId;
    }

    /**
     * Advance a run until it waits, ends, or hits the step cap.
     *
     * Returns the run's final status for this pass, so a caller can report it.
     */
    public function advance(int $runId): string
    {
        if (!$this->runs->claim($runId)) {
            // Somebody else has it. Two schedulers overlapping must not both send.
            return 'claimed_elsewhere';
        }

        $run = $this->runs->findDecoded($runId);

        if ($run === null) {
            return 'gone';
        }

        $automation = $this->automations->findDecoded((int) $run['automation_id']);

        if ($automation === null || (string) $automation['status'] === 'archived') {
            $this->finish($runId, 'cancelled', 'The journey was deleted.');

            return 'cancelled';
        }

        // Pausing a journey stops runs moving, and does not lose them: they pick
        // up where they were when it is switched back on.
        if ((string) $automation['status'] === 'paused') {
            $this->runs->update($runId, ['status' => 'waiting', 'resume_at' => $this->clock->agoString(0)]);

            return 'paused';
        }

        $contact = $this->contacts->find((int) $run['contact_id']);

        if ($contact === null) {
            $this->finish($runId, 'cancelled', 'The contact was deleted.');

            return 'cancelled';
        }

        $maxSteps = (int) $this->config->get('automation.max_steps_per_run', 50);
        $steps    = (int) $run['step_count'];
        $context  = is_array($run['context']) ? $run['context'] : [];

        $context['automation_id'] = (int) $automation['id'];
        $context['run_id']        = $runId;

        $nodeId = (int) $run['current_node_id'];

        while (true) {
            if ($steps >= $maxSteps) {
                // A journey wired in a circle. Failing loudly beats quietly
                // burning a worker and the organisation's sending quota.
                $this->runs->log($runId, $nodeId, 'action', null, 'error', 'Stopped: this journey loops.');
                $this->finish($runId, 'failed', 'This journey keeps going round in circles.');

                return 'failed';
            }

            $next = $this->nextNode($automation, $nodeId, $contact, $runId);

            if ($next === null) {
                $this->finish($runId, 'completed', '');

                return 'completed';
            }

            $steps++;
            $nodeId = (int) $next['id'];

            $outcome = $this->runNode($next, $contact, $context, $runId);

            $this->runs->update($runId, [
                'current_node_id' => $nodeId,
                'step_count'      => $steps,
                'context'         => $context,
            ]);

            if ($outcome === 'wait') {
                return 'waiting';
            }

            if ($outcome === 'exit') {
                $this->finish($runId, 'exited', '');

                return 'exited';
            }

            if ($outcome === 'failed') {
                $this->finish($runId, 'failed', 'A step could not be completed.');

                return 'failed';
            }

            // Re-read: a step may have changed the contact under us, and the next
            // condition should see the new state rather than a stale copy.
            $contact = $this->contacts->find((int) $run['contact_id']) ?? $contact;
        }
    }

    // ------------------------------------------------------------- the graph

    /**
     * Run one node and say what should happen next.
     *
     * @param array<string,mixed> $node
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $context
     */
    private function runNode(array $node, array $contact, array &$context, int $runId): string
    {
        $type = (string) $node['node_type'];

        if ($type === 'exit') {
            $this->runs->log($runId, (int) $node['id'], 'exit', null, 'passed', 'Journey finished here.');

            return 'exit';
        }

        if ($type === 'wait') {
            $minutes = max(1, (int) ($node['wait_minutes'] ?? 60));
            $maxDays = (int) $this->config->get('automation.max_wait_days', 365);
            $minutes = min($minutes, $maxDays * 24 * 60);

            $this->runs->update($runId, [
                'status'    => 'waiting',
                'resume_at' => $this->clock->now()->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s'),
            ]);

            $this->runs->log(
                $runId,
                (int) $node['id'],
                'wait',
                null,
                'waited',
                'Waiting ' . $this->describeMinutes($minutes) . '.'
            );

            return 'wait';
        }

        if ($type === 'condition') {
            // Evaluated by the segment compiler, so a journey condition and a
            // smart list mean exactly the same thing — and a condition cannot
            // reach a field the compiler was never given.
            $passed = $this->conditionPasses($node, (int) $contact['id']);

            $this->runs->log(
                $runId,
                (int) $node['id'],
                'condition',
                null,
                'passed',
                $passed ? 'Yes — took the yes branch.' : 'No — took the no branch.',
                null,
                ['branch' => $passed ? 'yes' : 'no']
            );

            $context['last_branch'] = $passed ? 'yes' : 'no';

            return 'continue';
        }

        // An action.
        $actionType = (string) ($node['action_type'] ?? '');
        $registry   = (array) $this->config->get('automation.actions', []);

        if (!isset($registry[$actionType])) {
            $this->runs->log($runId, (int) $node['id'], 'action', $actionType, 'error', 'Unknown step type.');

            return 'failed';
        }

        try {
            /** @var \App\Automation\Actions\Action $action */
            $action = $this->container->make((string) $registry[$actionType]['class']);
            $result = $action->perform($actionType, (array) ($node['config'] ?? []), $contact, $context);
        } catch (Throwable $e) {
            // One contact's broken step must not stop three hundred others.
            $this->logger->warning('Automation step threw', [
                'run'   => $runId,
                'node'  => (int) $node['id'],
                'error' => $e->getMessage(),
            ]);

            $result = ActionResult::failed($e->getMessage());
        }

        $this->runs->log(
            $runId,
            (int) $node['id'],
            'action',
            $actionType,
            $result->outcome,
            $result->message,
            $result->reasonCode,
            $result->metadata
        );

        // A blocked send is a legitimate outcome, not a broken journey: the
        // contact unsubscribed, and the rest of the journey may still apply.
        return $result->outcome === 'error' ? 'failed' : 'continue';
    }

    /**
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $contact
     * @return array<string,mixed>|null
     */
    private function nextNode(array $automation, int $fromNodeId, array $contact, int $runId): ?array
    {
        $automationId = (int) $automation['id'];
        $branch       = 'default';

        $run = $this->runs->findDecoded($runId);

        if ($run !== null && is_array($run['context']) && isset($run['context']['last_branch'])) {
            $branch = (string) $run['context']['last_branch'];
        }

        $nodes = [];

        foreach ($this->automations->nodes($automationId) as $node) {
            $nodes[(int) $node['id']] = $node;
        }

        $candidates = [];

        foreach ($this->automations->connections($automationId) as $edge) {
            if ((int) $edge['from_node_id'] !== $fromNodeId) {
                continue;
            }

            $candidates[(string) $edge['branch']] = (int) $edge['to_node_id'];
        }

        // A condition takes its branch; everything else takes the default edge.
        $nextId = $candidates[$branch] ?? $candidates['default'] ?? null;

        if ($nextId === null && $branch !== 'default') {
            // A condition with no "no" branch simply ends the journey there,
            // which is the common and reasonable shape.
            return null;
        }

        return $nextId === null ? null : ($nodes[$nextId] ?? null);
    }

    /**
     * @param array<string,mixed> $node
     */
    private function conditionPasses(array $node, int $contactId): bool
    {
        $definition = (array) ($node['config']['definition'] ?? []);

        if ($definition === []) {
            return true;
        }

        try {
            $validated = $this->compiler->validate($definition);
        } catch (Throwable) {
            // A condition that no longer makes sense is treated as "no" rather
            // than "yes": the safer branch when a journey may send email.
            return false;
        }

        return $this->compiler->compile($validated)
            ->where('contacts.id', '=', $contactId)
            ->exists();
    }

    /** @return array<string,mixed>|null */
    private function triggerNode(int $automationId): ?array
    {
        foreach ($this->automations->nodes($automationId) as $node) {
            if ((string) $node['node_type'] === 'trigger') {
                return $node;
            }
        }

        return null;
    }

    // ---------------------------------------------------------- entry rules

    /** @param array<string,mixed> $automation */
    private function mayEnter(array $automation, int $contactId): bool
    {
        $automationId = (int) $automation['id'];

        // Already on this journey. Starting a second run would mean two copies of
        // every email.
        if ($this->runs->hasOpenRun($automationId, $contactId)) {
            return false;
        }

        $previous = $this->runs->runCountFor($automationId, $contactId);

        if ($previous === 0) {
            return true;
        }

        if ((int) ($automation['allow_reentry'] ?? 0) !== 1) {
            return false;
        }

        $max = (int) ($automation['max_runs_per_contact'] ?? 1);

        if ($max > 0 && $previous >= $max) {
            return false;
        }

        $cooldown = (int) ($automation['reentry_cooldown_hours'] ?? 720);
        $last     = $this->runs->lastRunFor($automationId, $contactId);

        if ($cooldown > 0 && $last !== null) {
            $readyAt = $this->clock->now()->modify('-' . $cooldown . ' hours')->format('Y-m-d H:i:s');

            if ((string) $last['started_at'] > $readyAt) {
                return false;
            }
        }

        return true;
    }

    private function finish(int $runId, string $status, string $error): void
    {
        $run = $this->runs->find($runId);

        $this->runs->update($runId, [
            'status'       => $status,
            'resume_at'    => null,
            'completed_at' => $this->clock->nowString(),
            'last_error'   => $error !== '' ? mb_substr($error, 0, 255) : null,
        ]);

        if ($run !== null) {
            $this->automations->refreshCounters((int) $run['automation_id']);
        }
    }

    private function describeMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }

        if ($minutes < 1440) {
            $hours = (int) round($minutes / 60);

            return $hours . ' hour' . ($hours === 1 ? '' : 's');
        }

        $days = (int) round($minutes / 1440);

        return $days . ' day' . ($days === 1 ? '' : 's');
    }
}
