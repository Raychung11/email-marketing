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
 * Signup forms.
 *
 * This is where consent actually comes from, which makes it the most important
 * file in the compliance story — everything else in the product defends a
 * permission that was captured here, and a permission captured badly cannot be
 * defended at all.
 *
 * So:
 *
 *  THE CONSENT BOX IS NEVER PRE-TICKED. Not configurable, not a setting somebody
 *  can turn on for a "better conversion rate". A pre-ticked box is not consent
 *  in any jurisdiction this product targets, and offering it would be selling
 *  customers a liability dressed as a feature.
 *
 *  THE EXACT WORDING IS STORED WITH THE SUBMISSION. Not a reference to the
 *  form's current text — the text as it was on screen at that moment, plus its
 *  version. The form gets edited; the evidence must not change with it.
 *
 *  SUBMITTING IS NOT CONSENT. A person filling in "get a quote" asked to be
 *  answered. They agreed to marketing only if they ticked the box that said so.
 *  The two are recorded separately because they are different things.
 *
 *  SUPPRESSION SURVIVES A RESUBSCRIBE FORM. Somebody who unsubscribed and later
 *  fills in a form is genuinely opting back in, and that is the one path allowed
 *  to clear an unsubscribe — a deliberate act by the person themselves, audited,
 *  and never touching a bounce or complaint suppression.
 */
final class FormService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContactService $contacts,
        private readonly \App\Repositories\ListRepository $lists,
        private readonly ConsentService $consent,
        private readonly SuppressionService $suppressions,
        private readonly LeadService $leads,
        private readonly EventTrackingService $events,
        private readonly TriggerDispatcher $triggers,
        private readonly AuthManager $auth,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly AuditService $audit,
    ) {
    }

    // -------------------------------------------------------------- authoring

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->connection->select(
            'SELECT * FROM forms WHERE organisation_id = ? AND deleted_at IS NULL ORDER BY created_at DESC',
            [$this->tenant->organisationId()]
        );
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $form = $this->connection->table('forms')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', $id)
            ->whereNull('deleted_at')
            ->first();

        if ($form === null) {
            throw HttpException::notFound();
        }

        $form['fields'] = $this->fields($id);

        return $form;
    }

    /** Public lookup by slug, for the hosted page. @return array<string,mixed>|null */
    public function findPublished(int $organisationId, string $slug): ?array
    {
        $form = $this->connection->table('forms')
            ->where('organisation_id', '=', $organisationId)
            ->where('slug', '=', $slug)
            ->where('status', '=', 'published')
            ->whereNull('deleted_at')
            ->first();

        if ($form === null) {
            return null;
        }

        $form['fields'] = $this->fields((int) $form['id'], $organisationId);

        return $form;
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $this->auth->authorise('forms.manage');

        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name === '') {
            throw new ValidationException(['name' => ['Give this form a name.']]);
        }

        $now = $this->clock->nowString();

        $id = $this->connection->table('forms')->insert([
            'organisation_id'          => $this->tenant->organisationId(),
            'uuid'                     => uuid4(),
            'name'                     => mb_substr($name, 0, 160),
            'slug'                     => $this->uniqueSlug(str_slug($name)),
            'form_type'                => in_array($attributes['form_type'] ?? '', ['hosted', 'embedded', 'popup'], true)
                ? (string) $attributes['form_type'] : 'hosted',
            'heading'                  => mb_substr((string) ($attributes['heading'] ?? $name), 0, 200),
            'intro'                    => mb_substr((string) ($attributes['intro'] ?? ''), 0, 2000) ?: null,
            'submit_label'             => mb_substr((string) ($attributes['submit_label'] ?? 'Send'), 0, 60),
            'success_message'          => mb_substr(
                (string) ($attributes['success_message'] ?? 'Thanks — we will be in touch.'),
                0,
                2000
            ),
            'consent_checkbox_enabled' => 1,
            'consent_checkbox_required' => (int) ($attributes['consent_checkbox_required'] ?? 0) === 1 ? 1 : 0,
            'consent_text'             => mb_substr(
                (string) ($attributes['consent_text'] ?? $this->defaultConsentText()),
                0,
                1000
            ),
            'consent_version'          => '1',
            'consent_type_granted'     => 'express',
            'create_lead'              => (int) ($attributes['create_lead'] ?? 0) === 1 ? 1 : 0,
            'target_list_ids'          => json_encode(array_map('intval', (array) ($attributes['target_list_ids'] ?? []))),
            'target_tag_ids'           => json_encode(array_map('intval', (array) ($attributes['target_tag_ids'] ?? []))),
            'honeypot_enabled'         => 1,
            'status'                   => 'draft',
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);

        // Every form needs somewhere to put an email address.
        $this->addField($id, ['field_key' => 'email', 'label' => 'Email address',
            'field_type' => 'email', 'is_required' => 1, 'maps_to_contact_field' => 'email']);
        $this->addField($id, ['field_key' => 'first_name', 'label' => 'First name',
            'field_type' => 'text', 'maps_to_contact_field' => 'first_name']);

        $this->audit->log('form_created', 'form', $id, null, ['name' => $name]);

        return $id;
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): void
    {
        $this->auth->authorise('forms.manage');

        $form    = $this->find($id);
        $payload = ['updated_at' => $this->clock->nowString()];

        foreach (['heading', 'intro', 'submit_label', 'success_message', 'redirect_url'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $payload[$field] = mb_substr((string) $attributes[$field], 0, 2000) ?: null;
            }
        }

        if (array_key_exists('consent_text', $attributes)) {
            $text = mb_substr((string) $attributes['consent_text'], 0, 1000);

            // Changing the wording starts a new version, so submissions already
            // taken keep pointing at the text those people actually saw.
            if ($text !== (string) $form['consent_text']) {
                $payload['consent_text']    = $text;
                $payload['consent_version'] = (string) ((int) $form['consent_version'] + 1);
            }
        }

        if (array_key_exists('consent_checkbox_required', $attributes)) {
            $payload['consent_checkbox_required'] = (int) $attributes['consent_checkbox_required'] === 1 ? 1 : 0;
        }

        if (array_key_exists('create_lead', $attributes)) {
            $payload['create_lead'] = (int) $attributes['create_lead'] === 1 ? 1 : 0;
        }

        $this->connection->table('forms')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', $id)
            ->update($payload);

        $this->audit->log('form_updated', 'form', $id, null, $payload);
    }

    public function publish(int $id): void
    {
        $this->auth->authorise('forms.manage');

        $form = $this->find($id);

        if (trim((string) $form['consent_text']) === '') {
            throw new ValidationException([
                'consent_text' => ['Say what people are agreeing to before you publish this.'],
            ]);
        }

        $this->connection->table('forms')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('id', '=', $id)
            ->update(['status' => 'published', 'updated_at' => $this->clock->nowString()]);

        $this->audit->log('form_published', 'form', $id);
    }

    /** @param array<string,mixed> $attributes */
    public function addField(int $formId, array $attributes): int
    {
        $types = ['text', 'email', 'phone', 'textarea', 'select', 'multi_select',
                  'checkbox', 'radio', 'number', 'date', 'hidden'];

        $type = (string) ($attributes['field_type'] ?? 'text');

        if (!in_array($type, $types, true)) {
            throw new ValidationException(['field_type' => ['That is not a kind of field this system has.']]);
        }

        // What a form field may write onto a contact is a whitelist, because a
        // public form posting arbitrary column names would be an obvious way in.
        $mapsTo = (string) ($attributes['maps_to_contact_field'] ?? '');
        $mappable = ['email', 'first_name', 'last_name', 'phone', 'company', 'job_title',
                     'city', 'state', 'postcode', 'country'];

        $now = $this->clock->nowString();

        return $this->connection->table('form_fields')->insert([
            'organisation_id'       => $this->tenant->organisationId(),
            'form_id'               => $formId,
            'field_key'             => mb_substr((string) ($attributes['field_key'] ?? uniqid('f', false)), 0, 60),
            'label'                 => mb_substr((string) ($attributes['label'] ?? 'Field'), 0, 120),
            'field_type'            => $type,
            'placeholder'           => mb_substr((string) ($attributes['placeholder'] ?? ''), 0, 160) ?: null,
            'options'               => isset($attributes['options']) && is_array($attributes['options'])
                ? json_encode(array_slice($attributes['options'], 0, 40), JSON_UNESCAPED_SLASHES) : null,
            'is_required'           => (int) ($attributes['is_required'] ?? 0) === 1 ? 1 : 0,
            'maps_to_contact_field' => in_array($mapsTo, $mappable, true) ? $mapsTo : null,
            'sort_order'            => (int) ($attributes['sort_order'] ?? 0),
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);
    }

    // ------------------------------------------------------------ submitting

    /**
     * Somebody filled the form in.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $meta  ip, user agent, referrer
     * @return array{contact_id:?int,lead_id:?int,message:string,redirect:?string}
     */
    public function submit(array $form, array $input, array $meta = []): array
    {
        // The honeypot: a field a person never sees and a bot fills in anyway.
        // Recorded rather than rejected outright, so a false positive is
        // recoverable instead of a silently lost customer.
        $spam = (int) $form['honeypot_enabled'] === 1 && trim((string) ($input['website_url'] ?? '')) !== '';

        $values = $this->mapFields($form, $input);
        $email  = trim((string) ($values['email'] ?? ''));

        if (!is_valid_email($email)) {
            throw new ValidationException(['email' => ['Please enter a valid email address.']]);
        }

        foreach ($form['fields'] as $field) {
            if ((int) $field['is_required'] === 1
                && trim((string) ($input[$field['field_key']] ?? '')) === ''
            ) {
                throw new ValidationException([
                    (string) $field['field_key'] => ['Please fill in ' . strtolower((string) $field['label']) . '.'],
                ]);
            }
        }

        // Ticked, or not. Never assumed, and never defaulted to true.
        $consentGiven = in_array($input['consent'] ?? null, ['1', 1, 'on', true], true);

        if ((int) $form['consent_checkbox_required'] === 1 && !$consentGiven) {
            throw new ValidationException([
                'consent' => ['Please tick the box to say we can email you.'],
            ]);
        }

        $now = $this->clock->nowString();

        if ($spam) {
            $this->recordSubmission($form, null, null, $input, $consentGiven, $meta, true);

            // Answered as if it worked. Telling a bot it was detected only helps
            // whoever wrote it.
            return [
                'contact_id' => null,
                'lead_id'    => null,
                'message'    => (string) $form['success_message'],
                'redirect'   => $form['redirect_url'] ?? null,
            ];
        }

        $contactId = $this->contacts->findOrCreateByEmail($email, array_merge(
            array_diff_key($values, ['email' => null]),
            ['source' => 'website_form']
        ));

        // The evidence: the wording as it was on screen, its version, when, from
        // where. Not a pointer to the form's current text, which will change.
        if ($consentGiven) {
            $this->consent->grant($contactId, [
                'channel'               => 'email',
                'consent_type'          => (string) $form['consent_type_granted'],
                'source'                => 'website_form',
                'source_reference'      => 'Form: ' . (string) $form['name'],
                'consent_text'          => (string) $form['consent_text'],
                'privacy_policy_version' => (string) $form['consent_version'],
                'ip_address'            => $meta['ip'] ?? null,
                'user_agent'            => $meta['user_agent'] ?? null,
            ]);

            // A person who unsubscribed and has now deliberately opted in again
            // is the one case where a suppression may be lifted — and only an
            // unsubscribe, never a bounce or a complaint.
            $this->suppressions->clearOnContactReaffirmation(
                $email,
                'the form "' . (string) $form['name'] . '"'
            );
        }

        $this->applyListsAndTags($form, $contactId);

        $leadId = null;

        if ((int) $form['create_lead'] === 1) {
            $leadId = $this->leads->create([
                'contact_id'    => $contactId,
                'email'         => $email,
                'title'         => (string) ($form['heading'] ?? $form['name']),
                'enquiry'       => $this->enquiryText($form, $input),
                'source'        => 'website_form',
                'source_detail' => (string) $form['name'],
            ]);
        }

        $this->recordSubmission($form, $contactId, $leadId, $input, $consentGiven, $meta, false);

        $this->connection->execute(
            'UPDATE forms SET submission_count = submission_count + 1, updated_at = ? WHERE id = ?',
            [$now, (int) $form['id']]
        );

        // Tie up their anonymous browsing, now that they have told us who they are.
        if (($meta['anonymous_id'] ?? '') !== '') {
            $this->events->identify((string) $meta['anonymous_id'], $contactId);
        }

        $this->triggers->fire('form_submitted', $contactId, [
            'form_id'   => (int) $form['id'],
            'reference' => (string) $form['name'],
        ]);

        return [
            'contact_id' => $contactId,
            'lead_id'    => $leadId,
            'message'    => (string) $form['success_message'],
            'redirect'   => $form['redirect_url'] ?? null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function submissions(int $formId, int $limit = 50): array
    {
        return $this->connection->select(
            'SELECT * FROM form_submissions WHERE organisation_id = ? AND form_id = ?
             ORDER BY created_at DESC LIMIT ' . max(1, $limit),
            [$this->tenant->organisationId(), $formId]
        );
    }

    // ------------------------------------------------------------ internals

    /** @return array<int,array<string,mixed>> */
    private function fields(int $formId, ?int $organisationId = null): array
    {
        return $this->connection->select(
            'SELECT * FROM form_fields WHERE organisation_id = ? AND form_id = ? ORDER BY sort_order, id',
            [$organisationId ?? $this->tenant->organisationId(), $formId]
        );
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function mapFields(array $form, array $input): array
    {
        $values = [];

        foreach ($form['fields'] as $field) {
            $target = (string) ($field['maps_to_contact_field'] ?? '');

            if ($target === '') {
                continue;
            }

            $value = $input[(string) $field['field_key']] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $values[$target] = mb_substr(trim($value), 0, 255);
            }
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $input
     */
    private function enquiryText(array $form, array $input): string
    {
        $lines = [];

        foreach ($form['fields'] as $field) {
            if (($field['maps_to_contact_field'] ?? null) !== null) {
                continue;
            }

            $value = $input[(string) $field['field_key']] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $lines[] = (string) $field['label'] . ': ' . mb_substr(trim($value), 0, 500);
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string,mixed> $form */
    private function applyListsAndTags(array $form, int $contactId): void
    {
        foreach ((array) json_decode((string) ($form['target_list_ids'] ?? '[]'), true) as $listId) {
            if ((int) $listId > 0) {
                $this->lists->addContact((int) $listId, $contactId, 'form');
            }
        }

        foreach ((array) json_decode((string) ($form['target_tag_ids'] ?? '[]'), true) as $tagId) {
            if ((int) $tagId > 0) {
                $this->contacts->addTag($contactId, (int) $tagId);
            }
        }
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $input
     * @param array<string,mixed> $meta
     */
    private function recordSubmission(
        array $form,
        ?int $contactId,
        ?int $leadId,
        array $input,
        bool $consentGiven,
        array $meta,
        bool $spam,
    ): void {
        $this->connection->table('form_submissions')->insert([
            'organisation_id'    => (int) $form['organisation_id'],
            'form_id'            => (int) $form['id'],
            'contact_id'         => $contactId,
            'lead_id'            => $leadId,
            'payload'            => json_encode($this->safePayload($input), JSON_UNESCAPED_SLASHES),
            'consent_given'      => $consentGiven ? 1 : 0,
            // The wording as it was, not a reference to what it will become.
            'consent_text_shown' => $consentGiven ? (string) $form['consent_text'] : null,
            'consent_version'    => $consentGiven ? (string) $form['consent_version'] : null,
            'ip_address'         => mb_substr((string) ($meta['ip'] ?? ''), 0, 45) ?: null,
            'user_agent'         => mb_substr((string) ($meta['user_agent'] ?? ''), 0, 255) ?: null,
            'referrer'           => mb_substr((string) ($meta['referrer'] ?? ''), 0, 500) ?: null,
            'is_spam'            => $spam ? 1 : 0,
            'created_at'         => $this->clock->nowString(),
        ]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function safePayload(array $input): array
    {
        unset($input['_token'], $input['consent'], $input['website_url']);

        $safe = [];

        foreach ($input as $key => $value) {
            if (count($safe) >= 40 || !is_scalar($value)) {
                continue;
            }

            $safe[mb_substr((string) $key, 0, 60)] = mb_substr((string) $value, 0, 1000);
        }

        return $safe;
    }

    private function uniqueSlug(string $base): string
    {
        $base = $base !== '' ? $base : 'form';
        $slug = $base;
        $n    = 1;

        while ($this->connection->table('forms')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('slug', '=', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }

    private function defaultConsentText(): string
    {
        $name = (string) ($this->tenant->organisation()['name'] ?? 'us');

        return 'Yes, ' . $name . ' can email me about offers and news. '
            . 'I can unsubscribe at any time using the link in any email.';
    }
}
