<?php

declare(strict_types=1);

namespace App\Automation\Actions;

use App\Core\Clock;
use App\Database\Connection;
use App\Support\TenantContext;

/**
 * Tell another system something happened.
 *
 * The journey does not make the HTTP call. It queues a delivery on
 * webhook_deliveries, which the scheduler sends with signing, retries and
 * backoff already built. A journey step that blocked on somebody else's slow
 * endpoint would stall the run and, eventually, the worker.
 *
 * The destination is a webhook the organisation configured, chosen by id — never
 * a URL from the node's own settings. Otherwise editing a journey would be a way
 * to make the server fetch arbitrary addresses, which is how a product like this
 * becomes a tool for probing somebody's internal network.
 */
final class WebhookAction implements Action
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
        $webhookId = (int) ($config['webhook_id'] ?? 0);

        if ($webhookId <= 0) {
            return ActionResult::failed('This step has no destination chosen on it.');
        }

        $webhook = $this->connection->table('webhooks')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', $webhookId)
            ->first();

        if ($webhook === null) {
            return ActionResult::failed('The destination this step used has been deleted.');
        }

        if ((int) $webhook['is_active'] !== 1) {
            return ActionResult::skipped('That destination is switched off.');
        }

        $now = $this->clock->nowString();

        $this->connection->table('webhook_deliveries')->insert([
            'organisation_id' => $this->tenant->organisationId(),
            'webhook_id'      => $webhookId,
            'event_type'      => 'automation.step',
            'event_uuid'      => uuid4(),
            // Identity and the journey, never the whole contact record: a webhook
            // payload is a message to somebody else's server, and it should carry
            // what they need rather than everything we hold.
            'payload'         => (string) json_encode([
                'event'   => 'automation.step',
                'contact' => [
                    'uuid'       => (string) ($contact['uuid'] ?? ''),
                    'email'      => (string) $contact['email'],
                    'first_name' => (string) ($contact['first_name'] ?? ''),
                    'last_name'  => (string) ($contact['last_name'] ?? ''),
                ],
                'automation_id' => $context['automation_id'] ?? null,
                'run_id'        => $context['run_id'] ?? null,
                'note'          => mb_substr((string) ($config['note'] ?? ''), 0, 200),
                'occurred_at'   => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES),
            'status'          => 'pending',
            'next_attempt_at' => $now,
            'created_at'      => $now,
        ]);

        return ActionResult::done('Queued a message to "' . (string) $webhook['name'] . '".');
    }
}
