<?php

declare(strict_types=1);

namespace App\Automation;

use App\Core\Config;
use App\Core\Logger;
use App\Repositories\AutomationRepository;
use App\Support\TenantContext;
use Throwable;

/**
 * "Something happened — does any journey care?"
 *
 * Called from the places where things actually happen: a contact is created, a
 * tag is added, somebody presses a link. It looks up live journeys listening for
 * that event, checks whatever the trigger needs to match, and enters the contact.
 *
 * Two deliberate choices:
 *
 *  ENTERING IS CHEAP, STEPPING IS NOT. Dispatch writes a run row and returns.
 *  The actual work happens on the scheduler's next tick, in a worker. So the
 *  HTTP request that created a contact never waits on an email being sent, and a
 *  CSV import of 40,000 rows does not try to run 40,000 journeys inline.
 *
 *  A BROKEN JOURNEY NEVER BREAKS THE THING THAT TRIGGERED IT. Every dispatch is
 *  wrapped: if a journey is misconfigured, the contact is still created, the tag
 *  is still added, and the problem goes to the log. Losing a customer record
 *  because an automation was wired wrong would be a far worse bug.
 */
final class TriggerDispatcher
{
    public function __construct(
        private readonly AutomationRepository $automations,
        private readonly JourneyRunner $runner,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $payload what the trigger needs to match on
     * @return array<int,int> the run ids that were started
     */
    public function fire(string $triggerType, int $contactId, array $payload = []): array
    {
        if (!$this->tenant->isBound() || $contactId <= 0) {
            return [];
        }

        $registry = (array) $this->config->get('automation.triggers', []);

        if (!isset($registry[$triggerType])) {
            $this->logger->warning('Something fired an unknown trigger', ['trigger' => $triggerType]);

            return [];
        }

        $started = [];

        foreach ($this->automations->activeFor($triggerType) as $automation) {
            try {
                if (!$this->matches($automation, $payload)) {
                    continue;
                }

                $runId = $this->runner->enter(
                    $automation,
                    $contactId,
                    $triggerType . ':' . (string) ($payload['reference'] ?? '')
                );

                if ($runId !== null) {
                    $started[] = $runId;
                }
            } catch (Throwable $e) {
                // Never let a journey take down the thing that triggered it.
                $this->logger->error('Could not start a journey', [
                    'automation' => (int) $automation['id'],
                    'trigger'    => $triggerType,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $started;
    }

    /**
     * Does this journey's trigger configuration match what happened?
     *
     * @param array<string,mixed> $automation
     * @param array<string,mixed> $payload
     */
    private function matches(array $automation, array $payload): bool
    {
        $config = is_array($automation['trigger_config'] ?? null) ? $automation['trigger_config'] : [];

        return match ((string) $automation['trigger_type']) {
            'tag_added'    => (int) ($config['tag_id'] ?? 0) === (int) ($payload['tag_id'] ?? -1),
            'list_joined'  => (int) ($config['list_id'] ?? 0) === (int) ($payload['list_id'] ?? -1),
            'api_event'    => (string) ($config['event_name'] ?? '') !== ''
                && (string) $config['event_name'] === (string) ($payload['event_name'] ?? ''),
            // Everything else fires for any occurrence of its event.
            default        => true,
        };
    }
}
