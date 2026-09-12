<?php

declare(strict_types=1);

namespace App\Services;

use App\Compliance\CampaignValidator;
use App\Core\Clock;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Mail\TemplateRenderer;
use App\Repositories\CampaignRecipientRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\ListRepository;
use App\Repositories\SegmentRepository;
use App\Repositories\TemplateRepository;
use App\Support\TenantContext;

/**
 * Campaigns and the approval workflow.
 *
 *   draft → pending_review → approved → scheduled → sending → completed
 *                     ↘ draft (changes requested)      ↘ paused ↘ cancelled
 *
 * Two rules make the workflow mean something rather than decorate it:
 *
 *  1. Content changes are only possible while a campaign is editable. Approving
 *     something and then rewriting it would make the approval a lie, so any edit
 *     to an approved or scheduled campaign sends it back to draft.
 *  2. An author cannot approve their own campaign. That is the whole point of
 *     having a reviewer, and it is enforced here rather than by the permission
 *     matrix — a MARKETING_MANAGER legitimately holds both permissions.
 *
 * Every transition is a conditional UPDATE, so two people pressing Approve, or
 * the scheduler racing a manual send, cannot both win.
 */
final class CampaignService
{
    /** States in which the content may still be changed. */
    public const EDITABLE_STATES = ['draft', 'pending_review'];

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly CampaignRecipientRepository $recipients,
        private readonly SegmentRepository $segments,
        private readonly ListRepository $lists,
        private readonly TemplateRepository $templates,
        private readonly TemplateRenderer $renderer,
        private readonly SegmentService $segmentService,
        private readonly CampaignValidator $validator,
        private readonly AuthManager $auth,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
    ) {
    }

    // ------------------------------------------------------------------ reads

    /** @param array<string,mixed> $filters */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $query = $this->campaigns->filtered($filters);
        $total = (clone $query)->count();

        return [
            'rows'     => $query->forPage($page, $perPage)->get(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        return $this->campaigns->findOrFailDecoded($id);
    }

    // ---------------------------------------------------------------- writing

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $organisation = $this->tenant->organisation();

        $payload = $this->sanitise($attributes);

        if (($payload['name'] ?? '') === '') {
            throw new ValidationException(['name' => ['Give the campaign a name.']]);
        }

        // Sender defaults come from the organisation, so a campaign is never
        // created with no identity at all.
        $payload['from_name']  ??= (string) ($organisation['default_sender_name'] ?? $organisation['name'] ?? '');
        $payload['from_email'] ??= (string) ($organisation['default_sender_email'] ?? '');
        $payload['reply_to']   ??= (string) ($organisation['reply_to_email'] ?? '') ?: null;
        $payload['timezone']   ??= (string) ($organisation['timezone'] ?? 'UTC');
        $payload['currency']     = (string) ($organisation['currency'] ?? 'USD');

        $payload['status']             = 'draft';
        $payload['created_by_user_id'] = $this->auth->id();
        $payload['created_via']        = $attributes['created_via'] ?? 'manual';

        // Everything created through this service is marketing, and message_class
        // is set here rather than taken from input. The transactional path exists
        // for password resets and receipts and is reached through
        // TransactionalMailer — never by labelling a campaign.
        $payload['message_class'] = 'marketing';

        $id = $this->campaigns->create($payload);

        $this->audit->log('campaign_created', 'campaign', $id, null, [
            'name' => $payload['name'],
            'type' => $payload['campaign_type'] ?? null,
        ]);

        return $id;
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        $this->assertEditable($campaign);

        $payload = $this->sanitise($attributes);

        // Any content change invalidates a previous validation pass.
        $payload['validated_at']        = null;
        $payload['validation_findings'] = null;

        $this->campaigns->update($id, $payload);

        $this->audit->log('campaign_updated', 'campaign', $id, [
            'subject' => $campaign['subject'],
            'status'  => $campaign['status'],
        ], $payload);
    }

    /**
     * Apply a template's rendered content to a campaign.
     *
     * The HTML is copied rather than referenced, so editing the template later
     * cannot silently change a campaign that has already been approved.
     */
    public function applyTemplate(int $id, int $templateId): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);
        $this->assertEditable($campaign);

        $template = $this->templates->findOrFailWithBlocks($templateId);

        $this->campaigns->update($id, [
            'template_id'         => $templateId,
            'html_content'        => (string) $template['html_cache'],
            'text_content'        => (string) $template['text_cache'],
            'validated_at'        => null,
            'validation_findings' => null,
        ]);

        $this->audit->log('campaign_template_applied', 'campaign', $id, null, ['template_id' => $templateId]);
    }

    public function delete(int $id): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        // A campaign that is mid-flight cannot be deleted: messages are already
        // with the provider and the record is needed to explain them.
        if (in_array((string) $campaign['status'], ['sending'], true)) {
            throw new ValidationException([
                'campaign' => ['This campaign is sending. Pause it first.'],
            ]);
        }

        $this->campaigns->softDelete($id);
        $this->audit->log('campaign_deleted', 'campaign', $id, ['name' => $campaign['name']]);
    }

    // ------------------------------------------------------------- audience

    /**
     * The audience preview.
     *
     * Returns matching and contactable separately — a single number would hide
     * the suppressed and no-consent contacts, which is exactly the number the
     * sender needs to see before they commit.
     *
     * @return array{total:int,eligible:int,suppressed:int,no_consent:int,invalid:int,blocked:int,description:string}
     */
    public function audience(int $id): array
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        $definition = $this->audienceDefinition($campaign);

        if ($definition === null) {
            return [
                'total' => 0, 'eligible' => 0, 'suppressed' => 0, 'no_consent' => 0,
                'invalid' => 0, 'blocked' => 0, 'description' => 'No audience selected',
            ];
        }

        $preview = $this->segmentService->preview($definition, 0);

        return [
            'total'       => $preview['total'],
            'eligible'    => $preview['eligible'],
            'suppressed'  => $preview['suppressed'],
            'no_consent'  => $preview['no_consent'],
            'invalid'     => $preview['invalid'],
            'blocked'     => $preview['blocked'],
            'description' => $this->audienceDescription($campaign),
        ];
    }

    /**
     * What actually happened, from the recipient snapshot.
     *
     * Once a campaign has a snapshot this replaces the live audience preview on
     * the campaign page, and the distinction matters: the preview is a query
     * against the segment as it is *now*, which for a campaign that went out last
     * Tuesday is a different set of people. The snapshot is the record of who was
     * actually considered, who was sent to, and who was held back and why.
     *
     * @return array{total:int,eligible:int,suppressed:int,no_consent:int,invalid:int,blocked:int,description:string,send_status:array<string,int>,skip_reasons:array<string,int>,remaining:int}|null
     */
    public function deliveryReport(int $id): ?array
    {
        if (!$this->campaigns->hasSnapshot($id)) {
            return null;
        }

        $campaign = $this->campaigns->findOrFailDecoded($id);

        return array_merge($this->recipients->eligibilitySummary($id), [
            'description'  => $this->audienceDescription($campaign),
            'send_status'  => $this->recipients->sendStatusBreakdown($id),
            'skip_reasons' => $this->recipients->skipReasons($id),
            'remaining'    => $this->recipients->remaining($id),
        ]);
    }

    /**
     * The rule tree for a campaign's audience.
     *
     * A list is expressed as a segment rule rather than handled separately, so the
     * snapshot builder and the preview share one code path — and so a list
     * audience gets the same eligibility treatment as a segment one.
     *
     * @param array<string,mixed> $campaign
     * @return array<string,mixed>|null
     */
    public function audienceDefinition(array $campaign): ?array
    {
        $segmentId = (int) ($campaign['segment_id'] ?? 0);
        $listId    = (int) ($campaign['list_id'] ?? 0);

        if ($segmentId > 0) {
            $segment = $this->segments->findWithDefinition($segmentId);

            if ($segment !== null) {
                return $segment['definition'];
            }
        }

        if ($listId > 0 && $this->lists->find($listId) !== null) {
            return [
                'match' => 'all',
                'rules' => [['field' => 'list', 'operator' => 'in', 'value' => [$listId]]],
            ];
        }

        return null;
    }

    /** @param array<string,mixed> $campaign */
    public function audienceDescription(array $campaign): string
    {
        $segmentId = (int) ($campaign['segment_id'] ?? 0);

        if ($segmentId > 0) {
            $segment = $this->segments->findWithDefinition($segmentId);

            if ($segment !== null) {
                return 'Segment: ' . (string) $segment['name'];
            }
        }

        $listId = (int) ($campaign['list_id'] ?? 0);

        if ($listId > 0) {
            $list = $this->lists->find($listId);

            if ($list !== null) {
                return 'List: ' . (string) $list['name'];
            }
        }

        return 'No audience selected';
    }

    // ------------------------------------------------------------ validation

    /**
     * Run the validator and record the outcome on the campaign.
     *
     * @return array{valid:bool,blocking:array<int,array{code:string,message:string}>,warnings:array<int,array{code:string,message:string}>}
     */
    public function validate(int $id): array
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);
        $audience = $this->audience($id);

        $result = $this->validator->validate($campaign, $this->tenant->organisation(), [
            'recipients' => $audience['total'],
            'eligible'   => $audience['eligible'],
        ]);

        $this->campaigns->update($id, [
            'validated_at'        => $this->clock->nowString(),
            'validation_findings' => $result,
            'recipient_count'     => $audience['total'],
            'eligible_count'      => $audience['eligible'],
            'suppressed_count'    => $audience['suppressed'],
            'no_consent_count'    => $audience['no_consent'],
        ]);

        return $result;
    }

    // -------------------------------------------------------------- workflow

    public function submitForReview(int $id): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);
        $this->assertEditable($campaign);

        $result = $this->validate($id);

        if (!$result['valid']) {
            throw new ValidationException([
                'campaign' => array_map(
                    static fn (array $finding): string => $finding['message'],
                    $result['blocking']
                ),
            ]);
        }

        // An organisation that has turned approval off goes straight to approved,
        // recorded as a policy decision rather than an approval by a person.
        if (!$this->requiresApproval()) {
            $this->campaigns->transition($id, self::EDITABLE_STATES, 'approved', [
                'approved_by_user_id' => null,
                'approved_at'         => $this->clock->nowString(),
            ]);

            $this->audit->log('campaign_approved', 'campaign', $id, null, [
                'approved_by' => 'policy: approval not required',
            ]);

            return;
        }

        $moved = $this->campaigns->transition($id, self::EDITABLE_STATES, 'pending_review');

        if ($moved === 0) {
            throw new ValidationException(['campaign' => ['This campaign is no longer a draft.']]);
        }

        $this->audit->log('campaign_submitted_for_review', 'campaign', $id);
    }

    public function approve(int $id): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        if ((string) $campaign['status'] !== 'pending_review') {
            throw new ValidationException([
                'campaign' => ['Only a campaign awaiting review can be approved.'],
            ]);
        }

        $this->auth->authorise('campaigns.approve');

        // The separation of duties that makes review meaningful. A
        // MARKETING_MANAGER holds both permissions, so the permission matrix
        // cannot enforce this — it has to be here.
        $authorId  = (int) ($campaign['created_by_user_id'] ?? 0);
        $approverId = (int) $this->auth->id();

        if ($authorId > 0 && $authorId === $approverId && !$this->auth->isSuperAdmin()) {
            throw HttpException::forbidden(
                'You created this campaign, so you cannot approve it. Ask a colleague with approval '
                . 'permission to review it — that is the point of the review step.'
            );
        }

        // Re-validate at approval time: the audience and the organisation's
        // configuration may have changed since the draft was written.
        $result = $this->validate($id);

        if (!$result['valid']) {
            throw new ValidationException([
                'campaign' => array_map(
                    static fn (array $finding): string => $finding['message'],
                    $result['blocking']
                ),
            ]);
        }

        $moved = $this->campaigns->transition($id, ['pending_review'], 'approved', [
            'approved_by_user_id' => $approverId,
            'approved_at'         => $this->clock->nowString(),
        ]);

        if ($moved === 0) {
            throw new ValidationException(['campaign' => ['Somebody else has already actioned this campaign.']]);
        }

        $this->audit->log('campaign_approved', 'campaign', $id, null, [
            'approved_by_user_id' => $approverId,
            'author_user_id'      => $authorId,
        ]);
    }

    public function requestChanges(int $id, string $reason): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        if ((string) $campaign['status'] !== 'pending_review') {
            throw new ValidationException(['campaign' => ['This campaign is not awaiting review.']]);
        }

        $this->auth->authorise('campaigns.approve');

        $this->campaigns->transition($id, ['pending_review'], 'draft');

        $this->audit->log('campaign_changes_requested', 'campaign', $id, null, [
            'reason'       => $reason,
            'requested_by' => $this->auth->id(),
        ]);
    }

    /**
     * Schedule an approved campaign.
     *
     * $scheduledAt arrives in the organisation's timezone, as the user typed it,
     * and is converted to UTC for storage — the one place in the application
     * where a local time is accepted.
     */
    public function schedule(int $id, string $scheduledAt): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        if ((string) $campaign['status'] !== 'approved') {
            throw new ValidationException(['campaign' => ['Only an approved campaign can be scheduled.']]);
        }

        $this->auth->authorise('campaigns.send');

        $timezone = $this->tenant->timezone();
        $utc      = $this->toUtc($scheduledAt, $timezone);

        if ($utc <= $this->clock->nowString()) {
            throw new ValidationException([
                'scheduled_at' => ['Choose a time in the future, or send now instead.'],
            ]);
        }

        $moved = $this->campaigns->transition($id, ['approved'], 'scheduled', [
            'scheduled_at' => $utc,
            'timezone'     => $timezone,
        ]);

        if ($moved === 0) {
            throw new ValidationException(['campaign' => ['This campaign is no longer approved.']]);
        }

        $this->audit->log('campaign_scheduled', 'campaign', $id, null, [
            'scheduled_at_utc'   => $utc,
            'scheduled_at_local' => $scheduledAt,
            'timezone'           => $timezone,
        ]);
    }

    /**
     * Send now: schedule for the next scheduler tick rather than sending inline.
     *
     * Bulk email never leaves an HTTP request. "Send now" means "queue now", and
     * the worker does the work.
     */
    public function sendNow(int $id): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        if ((string) $campaign['status'] !== 'approved') {
            throw new ValidationException(['campaign' => ['Only an approved campaign can be sent.']]);
        }

        $this->auth->authorise('campaigns.send');

        $moved = $this->campaigns->transition($id, ['approved'], 'scheduled', [
            'scheduled_at' => $this->clock->nowString(),
            'timezone'     => $this->tenant->timezone(),
        ]);

        if ($moved === 0) {
            throw new ValidationException(['campaign' => ['This campaign is no longer approved.']]);
        }

        $this->audit->log('campaign_send_requested', 'campaign', $id, null, ['mode' => 'immediate']);
    }

    public function unschedule(int $id): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        if ((string) $campaign['status'] !== 'scheduled') {
            throw new ValidationException(['campaign' => ['This campaign is not scheduled.']]);
        }

        $this->auth->authorise('campaigns.send');

        $this->campaigns->transition($id, ['scheduled'], 'approved', ['scheduled_at' => null]);

        $this->audit->log('campaign_unscheduled', 'campaign', $id);
    }

    /**
     * Pause a send in flight.
     *
     * Messages already handed to the provider cannot be recalled; this stops the
     * queue from taking any more. It is the lever somebody reaches for when a
     * campaign is going wrong, so it must work from any sending state.
     */
    public function pause(int $id, string $reason = 'Paused by a user'): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        if (!in_array((string) $campaign['status'], ['sending', 'scheduled'], true)) {
            throw new ValidationException(['campaign' => ['This campaign is not sending.']]);
        }

        $this->auth->authorise('campaigns.send');

        $this->campaigns->transition($id, ['sending', 'scheduled'], 'paused', ['pause_reason' => $reason]);

        $this->audit->log('campaign_paused', 'campaign', $id, null, ['reason' => $reason]);
    }

    public function resume(int $id): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        if ((string) $campaign['status'] !== 'paused') {
            throw new ValidationException(['campaign' => ['This campaign is not paused.']]);
        }

        $this->auth->authorise('campaigns.send');

        // If a snapshot was already built, the dispatcher can pick up where it left
        // off — it works from send_status on the snapshot rows. Otherwise the
        // campaign goes back to the queue and starts properly.
        //
        // This asks the snapshot table rather than recipient_count: validation
        // writes that column too, as the current audience size, and treating it as
        // "a snapshot exists" would resume a campaign into sending with nothing
        // to send.
        $hasSnapshot = $this->campaigns->hasSnapshot($id);

        $this->campaigns->transition($id, ['paused'], $hasSnapshot ? 'sending' : 'scheduled', [
            'pause_reason' => null,
            'scheduled_at' => $hasSnapshot ? $campaign['scheduled_at'] : $this->clock->nowString(),
        ]);

        $this->audit->log('campaign_resumed', 'campaign', $id);
    }

    public function cancel(int $id, string $reason = 'Cancelled by a user'): void
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        if (in_array((string) $campaign['status'], ['completed', 'cancelled'], true)) {
            throw new ValidationException(['campaign' => ['This campaign has already finished.']]);
        }

        $this->auth->authorise('campaigns.send');

        $this->campaigns->transition(
            $id,
            ['draft', 'pending_review', 'approved', 'scheduled', 'sending', 'paused'],
            'cancelled',
            ['pause_reason' => $reason]
        );

        $this->audit->log('campaign_cancelled', 'campaign', $id, null, ['reason' => $reason]);
    }

    public function duplicate(int $id): int
    {
        $campaign = $this->campaigns->findOrFailDecoded($id);

        return $this->create([
            'name'          => (string) $campaign['name'] . ' (copy)',
            'subject'       => $campaign['subject'],
            'preview_text'  => $campaign['preview_text'],
            'from_name'     => $campaign['from_name'],
            'from_email'    => $campaign['from_email'],
            'reply_to'      => $campaign['reply_to'],
            'campaign_type' => $campaign['campaign_type'],
            'template_id'   => $campaign['template_id'],
            'html_content'  => $campaign['html_content'],
            'text_content'  => $campaign['text_content'],
            'segment_id'    => $campaign['segment_id'],
            'list_id'       => $campaign['list_id'],
            'utm_source'    => $campaign['utm_source'],
            'utm_medium'    => $campaign['utm_medium'],
            'utm_campaign'  => $campaign['utm_campaign'],
        ]);
    }

    // ------------------------------------------------------------- internals

    /** @param array<string,mixed> $campaign */
    private function assertEditable(array $campaign): void
    {
        $status = (string) $campaign['status'];

        if (in_array($status, self::EDITABLE_STATES, true)) {
            return;
        }

        if (in_array($status, ['approved', 'scheduled'], true)) {
            // Rather than silently un-approving, say what will happen and make the
            // user choose. An approval that survives a rewrite is worthless.
            throw new ValidationException([
                'campaign' => [
                    'This campaign has been approved. Editing it would invalidate that approval — '
                    . 'unschedule and return it to draft first.',
                ],
            ]);
        }

        throw new ValidationException([
            'campaign' => ['A campaign in the "' . str_replace('_', ' ', $status) . '" state cannot be edited.'],
        ]);
    }

    /**
     * Deliberately narrow whitelist. Status, counters, approval fields and
     * message_class are not settable from a request.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private function sanitise(array $attributes): array
    {
        $writable = [
            'name', 'subject', 'preview_text', 'from_name', 'from_email', 'reply_to',
            'campaign_type', 'template_id', 'html_content', 'text_content',
            'segment_id', 'list_id', 'utm_source', 'utm_medium', 'utm_campaign',
            'utm_content', 'utm_term', 'workspace_id',
        ];

        $payload = [];

        foreach ($writable as $column) {
            if (!array_key_exists($column, $attributes)) {
                continue;
            }

            $value = $attributes[$column];

            if (in_array($column, ['template_id', 'segment_id', 'list_id', 'workspace_id'], true)) {
                $payload[$column] = (int) $value > 0 ? (int) $value : null;
                continue;
            }

            if ($column === 'html_content') {
                // Campaign HTML comes from the renderer or from a user who is
                // allowed to write email markup, so it is not sanitised here — but
                // it never renders in the application's own origin either: the
                // preview is served into a sandboxed frame with its own CSP.
                $payload[$column] = is_string($value) ? $value : '';
                continue;
            }

            $payload[$column] = $value === '' ? null : (is_scalar($value) ? (string) $value : null);
        }

        // A campaign type we do not recognise would derive the wrong message class.
        if (isset($payload['campaign_type'])) {
            $allowed = [
                'newsletter', 'promotion', 'reactivation', 'announcement', 'event',
                'lead_followup', 'customer_followup', 'review_request', 'seasonal',
                'product', 'service',
            ];

            if (!in_array((string) $payload['campaign_type'], $allowed, true)) {
                throw new ValidationException(['campaign_type' => ['Choose a valid campaign type.']]);
            }
        }

        return $payload;
    }

    private function requiresApproval(): bool
    {
        return (int) ($this->tenant->organisation()['require_campaign_approval'] ?? 1) === 1;
    }

    private function toUtc(string $local, string $timezone): string
    {
        try {
            $date = new \DateTimeImmutable($local, new \DateTimeZone($timezone));
        } catch (\Throwable) {
            throw new ValidationException(['scheduled_at' => ['That is not a valid date and time.']]);
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return array<string,int> */
    public function statusCounts(): array
    {
        return $this->campaigns->statusCounts();
    }
}
