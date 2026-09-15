<?php

declare(strict_types=1);

namespace App\Services;

use App\Compliance\ComplianceService;
use App\Compliance\ReasonCode;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Logger;
use App\Jobs\SendCampaignEmail;
use App\Mail\EmailProviderInterface;
use App\Mail\SendQuota;
use App\Queue\QueueManager;
use App\Queue\SyncQueueDriver;
use App\Repositories\CampaignRecipientRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\EmailMessageRepository;
use App\Repositories\OrganisationRepository;
use App\Support\TenantContext;
use Throwable;

/**
 * Turns a scheduled campaign into queued work.
 *
 * This is the only path from "a user pressed send" to "messages exist on a
 * queue", and it runs in the scheduler — never in an HTTP request. A web request
 * that tried to send 80,000 emails would time out somewhere in the middle, and
 * nobody would know which half went.
 *
 * The lifecycle it drives:
 *
 *   scheduled ──build snapshot──▶ scheduled ──▶ sending ──enqueue──▶ completed
 *
 * Three properties are worth stating, because the rest of the class is shaped
 * around them:
 *
 *  1. THE SNAPSHOT IS BUILT BEFORE THE CAMPAIGN IS "SENDING". A campaign only
 *     reaches `sending` once `snapshot_completed_at` is stamped, so a crash
 *     half-way through a 100k audience leaves a resumable state rather than a
 *     campaign that believes its audience is 12,000 people.
 *
 *  2. THE SNAPSHOT IS NEVER RE-EVALUATED ONCE COMPLETE. A segment is a live
 *     query. Re-running it mid-send would add contacts the sender never reviewed
 *     and drop contacts who are already half-sent. `snapshot_completed_at` is
 *     what makes "who did this go to" a question with a stable answer.
 *
 *  3. ENQUEUEING HAPPENS BEFORE THE ROW IS MARKED QUEUED. If this process dies
 *     between the two, the recipient is picked up again next tick and a second
 *     job is enqueued — and `claimForSending()` makes the second job a no-op.
 *     The opposite order would lose the recipient silently, and a duplicate job
 *     that sends nothing is a far better failure than a customer who was
 *     promised an email and never got one.
 */
final class CampaignDispatcher
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly CampaignRecipientRepository $recipients,
        private readonly EmailMessageRepository $messages,
        private readonly OrganisationRepository $organisations,
        private readonly CampaignService $campaignService,
        private readonly SegmentService $segments,
        private readonly ComplianceService $compliance,
        private readonly QueueManager $queue,
        private readonly EmailProviderInterface $provider,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * One scheduler tick.
     *
     * Deliberately crosses tenants — this is the scheduler's job — and binds each
     * organisation explicitly before touching a single row, so every repository
     * call underneath stays organisation-scoped exactly as it is in a request.
     *
     * @return array{activated:int,queued:int,completed:int,skipped:int}
     */
    public function tick(int $limit = 25): array
    {
        $summary = ['activated' => 0, 'queued' => 0, 'completed' => 0, 'skipped' => 0];

        // This method binds and clears several tenants in turn. Put whatever was
        // bound on the way in back on the way out, so a caller that already had a
        // tenant (a test, a console command) is not left without one.
        $captured = $this->tenant->capture();

        $candidates = [];

        foreach ([$this->campaigns->dueForSending($this->clock->nowString(), $limit),
                  $this->campaigns->inFlight($limit)] as $set) {
            foreach ($set as $row) {
                $candidates[(int) $row['id']] = $row;
            }
        }

        if ($candidates === []) {
            return $summary;
        }

        // Grouped by organisation so the daily-send budget is computed once and
        // shared: two campaigns in flight for one tenant must not each be handed
        // the full allowance.
        $byOrganisation = [];

        foreach ($candidates as $row) {
            $byOrganisation[(int) $row['organisation_id']][] = (int) $row['id'];
        }

        foreach ($byOrganisation as $organisationId => $campaignIds) {
            $organisation = $this->organisations->findById($organisationId);

            if ($organisation === null) {
                continue;
            }

            $this->tenant->clear();
            $this->tenant->bind($organisationId, null, $organisation);

            try {
                $budget = $this->budgetFor($organisation);

                foreach ($campaignIds as $campaignId) {
                    $outcome = $this->run($campaignId, $budget);

                    $summary['activated'] += $outcome['activated'];
                    $summary['queued']    += $outcome['queued'];
                    $summary['completed'] += $outcome['completed'];
                    $summary['skipped']   += $outcome['skipped'];

                    $budget -= $outcome['queued'];
                }
            } catch (Throwable $e) {
                $this->logger->error('Campaign dispatch failed for organisation', [
                    'organisation' => $organisationId,
                    'error'        => $e->getMessage(),
                ]);
            } finally {
                $this->tenant->clear();
            }
        }

        $this->tenant->restore($captured);

        return $summary;
    }

    /**
     * Drive one campaign as far as this tick can take it.
     *
     * @return array{activated:int,queued:int,completed:int,skipped:int}
     */
    public function run(int $campaignId, int $budget): array
    {
        $outcome = ['activated' => 0, 'queued' => 0, 'completed' => 0, 'skipped' => 0];

        // Re-read under the bound tenant: the cross-tenant scan happened before
        // anything was locked, and a user may have paused it in between.
        $campaign = $this->campaigns->find($campaignId);

        if ($campaign === null) {
            return $outcome;
        }

        $status = (string) $campaign['status'];

        if (!in_array($status, ['scheduled', 'sending'], true)) {
            return $outcome;
        }

        // Organisation-level blocks are checked here rather than left to the
        // per-contact compliance check. They apply to everybody, and letting the
        // per-contact path handle them would mark the entire audience "skipped"
        // for a condition an administrator will lift in an hour.
        $organisation = $this->tenant->organisation();

        if ((string) ($organisation['status'] ?? 'active') === 'suspended'
            || (int) ($organisation['sending_paused'] ?? 0) === 1
        ) {
            $this->logger->warning('Campaign held: sending is blocked for this organisation', [
                'campaign'     => $campaignId,
                'organisation' => (int) $organisation['id'],
                'reason'       => (string) ($organisation['status'] ?? '') === 'suspended'
                    ? ReasonCode::ORG_SUSPENDED
                    : ReasonCode::ORG_SENDING_PAUSED,
            ]);

            $outcome['skipped'] = 1;

            return $outcome;
        }

        // Completes a build that was interrupted; a no-op once stamped.
        $counts = $this->ensureSnapshot($campaign);

        if ($status === 'scheduled') {
            if ($counts['eligible'] === 0) {
                $this->closeEmptyCampaign($campaignId, $counts);
                $outcome['completed'] = 1;

                return $outcome;
            }

            if ($this->campaigns->transition($campaignId, ['scheduled'], 'sending', [
                'send_started_at' => $this->clock->nowString(),
            ]) === 0) {
                // Somebody paused or cancelled it while the snapshot was building.
                return $outcome;
            }

            $outcome['activated'] = 1;

            $this->audit->log('campaign_send_started', 'campaign', $campaignId, null, [
                'recipients' => $counts['total'],
                'eligible'   => $counts['eligible'],
            ]);
        }

        $outcome['queued'] = $this->dispatchPending($campaignId, $budget);

        if ($this->finalise($campaignId)) {
            $outcome['completed'] = 1;
        }

        return $outcome;
    }

    // ------------------------------------------------------------- snapshot

    /**
     * Build the recipient snapshot, resuming an interrupted build.
     *
     * Every contact the audience matches gets a row, eligible or not. Storing the
     * ineligible ones is the point: "we sent to 1,327 of 1,482, and here is why
     * the other 155 were held back" is a compliance record, where silently
     * sending to 1,327 is just a number.
     *
     * @param array<string,mixed> $campaign
     * @return array{total:int,eligible:int,suppressed:int,no_consent:int,invalid:int,blocked:int}
     */
    public function ensureSnapshot(array $campaign): array
    {
        $campaignId = (int) $campaign['id'];

        if (($campaign['snapshot_completed_at'] ?? null) !== null) {
            return $this->snapshotCounts($campaignId);
        }

        $definition = $this->campaignService->audienceDefinition($campaign);

        if ($definition === null) {
            $this->campaigns->update($campaignId, [
                'snapshot_completed_at' => $this->clock->nowString(),
            ]);

            return $this->snapshotCounts($campaignId);
        }

        $organisation = $this->tenant->organisation();
        $query        = $this->segments->query($definition);
        $chunkSize    = max(100, (int) $this->config->get('database.chunk_size', 1000));

        // Resume from the watermark. The snapshot is written in contact-id order,
        // so this picks up exactly where an interrupted build stopped.
        $lastId = $this->recipients->lastSnapshottedContactId($campaignId);

        do {
            $chunk = (clone $query)
                ->where('contacts.id', '>', $lastId)
                ->orderBy('contacts.id')
                ->limit($chunkSize)
                ->get();

            if ($chunk === []) {
                break;
            }

            // One batched evaluation per chunk: suppression and consent are
            // pre-loaded for the whole chunk, so the decision itself costs no
            // further queries.
            $decisions = $this->compliance->evaluateBatch($organisation, $chunk, $campaign);
            $rows      = [];

            foreach ($chunk as $contact) {
                $contactId = (int) $contact['id'];
                $lastId    = $contactId;
                $decision  = $decisions[$contactId] ?? null;

                if ($decision === null) {
                    continue;
                }

                $email = trim((string) ($contact['email'] ?? ''));

                $rows[] = [
                    'campaign_id'        => $campaignId,
                    'contact_id'         => $contactId,
                    'email'              => $email,
                    'email_normalized'   => $email === '' ? '' : normalize_email($email),
                    'eligibility_status' => $decision->allowed ? 'eligible' : ReasonCode::bucket($decision->reason),
                    'eligibility_reason' => $decision->allowed ? null : $decision->reason,
                    // Ineligible rows are closed out immediately, so the "still to
                    // send" count never has to reason about eligibility again.
                    'send_status'        => $decision->allowed ? 'pending' : 'skipped',
                ];
            }

            $this->recipients->insertMany($rows);
        } while (count($chunk) === $chunkSize);

        $counts = $this->snapshotCounts($campaignId);

        $this->campaigns->update($campaignId, [
            'snapshot_completed_at' => $this->clock->nowString(),
            'recipient_count'       => $counts['total'],
            'eligible_count'        => $counts['eligible'],
            'suppressed_count'      => $counts['suppressed'],
            'no_consent_count'      => $counts['no_consent'],
        ]);

        return $counts;
    }

    // ------------------------------------------------------------- dispatch

    /**
     * Enqueue up to $budget sends for a campaign.
     *
     * Streams the snapshot with keyset pagination; a 100k audience never lands in
     * PHP memory, and what is left unqueued this tick is simply picked up by the
     * next one.
     */
    public function dispatchPending(int $campaignId, int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }

        // The sync driver runs jobs inline. That is fine in a test, and it is the
        // single worst thing this application could do in production: an HTTP
        // request or a cron tick would send the entire campaign in-process, time
        // out somewhere in the middle, and leave nobody able to say which half
        // went. Refuse rather than half-send.
        if ($this->queue->driver() instanceof SyncQueueDriver
            && (string) $this->config->get('app.env', 'production') !== 'testing'
        ) {
            $this->logger->error(
                'Refusing to dispatch a campaign on the sync queue driver. '
                . 'Configure QUEUE_DRIVER=redis (or database) and run the workers.',
                ['campaign' => $campaignId]
            );

            return 0;
        }

        $organisationId = $this->tenant->organisationId();
        $queueName      = (string) $this->config->get('queue.campaign_queue', 'email_marketing');
        $queued         = 0;

        foreach ($this->recipients->pendingBatches($campaignId, 500) as $batch) {
            $dispatched = [];

            foreach ($batch as $row) {
                if ($queued >= $budget) {
                    break;
                }

                $recipientId = (int) $row['id'];

                // Enqueue first, mark second — see the class docblock. A duplicate
                // job is harmless; a lost recipient is not.
                $this->queue->dispatch(
                    $queueName,
                    SendCampaignEmail::class,
                    [
                        'organisation_id' => $organisationId,
                        'campaign_id'     => $campaignId,
                        'recipient_id'    => $recipientId,
                    ],
                    $organisationId
                );

                $dispatched[] = $recipientId;
                $queued++;
            }

            if ($dispatched !== []) {
                $this->recipients->markQueued($dispatched);
            }

            if ($queued >= $budget) {
                break;
            }
        }

        if ($queued > 0) {
            $this->logger->info('Campaign sends enqueued', [
                'campaign' => $campaignId,
                'queued'   => $queued,
                'budget'   => $budget,
            ]);
        }

        return $queued;
    }

    /**
     * Close a campaign whose snapshot has been fully worked through.
     *
     * "Worked through" counts queued recipients as outstanding, so a campaign is
     * never reported complete while jobs are still sitting on the queue.
     */
    public function finalise(int $campaignId): bool
    {
        if ($this->recipients->remaining($campaignId) > 0) {
            return false;
        }

        $moved = $this->campaigns->transition($campaignId, ['sending'], 'completed', [
            'send_completed_at' => $this->clock->nowString(),
        ]);

        if ($moved === 0) {
            return false;
        }

        $this->campaigns->refreshCounters($campaignId);

        $this->audit->log('campaign_completed', 'campaign', $campaignId, null, [
            'skip_reasons' => $this->recipients->skipReasons($campaignId),
        ]);

        return true;
    }

    // -------------------------------------------------------------- budget

    /**
     * How many sends this organisation may enqueue in this tick.
     *
     * Two independent ceilings, and the lower one wins:
     *
     *  - the tenant's own daily limit, as a calendar day in their timezone,
     *    because that is what their plan says and what they see in the UI;
     *  - the provider's live quota, read from the provider rather than guessed
     *    from config, because exceeding an SES rate limit produces throttling
     *    errors that look like bounces and damage the whole platform's
     *    reputation, not just this tenant's.
     *
     * @param array<string,mixed> $organisation
     */
    public function budgetFor(array $organisation): int
    {
        $budget = PHP_INT_MAX;

        $dailyLimit = (int) ($organisation['daily_send_limit'] ?? 0);

        if ($dailyLimit > 0) {
            $sentToday = $this->messages->sentToday(
                (string) ($organisation['timezone'] ?? 'UTC')
            );

            $budget = max(0, $dailyLimit - $sentToday);
        }

        if (!(bool) $this->config->get('mail.throttle.respect_provider_quota', true)) {
            return $this->capped($budget);
        }

        $quota = $this->quota();
        $window = max(1, (int) $this->config->get('mail.throttle.dispatch_window_seconds', 60));

        if ($quota === null) {
            // No live quota (a provider that does not report one, or a transient
            // failure). Fall back to the configured rate rather than assuming
            // unlimited — guessing high here is the expensive direction.
            $perSecond = max(1, (int) $this->config->get('mail.throttle.default_per_second', 14));

            return $this->capped(min($budget, $perSecond * $window));
        }

        $budget = min($budget, (int) floor($quota->remaining()));

        if ($quota->maxSendRate > 0) {
            $budget = min($budget, (int) floor($quota->maxSendRate * $window));
        }

        return $this->capped($budget);
    }

    private function quota(): ?SendQuota
    {
        try {
            return $this->provider->getQuota();
        } catch (Throwable $e) {
            $this->logger->warning('Could not read the provider send quota; falling back to the configured rate.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * With no tenant limit and no provider quota to honour there is still a
     * ceiling, because "unlimited" in a loop that enqueues jobs is how one
     * campaign fills a queue that transactional mail also has to move through.
     */
    private function capped(int $budget): int
    {
        return max(0, $budget === PHP_INT_MAX ? 10_000 : $budget);
    }

    // ----------------------------------------------------------- internals

    /**
     * @param array<string,int> $counts
     */
    private function closeEmptyCampaign(int $campaignId, array $counts): void
    {
        $now = $this->clock->nowString();

        $this->campaigns->transition($campaignId, ['scheduled'], 'completed', [
            'send_started_at'   => $now,
            'send_completed_at' => $now,
        ]);

        // Not a failure: the audience was real when it was approved and nobody in
        // it is contactable now. The skip reasons on the snapshot say why, which
        // is the answer the sender actually needs.
        $this->logger->warning('Campaign completed without sending: no eligible recipients', [
            'campaign' => $campaignId,
            'counts'   => $counts,
        ]);

        $this->audit->log('campaign_completed', 'campaign', $campaignId, null, [
            'reason'       => ReasonCode::NO_ELIGIBLE_RECIPIENTS,
            'counts'       => $counts,
            'skip_reasons' => $this->recipients->skipReasons($campaignId),
        ]);
    }

    /** @return array{total:int,eligible:int,suppressed:int,no_consent:int,invalid:int,blocked:int} */
    private function snapshotCounts(int $campaignId): array
    {
        return $this->recipients->eligibilitySummary($campaignId);
    }
}
