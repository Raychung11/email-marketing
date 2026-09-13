<?php

declare(strict_types=1);

namespace App\Services;

use App\Automation\TriggerDispatcher;
use App\Core\Clock;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Database\Connection;
use App\Support\TenantContext;

/**
 * Enquiries, and what happens to them.
 *
 * The most valuable thing this product does for a trade business is not send
 * email — it is stop an enquiry going unanswered. So the pipeline exists mainly
 * to make one number visible: how many people asked you for work and have not
 * heard back.
 *
 * Scoring is deliberately simple and entirely explainable. Every lead carries
 * the list of reasons it scored what it scored, because a number nobody can
 * account for is a number people ignore, and then they work the list by date
 * again.
 */
final class LeadService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContactService $contacts,
        private readonly TriggerDispatcher $triggers,
        private readonly AuthManager $auth,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly ActivityService $activity,
        private readonly AuditService $audit,
    ) {
    }

    // ------------------------------------------------------------- creating

    /**
     * Record an enquiry.
     *
     * The contact comes first: an enquiry from somebody we already know should
     * attach to them rather than create a second record of the same person.
     *
     * @param array<string,mixed> $attributes
     */
    public function create(array $attributes): int
    {
        $email = trim((string) ($attributes['email'] ?? ''));

        $contactId = (int) ($attributes['contact_id'] ?? 0);

        if ($contactId <= 0 && $email !== '') {
            $contactId = $this->contacts->findOrCreateByEmail($email, [
                'first_name' => $attributes['first_name'] ?? null,
                'last_name'  => $attributes['last_name'] ?? null,
                'phone'      => $attributes['phone'] ?? null,
                'source'     => $attributes['source'] ?? 'website_form',
            ]);
        }

        if ($contactId <= 0) {
            throw new ValidationException(['email' => ['We need an email address or an existing contact.']]);
        }

        $pipeline = $this->defaultPipeline();
        $stage    = $this->firstStage((int) $pipeline['id']);

        $now = $this->clock->nowString();

        $id = $this->connection->table('leads')->insert([
            'organisation_id'   => $this->tenant->organisationId(),
            'uuid'              => uuid4(),
            'contact_id'        => $contactId,
            'pipeline_id'       => (int) $pipeline['id'],
            'pipeline_stage_id' => (int) $stage['id'],
            'title'             => mb_substr((string) ($attributes['title'] ?? 'Enquiry'), 0, 200),
            'enquiry'           => mb_substr((string) ($attributes['enquiry'] ?? ''), 0, 5000) ?: null,
            'service_type'      => mb_substr((string) ($attributes['service_type'] ?? ''), 0, 120) ?: null,
            'estimated_value'   => isset($attributes['estimated_value'])
                ? round((float) $attributes['estimated_value'], 2) : null,
            'currency'          => $this->tenant->currency(),
            'source'            => $this->source((string) ($attributes['source'] ?? 'manual')),
            'source_detail'     => mb_substr((string) ($attributes['source_detail'] ?? ''), 0, 255) ?: null,
            'campaign_id'       => (int) ($attributes['campaign_id'] ?? 0) ?: null,
            'assigned_user_id'  => (int) ($attributes['assigned_user_id'] ?? 0) ?: null,
            'status'            => 'open',
            'last_activity_at'  => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $this->rescore($id);

        $this->activity->record('lead_created', $contactId, 'New enquiry', ['lead_id' => $id]);
        $this->audit->log('lead_created', 'lead', $id, null, ['source' => $attributes['source'] ?? 'manual']);

        // A journey may want to chase this if nobody answers.
        $this->triggers->fire('lead_created', $contactId, ['reference' => (string) ($attributes['source'] ?? '')]);

        return $id;
    }

    // -------------------------------------------------------------- working

    /**
     * Move a lead to another stage, recording how long it sat in the last one.
     */
    public function moveToStage(int $leadId, int $stageId, string $note = '', string $via = 'manual'): void
    {
        $this->auth->authorise('leads.manage');

        $lead  = $this->findOrFail($leadId);
        $stage = $this->connection->table('pipeline_stages')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', $stageId)
            ->first();

        if ($stage === null) {
            throw new ValidationException(['stage' => ['That stage does not exist.']]);
        }

        if ((int) $lead['pipeline_stage_id'] === $stageId) {
            return;
        }

        $now = $this->clock->nowString();

        // How long it sat where it was — the number that tells somebody their
        // quotes are going cold at the same step every time.
        $since = (string) ($lead['last_activity_at'] ?? $lead['created_at']);
        $held  = max(0, $this->clock->now()->getTimestamp() - strtotime($since));

        $this->connection->table('lead_stage_history')->insert([
            'organisation_id'    => $this->tenant->organisationId(),
            'lead_id'            => $leadId,
            'from_stage_id'      => (int) $lead['pipeline_stage_id'],
            'to_stage_id'        => $stageId,
            'changed_by_user_id' => $this->auth->id(),
            'changed_via'        => $via,
            'duration_seconds'   => $held,
            'note'               => mb_substr($note, 0, 255) ?: null,
            'created_at'         => $now,
        ]);

        $update = [
            'pipeline_stage_id' => $stageId,
            'last_activity_at'  => $now,
            'updated_at'        => $now,
        ];

        if ((int) $stage['is_won'] === 1) {
            $update['status'] = 'won';
            $update['won_at'] = $now;
        } elseif ((int) $stage['is_lost'] === 1) {
            $update['status']  = 'lost';
            $update['lost_at'] = $now;
        } else {
            $update['status']  = 'open';
        }

        $this->connection->table('leads')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', $leadId)
            ->update($update);

        $this->audit->log('lead_stage_changed', 'lead', $leadId, null, [
            'to'    => (string) $stage['label'],
            'via'   => $via,
            'after' => $held,
        ]);
    }

    /**
     * Somebody has replied.
     *
     * Recorded once, on the first reply, because "how long until we answer" is
     * the number worth watching and a second reply does not change it.
     */
    public function markResponded(int $leadId): void
    {
        $lead = $this->findOrFail($leadId);

        if ($lead['first_response_at'] !== null) {
            return;
        }

        $this->connection->table('leads')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', $leadId)
            ->update([
                'first_response_at' => $this->clock->nowString(),
                'last_activity_at'  => $this->clock->nowString(),
                'updated_at'        => $this->clock->nowString(),
            ]);
    }

    // -------------------------------------------------------------- scoring

    /**
     * Work out what a lead is worth looking at, and why.
     *
     * @return array{score:int,reasons:array<int,array<string,mixed>>,temperature:string}
     */
    public function score(int $leadId): array
    {
        $lead = $this->findOrFail($leadId);

        /** @var array<string,array<string,mixed>> $rules */
        $rules   = (array) $this->config->get('leads.scoring.rules', []);
        $reasons = [];
        $score   = 0;

        $contact = $this->connection->table('contacts')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', (int) $lead['contact_id'])
            ->first() ?? [];

        $matched = [];

        if (trim((string) ($contact['phone'] ?? '')) !== '') {
            $matched[] = 'has_phone';
        }

        if (mb_strlen(trim((string) ($lead['enquiry'] ?? ''))) > 80) {
            $matched[] = 'enquiry_detail';
        }

        if ((float) ($lead['estimated_value'] ?? 0) > $this->averageJobValue()) {
            $matched[] = 'estimated_value';
        }

        if ((int) ($contact['purchase_count'] ?? 0) > 0) {
            $matched[] = 'existing_customer';
        }

        $recentClick = (string) ($contact['last_email_click_at'] ?? '');

        if ($recentClick !== '' && $recentClick >= $this->clock->agoString(30)) {
            $matched[] = 'clicked_recent_email';
        }

        $pricingViews = (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM tracking_events
             WHERE organisation_id = ? AND contact_id = ? AND event_name = 'pricing_view'
               AND occurred_at >= ?",
            [$this->tenant->organisationId(), (int) $lead['contact_id'], $this->clock->agoString(30)]
        );

        if ($pricingViews > 0) {
            $matched[] = 'visited_pricing';
        }

        $priorEnquiries = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM leads WHERE organisation_id = ? AND contact_id = ? AND id != ?
               AND deleted_at IS NULL',
            [$this->tenant->organisationId(), (int) $lead['contact_id'], $leadId]
        );

        if ($priorEnquiries > 0) {
            $matched[] = 'repeat_enquiry';
        }

        foreach ($matched as $key) {
            if (!isset($rules[$key])) {
                continue;
            }

            $score    += (int) $rules[$key]['points'];
            $reasons[] = [
                'key'    => $key,
                'label'  => (string) $rules[$key]['label'],
                'why'    => (string) $rules[$key]['why'],
                'points' => (int) $rules[$key]['points'],
            ];
        }

        $hot  = (int) $this->config->get('leads.scoring.hot_threshold', 50);
        $warm = (int) $this->config->get('leads.scoring.warm_threshold', 25);

        return [
            'score'       => $score,
            'reasons'     => $reasons,
            'temperature' => $score >= $hot ? 'hot' : ($score >= $warm ? 'warm' : 'cool'),
        ];
    }

    public function rescore(int $leadId): int
    {
        $result = $this->score($leadId);

        $this->connection->table('leads')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', $leadId)
            ->update(['lead_score' => $result['score'], 'updated_at' => $this->clock->nowString()]);

        return $result['score'];
    }

    // ------------------------------------------------------------- reading

    /**
     * The board: stages across, leads down.
     *
     * @return array<int,array<string,mixed>>
     */
    public function board(): array
    {
        $pipeline = $this->defaultPipeline();

        $stages = $this->connection->select(
            'SELECT * FROM pipeline_stages WHERE organisation_id = ? AND pipeline_id = ?
             ORDER BY sort_order, id',
            [$this->tenant->organisationId(), (int) $pipeline['id']]
        );

        $board = [];

        foreach ($stages as $stage) {
            $leads = $this->connection->select(
                "SELECT l.*, c.first_name, c.last_name, c.email, c.phone
                 FROM leads l
                 INNER JOIN contacts c ON c.id = l.contact_id AND c.organisation_id = l.organisation_id
                 WHERE l.organisation_id = ? AND l.pipeline_stage_id = ? AND l.deleted_at IS NULL
                   AND l.status != 'archived'
                 ORDER BY l.lead_score DESC, l.created_at
                 LIMIT 50",
                [$this->tenant->organisationId(), (int) $stage['id']]
            );

            $board[] = [
                'stage' => $stage,
                'leads' => $leads,
                'value' => array_sum(array_map(
                    static fn (array $l): float => (float) ($l['estimated_value'] ?? 0),
                    $leads
                )),
            ];
        }

        return $board;
    }

    /**
     * The number that matters: enquiries nobody has answered.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unanswered(): array
    {
        $hours  = (int) $this->config->get('leads.stale_after_hours', 24);
        $cutoff = $this->clock->now()->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s');

        return $this->connection->select(
            "SELECT l.*, c.first_name, c.last_name, c.email, c.phone
             FROM leads l
             INNER JOIN contacts c ON c.id = l.contact_id AND c.organisation_id = l.organisation_id
             WHERE l.organisation_id = ? AND l.status = 'open' AND l.first_response_at IS NULL
               AND l.created_at < ? AND l.deleted_at IS NULL
             ORDER BY l.lead_score DESC, l.created_at
             LIMIT 50",
            [$this->tenant->organisationId(), $cutoff]
        );
    }

    /** @return array<string,mixed> */
    public function findOrFail(int $id): array
    {
        $lead = $this->connection->table('leads')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', $id)
            ->whereNull('deleted_at')
            ->first();

        if ($lead === null) {
            throw HttpException::notFound();
        }

        return $lead;
    }

    /** @return array<string,mixed> */
    public function stats(int $days = 90): array
    {
        $since = $this->clock->agoString($days);

        $row = $this->connection->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won,
                    SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open,
                    SUM(CASE WHEN first_response_at IS NULL AND status = 'open' THEN 1 ELSE 0 END) AS unanswered,
                    COALESCE(SUM(CASE WHEN status = 'won' THEN COALESCE(actual_value, estimated_value, 0) ELSE 0 END), 0) AS won_value
             FROM leads WHERE organisation_id = ? AND created_at >= ? AND deleted_at IS NULL",
            [$this->tenant->organisationId(), $since]
        ) ?? [];

        $total = (int) ($row['total'] ?? 0);
        $won   = (int) ($row['won'] ?? 0);

        return [
            'total'       => $total,
            'won'         => $won,
            'lost'        => (int) ($row['lost'] ?? 0),
            'open'        => (int) ($row['open'] ?? 0),
            'unanswered'  => (int) ($row['unanswered'] ?? 0),
            'won_value'   => (float) ($row['won_value'] ?? 0),
            'win_rate'    => $total > 0 ? round($won / $total * 100, 1) : 0.0,
            'currency'    => $this->tenant->currency(),
        ];
    }

    // ------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function defaultPipeline(): array
    {
        $pipeline = $this->connection->table('pipelines')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->whereNull('deleted_at')
            ->orderBy('is_default', 'desc')
            ->first();

        if ($pipeline === null) {
            throw new ValidationException(['pipeline' => ['This account has no lead pipeline set up.']]);
        }

        return $pipeline;
    }

    /** @return array<string,mixed> */
    private function firstStage(int $pipelineId): array
    {
        $stage = $this->connection->table('pipeline_stages')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('pipeline_id', '=', $pipelineId)
            ->orderBy('sort_order')
            ->first();

        if ($stage === null) {
            throw new ValidationException(['pipeline' => ['That pipeline has no stages.']]);
        }

        return $stage;
    }

    /** What a typical job is worth here, so "big" means something local. */
    private function averageJobValue(): float
    {
        $average = (float) $this->connection->scalar(
            "SELECT AVG(COALESCE(actual_value, estimated_value))
             FROM leads WHERE organisation_id = ? AND status = 'won'
               AND COALESCE(actual_value, estimated_value) > 0 AND deleted_at IS NULL",
            [$this->tenant->organisationId()]
        );

        // With no history yet, nothing counts as unusually big. Better than
        // inventing a threshold and scoring every enquiry the same.
        return $average > 0 ? $average : PHP_FLOAT_MAX;
    }

    private function source(string $source): string
    {
        $allowed = (array) $this->config->get('leads.sources', []);

        return isset($allowed[$source]) ? $source : 'manual';
    }
}
