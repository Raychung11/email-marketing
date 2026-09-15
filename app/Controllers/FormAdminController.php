<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ListRepository;
use App\Repositories\TagRepository;
use App\Services\FormService;
use App\Support\TenantContext;

final class FormAdminController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly FormService $forms,
        private readonly ListRepository $lists,
        private readonly TagRepository $tags,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($view, $session, $config);
    }

    /** GET /forms */
    public function index(Request $request): Response
    {
        return $this->render('forms.index', [
            'forms'          => $this->forms->all(),
            'organisationId' => $this->tenant->organisationId(),
        ]);
    }

    /** GET /forms/{id} */
    public function show(Request $request): Response
    {
        $id = (int) $request->route('id');

        return $this->render('forms.show', [
            'form'           => $this->forms->find($id),
            'submissions'    => $this->forms->submissions($id, 25),
            'lists'          => $this->lists->all(),
            'tags'           => $this->tags->all(),
            'organisationId' => $this->tenant->organisationId(),
            'appUrl'         => rtrim((string) $this->config->get('app.url', ''), '/'),
        ]);
    }

    /** POST /forms */
    public function store(Request $request): Response
    {
        $data = $this->validate($request, ['name' => 'required|max:160']);

        $id = $this->forms->create($data + [
            'create_lead'               => $request->int('create_lead'),
            'consent_checkbox_required' => $request->int('consent_checkbox_required'),
        ]);

        return $this->withSuccess('/forms/' . $id, 'Form created. Check the wording, then publish it.');
    }

    /** POST /forms/{id} */
    public function update(Request $request): Response
    {
        $id = (int) $request->route('id');

        $this->forms->update($id, [
            'heading'                   => $request->string('heading'),
            'intro'                     => $request->string('intro'),
            'submit_label'              => $request->string('submit_label'),
            'success_message'           => $request->string('success_message'),
            'consent_text'              => $request->string('consent_text'),
            'consent_checkbox_required' => $request->int('consent_checkbox_required'),
            'create_lead'               => $request->int('create_lead'),
        ]);

        return $this->withSuccess('/forms/' . $id, 'Saved.');
    }

    /** POST /forms/{id}/publish */
    public function publish(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->forms->publish($id);

        return $this->withSuccess('/forms/' . $id, 'Published. The link below is live.');
    }
}
