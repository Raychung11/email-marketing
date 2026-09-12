<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Compliance\ComplianceService;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ContactRepository;
use App\Services\ConsentService;
use App\Services\ContactService;
use App\Support\TenantContext;

/**
 * /api/v1/contacts
 *
 * Note what the API deliberately cannot do: it cannot grant consent it has no
 * evidence for, and it cannot clear a suppression. A contact created through the
 * API with no consent payload gets an explicit `unknown` consent record, which
 * means marketing sending stays blocked in jurisdictions that require a basis —
 * the same behaviour as the UI, because an integration is not a loophole.
 */
final class ContactApiController
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly ContactRepository $repository,
        private readonly ConsentService $consent,
        private readonly ComplianceService $compliance,
        private readonly TenantContext $tenant,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request): Response
    {
        $perPage = max(1, min($request->int('per_page', 50), 200));
        $page    = max(1, $request->int('page', 1));

        $result = $this->contacts->paginate([
            'search'    => $request->string('search'),
            'status'    => $request->string('status'),
            'country'   => $request->string('country'),
            'consent'   => $request->string('consent'),
            'tag_id'    => $request->int('tag_id'),
            'list_id'   => $request->int('list_id'),
        ], $page, $perPage);

        return Response::json([
            'data' => array_map([$this, 'present'], $result['rows']),
            'meta' => [
                'page'     => $result['page'],
                'per_page' => $result['per_page'],
                'total'    => $result['total'],
                'pages'    => $result['pages'],
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        $contact = $this->repository->findByUuid((string) $request->route('uuid'));

        if ($contact === null) {
            return Response::json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Contact not found.']], 404);
        }

        $decision = $this->compliance->canSendMarketingEmail($this->tenant->organisation(), $contact);

        return Response::json([
            'data' => array_merge($this->present($contact), [
                'consent'     => $this->consent->current((int) $contact['id']),
                'eligibility' => $decision->toArray(),
            ]),
        ]);
    }

    /**
     * Create or update by email (upsert), which is what an integration almost
     * always wants.
     */
    public function store(Request $request): Response
    {
        $email = $request->string('email');

        if (!is_valid_email($email)) {
            return Response::json([
                'error' => [
                    'code'    => 'VALIDATION_FAILED',
                    'message' => 'A valid email address is required.',
                    'errors'  => ['email' => ['A valid email address is required.']],
                ],
            ], 422);
        }

        $attributes = [
            'email'           => $email,
            'first_name'      => $request->string('first_name') ?: null,
            'last_name'       => $request->string('last_name') ?: null,
            'phone'           => $request->string('phone') ?: null,
            'company'         => $request->string('company') ?: null,
            'job_title'       => $request->string('job_title') ?: null,
            'country'         => $request->string('country') ?: null,
            'state'           => $request->string('state') ?: null,
            'city'            => $request->string('city') ?: null,
            'postcode'        => $request->string('postcode') ?: null,
            'source'          => $request->string('source', 'api'),
            'source_detail'   => $request->string('source_detail') ?: null,
            'customer_status' => $request->string('customer_status') ?: null,
            'lifecycle_stage' => $request->string('lifecycle_stage') ?: null,
        ];

        $attributes = array_filter($attributes, static fn ($value): bool => $value !== null);

        $existing = $this->repository->findByEmail($email);

        if ($existing !== null) {
            $this->contacts->update((int) $existing['id'], $attributes);
            $contact = $this->repository->find((int) $existing['id']);

            return Response::json(['data' => $this->present($contact ?? $existing)], 200);
        }

        $contactId = $this->contacts->create($attributes, $this->consentEvidenceFrom($request));
        $contact   = $this->repository->find($contactId);

        return Response::json(['data' => $this->present($contact ?? [])], 201);
    }

    public function update(Request $request): Response
    {
        $contact = $this->repository->findByUuid((string) $request->route('uuid'));

        if ($contact === null) {
            return Response::json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Contact not found.']], 404);
        }

        $attributes = array_filter([
            'first_name'      => $request->input('first_name'),
            'last_name'       => $request->input('last_name'),
            'phone'           => $request->input('phone'),
            'company'         => $request->input('company'),
            'job_title'       => $request->input('job_title'),
            'country'         => $request->input('country'),
            'state'           => $request->input('state'),
            'city'            => $request->input('city'),
            'postcode'        => $request->input('postcode'),
            'customer_status' => $request->input('customer_status'),
            'lifecycle_stage' => $request->input('lifecycle_stage'),
        ], static fn ($value): bool => $value !== null);

        $this->contacts->update((int) $contact['id'], $attributes);

        $updated = $this->repository->find((int) $contact['id']);

        return Response::json(['data' => $this->present($updated ?? $contact)]);
    }

    public function destroy(Request $request): Response
    {
        $contact = $this->repository->findByUuid((string) $request->route('uuid'));

        if ($contact === null) {
            return Response::json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Contact not found.']], 404);
        }

        $this->contacts->delete((int) $contact['id']);

        return Response::noContent();
    }

    /**
     * Record consent with evidence.
     *
     * An integration CAN record a consent grant — that is how a website form or a
     * checkout reports an opt-in — but it must supply the evidence, and the record
     * is appended to the history like any other.
     */
    public function recordConsent(Request $request): Response
    {
        $contact = $this->repository->findByUuid((string) $request->route('uuid'));

        if ($contact === null) {
            return Response::json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Contact not found.']], 404);
        }

        $status = $request->string('status', 'granted');

        if (!in_array($status, ['granted', 'withdrawn', 'denied', 'unknown'], true)) {
            return Response::json([
                'error' => ['code' => 'VALIDATION_FAILED', 'message' => 'status must be granted, withdrawn, denied or unknown.'],
            ], 422);
        }

        $evidence = [
            'channel'          => $request->string('channel', 'email'),
            'consent_type'     => $request->string('consent_type', 'express'),
            'source'           => 'api',
            'source_reference' => $request->string('source_reference') ?: null,
            'consent_text'     => $request->string('consent_text') ?: null,
            'ip_address'       => $request->string('contact_ip') ?: null,
            'user_agent'       => $request->string('contact_user_agent') ?: null,
        ];

        match ($status) {
            'granted'   => $this->consent->grant((int) $contact['id'], $evidence),
            'withdrawn' => $this->consent->withdraw((int) $contact['id'], $evidence),
            'denied'    => $this->consent->deny((int) $contact['id'], $evidence),
            default     => $this->consent->recordUnknown((int) $contact['id'], $evidence),
        };

        return Response::json([
            'data' => [
                'contact_uuid' => $contact['uuid'],
                'consent'      => $this->consent->current((int) $contact['id'], (string) $evidence['channel']),
            ],
        ], 201);
    }

    /** @return array<string,mixed> */
    private function consentEvidenceFrom(Request $request): array
    {
        $consent = $request->input('consent');

        if (!is_array($consent)) {
            return [];
        }

        return [
            'status'           => (string) ($consent['status'] ?? 'unknown'),
            'consent_type'     => (string) ($consent['consent_type'] ?? 'express'),
            'source'           => 'api',
            'source_reference' => isset($consent['source_reference']) ? (string) $consent['source_reference'] : null,
            'consent_text'     => isset($consent['consent_text']) ? (string) $consent['consent_text'] : null,
            'ip_address'       => isset($consent['ip_address']) ? (string) $consent['ip_address'] : null,
            'user_agent'       => isset($consent['user_agent']) ? (string) $consent['user_agent'] : null,
        ];
    }

    /**
     * Public representation.
     *
     * Internal ids never leave the application; integrations address contacts by
     * UUID. The cached compliance mirrors are exposed as hints with clear names,
     * and the authoritative eligibility decision is on the show endpoint.
     *
     * @param array<string,mixed> $contact
     * @return array<string,mixed>
     */
    private function present(array $contact): array
    {
        return [
            'uuid'            => $contact['uuid'] ?? null,
            'email'           => $contact['email'] ?? null,
            'first_name'      => $contact['first_name'] ?? null,
            'last_name'       => $contact['last_name'] ?? null,
            'phone'           => $contact['phone'] ?? null,
            'company'         => $contact['company'] ?? null,
            'job_title'       => $contact['job_title'] ?? null,
            'country'         => $contact['country'] ?? null,
            'state'           => $contact['state'] ?? null,
            'city'            => $contact['city'] ?? null,
            'postcode'        => $contact['postcode'] ?? null,
            'source'          => $contact['source'] ?? null,
            'customer_status' => $contact['customer_status'] ?? null,
            'lifecycle_stage' => $contact['lifecycle_stage'] ?? null,
            'lead_score'      => isset($contact['lead_score']) ? (int) $contact['lead_score'] : 0,
            'customer_value'  => isset($contact['customer_value']) ? (float) $contact['customer_value'] : 0.0,
            'total_revenue'   => isset($contact['total_revenue']) ? (float) $contact['total_revenue'] : 0.0,
            'purchase_count'  => isset($contact['purchase_count']) ? (int) $contact['purchase_count'] : 0,
            'currency'        => $contact['currency'] ?? $this->tenant->currency(),
            'marketing_consent' => (bool) ($contact['marketing_consent_cache'] ?? false),
            'suppressed'      => (bool) ($contact['is_suppressed_cache'] ?? false),
            'created_at'      => $contact['created_at'] ?? null,
            'updated_at'      => $contact['updated_at'] ?? null,
        ];
    }
}
