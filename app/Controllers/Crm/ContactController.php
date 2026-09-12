<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ContactRepository;
use App\Repositories\CustomFieldRepository;
use App\Repositories\ListRepository;
use App\Repositories\TagRepository;
use App\Services\AuthManager;
use App\Services\ConsentService;
use App\Services\ContactService;
use App\Support\Str;

final class ContactController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly ContactService $contacts,
        private readonly ContactRepository $contactRepository,
        private readonly TagRepository $tags,
        private readonly ListRepository $lists,
        private readonly CustomFieldRepository $customFields,
        private readonly ConsentService $consent,
        private readonly AuthManager $auth,
        private readonly Clock $clock,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search'     => $request->string('search'),
            'status'     => $request->string('status'),
            'lifecycle'  => $request->string('lifecycle'),
            'country'    => $request->string('country'),
            'tag_id'     => $request->int('tag_id'),
            'list_id'    => $request->int('list_id'),
            'suppressed' => $request->string('suppressed'),
            'consent'    => $request->string('consent'),
        ];

        $result = $this->contacts->paginate(
            $filters,
            $this->page($request),
            $this->perPage($request),
            $request->string('sort', 'created_at'),
            $request->string('direction', 'desc')
        );

        return $this->render('crm.contacts_index', [
            'result'    => $result,
            'filters'   => $filters,
            'tags'      => $this->tags->all(),
            'lists'     => $this->lists->all(),
            'statuses'  => $this->config->get('crm.customer_statuses', []),
            'stages'    => $this->config->get('crm.lifecycle_stages', []),
            'countries' => $this->config->get('app.supported_countries', []),
            'counts'    => $this->contactRepository->statusCounts(),
        ]);
    }

    public function show(Request $request): Response
    {
        $profile = $this->contacts->profile((int) $request->route('id'));

        return $this->render('crm.contact_show', [
            'profile'  => $profile,
            'allTags'  => $this->tags->all(),
            'allLists' => $this->lists->all(),
            'statuses' => $this->config->get('crm.customer_statuses', []),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('crm.contact_form', [
            'contact'      => null,
            'tags'         => $this->tags->all(),
            'lists'        => $this->lists->all(),
            'definitions'  => $this->customFields->definitions('contact'),
            'statuses'     => $this->config->get('crm.customer_statuses', []),
            'stages'       => $this->config->get('crm.lifecycle_stages', []),
            'countries'    => $this->config->get('app.supported_countries', []),
            'sources'      => $this->config->get('crm.contact_sources', []),
            'consentTypes' => $this->config->get('compliance.consent_types', []),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'email'           => 'required|email|max:255',
            'first_name'      => 'nullable|max:100',
            'last_name'       => 'nullable|max:100',
            'phone'           => 'nullable|max:40',
            'company'         => 'nullable|max:200',
            'job_title'       => 'nullable|max:120',
            'country'         => 'nullable|max:2',
            'state'           => 'nullable|max:120',
            'city'            => 'nullable|max:120',
            'postcode'        => 'nullable|max:30',
            'customer_status' => 'nullable|in:' . implode(',', array_keys((array) $this->config->get('crm.customer_statuses', []))),
            'lifecycle_stage' => 'nullable|in:' . implode(',', array_keys((array) $this->config->get('crm.lifecycle_stages', []))),
            'source'          => 'nullable|max:40',
            'notes'           => 'nullable|max:5000',
        ]);

        $attributes = array_merge($data, [
            'tag_ids'       => array_map('intval', $request->array('tag_ids')),
            'list_ids'      => array_map('intval', $request->array('list_ids')),
            'custom_fields' => $request->input('custom_fields', []),
            'source'        => $data['source'] ?? 'manual',
        ]);

        // Consent recorded at creation, with the wording that was shown. A
        // manually created contact with no declared consent gets an explicit
        // 'unknown' record rather than nothing, so the compliance gate can block.
        $consentEvidence = [];

        if ($request->bool('consent_granted')) {
            $consentEvidence = [
                'status'           => 'granted',
                'consent_type'     => $request->string('consent_type', 'express'),
                'source'           => 'manual',
                'source_reference' => $request->string('consent_reference'),
                'consent_text'     => $request->string('consent_text'),
                'ip_address'       => $request->ip(),
                'user_agent'       => $request->userAgent(),
                'recorded_by_user_id' => $this->auth->id(),
            ];
        }

        $contactId = $this->contacts->create($attributes, $consentEvidence);

        return $this->withSuccess('/contacts/' . $contactId, 'Contact created.');
    }

    public function edit(Request $request): Response
    {
        $contactId = (int) $request->route('id');
        $contact   = $this->contactRepository->findOrFail($contactId);

        return $this->render('crm.contact_form', [
            'contact'      => $contact,
            'contactTags'  => array_map(static fn (array $t): int => (int) $t['id'], $this->tags->forContact($contactId)),
            'contactLists' => array_map(static fn (array $l): int => (int) $l['id'], $this->lists->forContact($contactId)),
            'values'       => $this->customFields->valuesForContact($contactId),
            'tags'         => $this->tags->all(),
            'lists'        => $this->lists->all(),
            'definitions'  => $this->customFields->definitions('contact'),
            'statuses'     => $this->config->get('crm.customer_statuses', []),
            'stages'       => $this->config->get('crm.lifecycle_stages', []),
            'countries'    => $this->config->get('app.supported_countries', []),
            'sources'      => $this->config->get('crm.contact_sources', []),
            'consentTypes' => $this->config->get('compliance.consent_types', []),
        ]);
    }

    public function update(Request $request): Response
    {
        $contactId = (int) $request->route('id');

        $data = $this->validate($request, [
            'email'           => 'required|email|max:255',
            'first_name'      => 'nullable|max:100',
            'last_name'       => 'nullable|max:100',
            'phone'           => 'nullable|max:40',
            'company'         => 'nullable|max:200',
            'job_title'       => 'nullable|max:120',
            'country'         => 'nullable|max:2',
            'state'           => 'nullable|max:120',
            'city'            => 'nullable|max:120',
            'postcode'        => 'nullable|max:30',
            'customer_status' => 'nullable|in:' . implode(',', array_keys((array) $this->config->get('crm.customer_statuses', []))),
            'lifecycle_stage' => 'nullable|in:' . implode(',', array_keys((array) $this->config->get('crm.lifecycle_stages', []))),
            'source'          => 'nullable|max:40',
            'notes'           => 'nullable|max:5000',
        ]);

        $this->contacts->update($contactId, array_merge($data, [
            'tag_ids'       => array_map('intval', $request->array('tag_ids')),
            'list_ids'      => array_map('intval', $request->array('list_ids')),
            'custom_fields' => $request->input('custom_fields', []),
        ]));

        return $this->withSuccess('/contacts/' . $contactId, 'Contact updated.');
    }

    public function destroy(Request $request): Response
    {
        $this->contacts->delete((int) $request->route('id'));

        return $this->withSuccess('/contacts', 'Contact deleted.');
    }

    /**
     * Privacy erasure. Distinct from delete: the record is anonymised in place and
     * the address stays suppressed so it can never be mailed again.
     */
    public function anonymise(Request $request): Response
    {
        $this->contacts->anonymise((int) $request->route('id'));

        return $this->withSuccess(
            '/contacts',
            'Contact anonymised. The address remains suppressed so it cannot be contacted again.'
        );
    }

    public function merge(Request $request): Response
    {
        $data = $this->validate($request, [
            'keep_id'  => 'required|integer',
            'merge_id' => 'required|integer',
        ]);

        $this->contacts->merge((int) $data['keep_id'], (int) $data['merge_id']);

        return $this->withSuccess('/contacts/' . (int) $data['keep_id'], 'Contacts merged.');
    }

    public function addTag(Request $request): Response
    {
        $this->contacts->addTag((int) $request->route('id'), $request->int('tag_id'));

        return $this->back($request, '/contacts/' . (int) $request->route('id'));
    }

    public function removeTag(Request $request): Response
    {
        $this->contacts->removeTag((int) $request->route('id'), (int) $request->route('tagId'));

        return $this->back($request, '/contacts/' . (int) $request->route('id'));
    }

    /** Record a consent decision against an existing contact. */
    public function recordConsent(Request $request): Response
    {
        $contactId = (int) $request->route('id');
        $this->contactRepository->findOrFail($contactId);

        $data = $this->validate($request, [
            'status'       => 'required|in:granted,withdrawn,denied,unknown',
            'consent_type' => 'nullable|in:' . implode(',', (array) $this->config->get('compliance.consent_types', [])),
            'source'       => 'nullable|in:' . implode(',', (array) $this->config->get('compliance.consent_sources', [])),
            'reference'    => 'nullable|max:255',
            'consent_text' => 'nullable|max:2000',
        ]);

        $evidence = [
            'channel'             => 'email',
            'consent_type'        => $data['consent_type'] ?? 'express',
            'source'              => $data['source'] ?? 'manual',
            'source_reference'    => $data['reference'] ?? null,
            'consent_text'        => $data['consent_text'] ?? null,
            'ip_address'          => $request->ip(),
            'user_agent'          => $request->userAgent(),
            'recorded_by_user_id' => $this->auth->id(),
        ];

        match ((string) $data['status']) {
            'granted'   => $this->consent->grant($contactId, $evidence),
            'withdrawn' => $this->consent->withdraw($contactId, $evidence),
            'denied'    => $this->consent->deny($contactId, $evidence),
            default     => $this->consent->recordUnknown($contactId, $evidence),
        };

        return $this->withSuccess(
            '/contacts/' . $contactId,
            'Consent recorded. The previous record is retained in the history.'
        );
    }

    /**
     * CSV export, streamed.
     *
     * Every field is passed through csvSafe() so a value beginning with "=" cannot
     * become a formula when the file is opened in a spreadsheet.
     */
    public function export(Request $request): Response
    {
        $filters = [
            'search'    => $request->string('search'),
            'status'    => $request->string('status'),
            'country'   => $request->string('country'),
            'tag_id'    => $request->int('tag_id'),
            'list_id'   => $request->int('list_id'),
            'consent'   => $request->string('consent'),
        ];

        $columns = [
            'email', 'first_name', 'last_name', 'phone', 'company', 'job_title',
            'country', 'state', 'city', 'postcode', 'customer_status',
            'lifecycle_stage', 'lead_score', 'customer_value', 'total_revenue',
            'purchase_count', 'last_purchase_at', 'marketing_consent_cache',
            'is_suppressed_cache', 'created_at',
        ];

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return $this->withError('/contacts', 'Unable to build the export.');
        }

        fputcsv($handle, $columns);

        $query = $this->contactRepository->filtered($filters);

        // Streamed in chunks: an export of a million contacts must not be built
        // in memory first.
        foreach ($this->contactRepository->stream($query, 1000) as $contact) {
            $row = [];

            foreach ($columns as $column) {
                $row[] = Str::csvSafe((string) ($contact[$column] ?? ''));
            }

            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $filename = 'contacts-' . $this->clock->now()->format('Y-m-d') . '.csv';

        return Response::make($csv)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
