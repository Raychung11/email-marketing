<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CompanyRepository;
use App\Services\AuditService;

final class CompanyController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly CompanyRepository $companies,
        private readonly AuditService $audit,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        $filters = ['search' => $request->string('search'), 'country' => $request->string('country')];
        $query   = $this->companies->filtered($filters);
        $total   = (clone $query)->count();
        $perPage = $this->perPage($request);

        return $this->render('crm.companies_index', [
            'companies' => $query->forPage($this->page($request), $perPage)->get(),
            'filters'   => $filters,
            'total'     => $total,
            'page'      => $this->page($request),
            'perPage'   => $perPage,
            'pages'     => (int) ceil($total / max(1, $perPage)),
            'countries' => $this->config->get('app.supported_countries', []),
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->route('id');

        return $this->render('crm.company_show', [
            'company'  => $this->companies->findOrFail($id),
            'contacts' => $this->companies->contacts($id),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'     => 'required|max:200',
            'domain'   => 'nullable|max:190',
            'industry' => 'nullable|max:60',
            'phone'    => 'nullable|max:40',
            'website'  => 'nullable|max:255',
            'country'  => 'nullable|max:2',
            'city'     => 'nullable|max:120',
            'state'    => 'nullable|max:120',
            'postcode' => 'nullable|max:30',
        ]);

        $id = $this->companies->create($data);
        $this->audit->log('company_created', 'company', $id, null, ['name' => $data['name']]);

        return $this->withSuccess('/companies/' . $id, 'Company created.');
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->companies->findOrFail($id);

        $data = $this->validate($request, [
            'name'     => 'required|max:200',
            'domain'   => 'nullable|max:190',
            'industry' => 'nullable|max:60',
            'phone'    => 'nullable|max:40',
            'website'  => 'nullable|max:255',
            'country'  => 'nullable|max:2',
            'city'     => 'nullable|max:120',
            'state'    => 'nullable|max:120',
            'postcode' => 'nullable|max:30',
            'notes'    => 'nullable|max:5000',
        ]);

        $this->companies->update($id, $data);
        $this->audit->log('company_updated', 'company', $id, null, $data);

        return $this->withSuccess('/companies/' . $id, 'Company updated.');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->companies->findOrFail($id);
        $this->companies->softDelete($id);
        $this->audit->log('company_deleted', 'company', $id);

        return $this->withSuccess('/companies', 'Company deleted.');
    }
}
