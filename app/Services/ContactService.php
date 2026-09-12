<?php

declare(strict_types=1);

namespace App\Services;

use App\Compliance\ComplianceService;
use App\Core\Clock;
use App\Core\Config;
use App\Core\ValidationException;
use App\Database\Connection;
use App\Repositories\CompanyRepository;
use App\Repositories\ContactRepository;
use App\Repositories\CustomFieldRepository;
use App\Repositories\ListRepository;
use App\Repositories\SuppressionRepository;
use App\Repositories\TagRepository;
use App\Support\TenantContext;

final class ContactService
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly TagRepository $tags,
        private readonly ListRepository $lists,
        private readonly CompanyRepository $companies,
        private readonly CustomFieldRepository $customFields,
        private readonly SuppressionRepository $suppressions,
        private readonly ConsentService $consent,
        private readonly ComplianceService $compliance,
        private readonly ActivityService $activity,
        private readonly AuditService $audit,
        private readonly Connection $connection,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Create a contact.
     *
     * Deduplication is on normalized email per organisation, enforced both here
     * and by a unique index — the index is what holds under concurrency.
     *
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $consentEvidence when present, consent is recorded atomically with the contact
     */
    public function create(array $attributes, array $consentEvidence = []): int
    {
        $email = trim((string) ($attributes['email'] ?? ''));

        if (!is_valid_email($email)) {
            throw new ValidationException(['email' => ['Please enter a valid email address.']]);
        }

        $existing = $this->contacts->findByEmail($email);

        if ($existing !== null) {
            throw new ValidationException([
                'email' => ['A contact with this email address already exists in this organisation.'],
            ]);
        }

        return $this->connection->transaction(function () use ($attributes, $email, $consentEvidence): int {
            $companyId = $this->resolveCompanyId($attributes);

            $contactId = $this->contacts->create($this->sanitise($attributes, [
                'email'      => $email,
                'company_id' => $companyId,
            ]));

            if ($consentEvidence !== []) {
                $this->applyConsentEvidence($contactId, $consentEvidence);
            } else {
                // An explicit "we do not know" beats an absent record: it is what
                // makes the compliance gate able to block rather than guess.
                $this->consent->recordUnknown($contactId, ['source' => 'manual']);
            }

            $this->syncCustomFields($contactId, $attributes['custom_fields'] ?? []);
            $this->syncTags($contactId, $attributes['tag_ids'] ?? []);
            $this->syncLists($contactId, $attributes['list_ids'] ?? []);

            // If this address is already suppressed (a previous unsubscribe, a
            // bounce), the new contact inherits that state immediately. Creating
            // a contact never clears a suppression.
            if ($this->suppressions->isSuppressed($email)) {
                $this->contacts->setSuppressionCacheByEmail(normalize_email($email), true);
            }

            $this->audit->log('contact_created', 'contact', $contactId, null, [
                'email'  => \App\Support\Str::maskEmail($email),
                'source' => $attributes['source'] ?? null,
            ]);

            $this->activity->record(
                'contact_created',
                $contactId,
                'Contact created',
                ['source' => $attributes['source'] ?? 'manual']
            );

            return $contactId;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $contactId, array $attributes): void
    {
        $before = $this->contacts->findOrFail($contactId);

        if (isset($attributes['email'])) {
            $email = trim((string) $attributes['email']);

            if (!is_valid_email($email)) {
                throw new ValidationException(['email' => ['Please enter a valid email address.']]);
            }

            $duplicate = $this->contacts->findByEmail($email);

            if ($duplicate !== null && (int) $duplicate['id'] !== $contactId) {
                throw new ValidationException([
                    'email' => ['Another contact already uses this email address.'],
                ]);
            }
        }

        $this->connection->transaction(function () use ($contactId, $attributes, $before): void {
            $companyId = $this->resolveCompanyId($attributes);

            $payload = $this->sanitise($attributes);

            if ($companyId !== null) {
                $payload['company_id'] = $companyId;
            }

            $this->contacts->update($contactId, $payload);

            if (array_key_exists('custom_fields', $attributes)) {
                $this->syncCustomFields($contactId, $attributes['custom_fields'] ?? []);
            }

            if (array_key_exists('tag_ids', $attributes)) {
                $this->replaceTags($contactId, $attributes['tag_ids'] ?? []);
            }

            if (array_key_exists('list_ids', $attributes)) {
                $this->replaceLists($contactId, $attributes['list_ids'] ?? []);
            }

            $this->audit->log(
                'contact_updated',
                'contact',
                $contactId,
                $this->onlyChangedKeys($before, $payload),
                $payload
            );

            $this->activity->record('contact_updated', $contactId, 'Contact details updated');
        });
    }

    public function delete(int $contactId): void
    {
        $contact = $this->contacts->findOrFail($contactId);

        $this->contacts->softDelete($contactId);

        $this->audit->log('contact_deleted', 'contact', $contactId, [
            'email' => \App\Support\Str::maskEmail((string) $contact['email']),
        ]);
    }

    /**
     * Privacy deletion: anonymise in place.
     *
     * Personal fields are cleared, but the row, the consent history, the
     * suppression record and the audit trail survive — those are required to
     * honour the erasure itself (we must still not email the address) and to
     * demonstrate compliance later.
     */
    public function anonymise(int $contactId): void
    {
        $contact = $this->contacts->findOrFail($contactId);
        $email   = (string) $contact['email'];

        // The suppression is keyed on the address, so re-assert it BEFORE the
        // address is destroyed. Otherwise an erased contact could be re-imported
        // and mailed.
        if (!$this->suppressions->isSuppressed($email)) {
            $this->suppressions->suppress($email, 'legal', [
                'source' => 'user',
                'detail' => 'Contact anonymised following a data subject request',
            ]);
        }

        $placeholder = 'anonymised-' . substr(hash('sha256', $email . $contactId), 0, 16) . '@anonymised.invalid';

        $this->contacts->update($contactId, [
            'email'            => $placeholder,
            'email_normalized' => $placeholder,
            'first_name'       => null,
            'last_name'        => null,
            'phone'            => null,
            'phone_normalized' => null,
            'job_title'        => null,
            'city'             => null,
            'state'            => null,
            'postcode'         => null,
            'notes'            => null,
            'customer_status'  => 'lost',
            'deleted_at'       => $this->clock->nowString(),
        ]);

        $this->audit->log('contact_anonymised', 'contact', $contactId, [
            'email' => \App\Support\Str::maskEmail($email),
        ], ['reason' => 'data_subject_request']);
    }

    /**
     * Merge duplicate contacts, preserving everything that matters.
     *
     * Activity, consent history, tags, lists and revenue move to the surviving
     * contact. Consent is NOT recomputed — the rows are re-pointed, so the
     * evidence trail stays intact.
     */
    public function merge(int $keepId, int $mergeId): void
    {
        if ($keepId === $mergeId) {
            return;
        }

        $keep  = $this->contacts->findOrFail($keepId);
        $merge = $this->contacts->findOrFail($mergeId);

        $this->connection->transaction(function () use ($keep, $merge, $keepId, $mergeId): void {
            $organisationId = $this->tenant->organisationId();

            // Move consent history wholesale: it belongs to the person, not the row.
            $this->connection->execute(
                'UPDATE contact_consents SET contact_id = ? WHERE contact_id = ? AND organisation_id = ?',
                [$keepId, $mergeId, $organisationId]
            );

            foreach (['activity_logs', 'email_messages', 'conversions', 'tracking_events', 'leads', 'lead_notes', 'lead_tasks'] as $table) {
                $this->connection->execute(
                    "UPDATE {$table} SET contact_id = ? WHERE contact_id = ? AND organisation_id = ?",
                    [$keepId, $mergeId, $organisationId]
                );
            }

            // Tags and lists: move only the ones the survivor does not have, so
            // the unique constraints hold.
            foreach ([['contact_tags', 'tag_id'], ['list_contacts', 'list_id']] as [$table, $column]) {
                $rows = $this->connection->select(
                    "SELECT {$column} FROM {$table} WHERE contact_id = ? AND organisation_id = ?",
                    [$mergeId, $organisationId]
                );

                foreach ($rows as $row) {
                    $exists = $this->connection->table($table)
                        ->where('contact_id', '=', $keepId)
                        ->where($column, '=', (int) $row[$column])
                        ->exists();

                    if (!$exists) {
                        $this->connection->execute(
                            "UPDATE {$table} SET contact_id = ? WHERE contact_id = ? AND {$column} = ? AND organisation_id = ?",
                            [$keepId, $mergeId, (int) $row[$column], $organisationId]
                        );
                    }
                }

                $this->connection->table($table)
                    ->where('contact_id', '=', $mergeId)
                    ->where('organisation_id', '=', $organisationId)
                    ->delete();
            }

            // Revenue and engagement: sum the money, keep the most recent dates.
            $this->contacts->update($keepId, [
                'total_revenue'   => (float) $keep['total_revenue'] + (float) $merge['total_revenue'],
                'customer_value'  => max((float) $keep['customer_value'], (float) $merge['customer_value']),
                'purchase_count'  => (int) $keep['purchase_count'] + (int) $merge['purchase_count'],
                'last_purchase_at' => $this->latest($keep['last_purchase_at'] ?? null, $merge['last_purchase_at'] ?? null),
                'last_engagement_at' => $this->latest($keep['last_engagement_at'] ?? null, $merge['last_engagement_at'] ?? null),
                'lead_score'      => max((int) $keep['lead_score'], (int) $merge['lead_score']),
                'first_name'      => $keep['first_name'] ?: $merge['first_name'],
                'last_name'       => $keep['last_name'] ?: $merge['last_name'],
                'phone'           => $keep['phone'] ?: $merge['phone'],
                'company'         => $keep['company'] ?: $merge['company'],
            ]);

            // Custom field values: fill only the gaps on the survivor.
            $mergeValues = $this->connection->table('contact_custom_fields')
                ->where('organisation_id', '=', $organisationId)
                ->where('contact_id', '=', $mergeId)
                ->get();

            foreach ($mergeValues as $value) {
                $exists = $this->connection->table('contact_custom_fields')
                    ->where('contact_id', '=', $keepId)
                    ->where('custom_field_definition_id', '=', (int) $value['custom_field_definition_id'])
                    ->exists();

                if (!$exists) {
                    $this->connection->table('contact_custom_fields')
                        ->where('id', '=', (int) $value['id'])
                        ->update(['contact_id' => $keepId]);
                }
            }

            $this->contacts->softDelete($mergeId);

            $this->consent->recordUnknown($keepId, [
                'source'           => 'crm',
                'source_reference' => 'merge:' . $mergeId,
            ]);

            $this->audit->log('contacts_merged', 'contact', $keepId, [
                'merged_contact_id' => $mergeId,
                'merged_email'      => \App\Support\Str::maskEmail((string) $merge['email']),
            ], ['kept_contact_id' => $keepId]);
        });
    }

    /**
     * The Customer 360 view.
     *
     * Note the eligibility block: it shows the *live* decision with its reason,
     * rather than a green tick derived from a cached column, because that is what
     * the send path will actually decide.
     *
     * @return array<string,mixed>
     */
    public function profile(int $contactId): array
    {
        $contact = $this->contacts->findOrFail($contactId);

        $decision = $this->compliance->canSendMarketingEmail($this->tenant->organisation(), $contact);

        return [
            'contact'        => $contact,
            'tags'           => $this->tags->forContact($contactId),
            'lists'          => $this->lists->forContact($contactId),
            'custom_fields'  => $this->customFields->valuesForContact($contactId),
            'consent'        => $this->consent->current($contactId),
            'consent_history' => $this->consent->history($contactId),
            'suppression'    => $this->suppressions->findActive((string) $contact['email']),
            'timeline'       => $this->activity->timeline($contactId),
            'eligibility'    => $decision->toArray(),
            'company'        => ($contact['company_id'] ?? null) !== null
                ? $this->companies->find((int) $contact['company_id'])
                : null,
        ];
    }

    /**
     * @param array{search?:string,status?:string,lifecycle?:string,country?:string,tag_id?:int,list_id?:int,suppressed?:string,consent?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 25, string $sort = 'created_at', string $direction = 'desc'): array
    {
        $allowedSorts = [
            'created_at', 'email', 'first_name', 'last_name', 'customer_value',
            'total_revenue', 'lead_score', 'last_engagement_at', 'last_purchase_at',
        ];

        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }

        $maxPerPage = (int) $this->config->get('app.pagination.max_per_page', 200);
        $perPage    = max(1, min($perPage, $maxPerPage));

        $query = $this->contacts->filtered($filters);
        $total = (clone $query)->count();

        $rows = $query->orderBy($sort, $direction)->forPage($page, $perPage)->get();

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    public function addTag(int $contactId, int $tagId): void
    {
        $this->contacts->findOrFail($contactId);
        $tag = $this->tags->findOrFail($tagId);

        if ($this->tags->attach($contactId, $tagId)) {
            $this->activity->record('tag_added', $contactId, 'Tagged "' . $tag['name'] . '"', ['tag_id' => $tagId]);
        }
    }

    public function removeTag(int $contactId, int $tagId): void
    {
        $this->contacts->findOrFail($contactId);
        $tag = $this->tags->findOrFail($tagId);

        if ($this->tags->detach($contactId, $tagId)) {
            $this->activity->record('tag_removed', $contactId, 'Removed tag "' . $tag['name'] . '"', ['tag_id' => $tagId]);
        }
    }

    /**
     * Lead scoring from an observable event. The weights are configurable per
     * organisation; nothing here is a model output.
     */
    public function applyScoringEvent(int $contactId, string $event): void
    {
        /** @var array<string,int> $weights */
        $weights = $this->config->get('crm.lead_scoring', []);
        $delta   = (int) ($weights[$event] ?? 0);

        if ($delta === 0) {
            return;
        }

        $this->contacts->incrementLeadScore($contactId, $delta);

        $this->activity->record('lead_score_changed', $contactId, 'Lead score ' . ($delta > 0 ? '+' : '') . $delta, [
            'event' => $event,
            'delta' => $delta,
        ]);
    }

    // ------------------------------------------------------------- internals

    /**
     * Whitelist of writable columns. Anything not listed here cannot be set from
     * a request body, which keeps cached compliance mirrors and money fields out
     * of reach of mass assignment.
     *
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function sanitise(array $attributes, array $overrides = []): array
    {
        $writable = [
            'email', 'first_name', 'last_name', 'phone', 'company', 'job_title',
            'country', 'state', 'city', 'postcode', 'timezone', 'preferred_language',
            'source', 'source_detail', 'customer_status', 'lead_status',
            'lifecycle_stage', 'owner_user_id', 'notes', 'currency',
            'workspace_id', 'company_id', 'deleted_at',
        ];

        $payload = [];

        foreach ($writable as $column) {
            if (array_key_exists($column, $attributes)) {
                $value = $attributes[$column];

                if ($column === 'country' && is_string($value) && $value !== '') {
                    $value = strtoupper(substr($value, 0, 2));
                }

                if ($column === 'phone' && is_string($value) && $value !== '') {
                    $payload['phone_normalized'] = preg_replace('/[^0-9+]/', '', $value);
                }

                $payload[$column] = $value === '' ? null : $value;
            }
        }

        return array_merge($payload, $overrides);
    }

    /** @param array<string,mixed> $attributes */
    private function resolveCompanyId(array $attributes): ?int
    {
        if (isset($attributes['company_id']) && (int) $attributes['company_id'] > 0) {
            return (int) $attributes['company_id'];
        }

        $companyName = trim((string) ($attributes['company'] ?? ''));

        return $companyName === '' ? null : $this->companies->firstOrCreate($companyName);
    }

    /** @param array<string,mixed> $evidence */
    private function applyConsentEvidence(int $contactId, array $evidence): void
    {
        $status = (string) ($evidence['status'] ?? 'granted');

        match ($status) {
            'granted'   => $this->consent->grant($contactId, $evidence),
            'denied'    => $this->consent->deny($contactId, $evidence),
            'withdrawn' => $this->consent->withdraw($contactId, $evidence),
            default     => $this->consent->recordUnknown($contactId, $evidence),
        };
    }

    /** @param mixed $values */
    private function syncCustomFields(int $contactId, mixed $values): void
    {
        if (!is_array($values)) {
            return;
        }

        foreach ($this->customFields->definitions('contact') as $definition) {
            $key = (string) $definition['key'];

            if (!array_key_exists($key, $values)) {
                continue;
            }

            $this->customFields->setValue(
                $contactId,
                (int) $definition['id'],
                (string) $definition['type'],
                $values[$key]
            );
        }
    }

    /** @param mixed $tagIds */
    private function syncTags(int $contactId, mixed $tagIds): void
    {
        if (!is_array($tagIds)) {
            return;
        }

        foreach ($tagIds as $tagId) {
            $tagId = (int) $tagId;

            if ($tagId > 0 && $this->tags->find($tagId) !== null) {
                $this->tags->attach($contactId, $tagId);
            }
        }
    }

    /** @param mixed $tagIds */
    private function replaceTags(int $contactId, mixed $tagIds): void
    {
        $desired = array_map('intval', is_array($tagIds) ? $tagIds : []);
        $current = array_map(
            static fn (array $tag): int => (int) $tag['id'],
            $this->tags->forContact($contactId)
        );

        foreach (array_diff($current, $desired) as $tagId) {
            $this->tags->detach($contactId, $tagId);
        }

        foreach (array_diff($desired, $current) as $tagId) {
            if ($this->tags->find($tagId) !== null) {
                $this->tags->attach($contactId, $tagId);
            }
        }
    }

    /** @param mixed $listIds */
    private function syncLists(int $contactId, mixed $listIds): void
    {
        if (!is_array($listIds)) {
            return;
        }

        foreach ($listIds as $listId) {
            $listId = (int) $listId;

            if ($listId > 0 && $this->lists->find($listId) !== null) {
                $this->lists->addContact($listId, $contactId);
            }
        }
    }

    /** @param mixed $listIds */
    private function replaceLists(int $contactId, mixed $listIds): void
    {
        $desired = array_map('intval', is_array($listIds) ? $listIds : []);
        $current = array_map(
            static fn (array $list): int => (int) $list['id'],
            $this->lists->forContact($contactId)
        );

        foreach (array_diff($current, $desired) as $listId) {
            $this->lists->removeContact($listId, $contactId);
        }

        foreach (array_diff($desired, $current) as $listId) {
            if ($this->lists->find($listId) !== null) {
                $this->lists->addContact($listId, $contactId);
            }
        }
    }

    private function latest(?string $a, ?string $b): ?string
    {
        if ($a === null || $a === '') {
            return $b;
        }

        if ($b === null || $b === '') {
            return $a;
        }

        return $a >= $b ? $a : $b;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function onlyChangedKeys(array $before, array $changes): array
    {
        $old = [];

        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $before) && $before[$key] !== $changes[$key]) {
                $old[$key] = $before[$key];
            }
        }

        return $old;
    }
}
