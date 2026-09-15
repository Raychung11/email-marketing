<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\MembershipRepository;
use App\Services\LeadService;
use App\Support\TenantContext;

final class LeadController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly LeadService $leads,
        private readonly MembershipRepository $memberships,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($view, $session, $config);
    }

    /** GET /leads */
    public function index(Request $request): Response
    {
        return $this->render('leads.index', [
            'unanswered' => $this->leads->unanswered(),
            'stats'      => $this->leads->stats(90),
            'sources'    => $this->config->get('leads.sources', []),
        ]);
    }

    /** GET /leads/pipeline */
    public function pipeline(Request $request): Response
    {
        return $this->render('leads.pipeline', [
            'board' => $this->leads->board(),
            'stats' => $this->leads->stats(90),
        ]);
    }

    /** GET /leads/{id} */
    public function show(Request $request): Response
    {
        $id = (int) $request->route('id');

        return $this->render('leads.show', [
            'lead'    => $this->leads->findOrFail($id),
            'scoring' => $this->leads->score($id),
            'team'    => $this->memberships->membersOf($this->tenant->organisationId()),
        ]);
    }

    /** POST /leads */
    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'email'           => 'required|email',
            'title'           => 'nullable|max:200',
            'enquiry'         => 'nullable|max:5000',
            'estimated_value' => 'nullable|numeric',
        ]);

        $id = $this->leads->create($data + ['source' => $request->string('source') ?: 'manual']);

        return $this->withSuccess('/leads/' . $id, 'Enquiry saved.');
    }

    /** POST /leads/{id}/stage */
    public function moveStage(Request $request): Response
    {
        $id = (int) $request->route('id');

        $this->leads->moveToStage($id, $request->int('stage_id'), $request->string('note'));

        return $this->back($request, '/leads/pipeline');
    }

    /** POST /leads/{id}/responded */
    public function markResponded(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->leads->markResponded($id);

        return $this->withSuccess('/leads/' . $id, 'Marked as answered.');
    }
}
