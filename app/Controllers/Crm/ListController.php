<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ContactRepository;
use App\Repositories\ListRepository;
use App\Services\AuditService;
use App\Services\AuthManager;

final class ListController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly ListRepository $lists,
        private readonly ContactRepository $contacts,
        private readonly AuthManager $auth,
        private readonly AuditService $audit,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        return $this->render('crm.lists_index', ['lists' => $this->lists->all()]);
    }

    public function show(Request $request): Response
    {
        $id   = (int) $request->route('id');
        $list = $this->lists->findOrFail($id);

        $query = $this->contacts->filtered(['list_id' => $id]);
        $total = (clone $query)->count();

        return $this->render('crm.list_show', [
            'list'    => $list,
            'total'   => $total,
            'contacts' => $query->orderBy('created_at', 'desc')
                ->forPage($this->page($request), $this->perPage($request))
                ->get(),
            'page'    => $this->page($request),
            'perPage' => $this->perPage($request),
            'pages'   => (int) ceil($total / max(1, $this->perPage($request))),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'        => 'required|max:160',
            'description' => 'nullable|max:255',
        ]);

        $id = $this->lists->create((string) $data['name'], [
            'description'        => $data['description'] ?? null,
            'created_by_user_id' => $this->auth->id(),
        ]);

        $this->audit->log('list_created', 'list', $id, null, ['name' => $data['name']]);

        return $this->withSuccess('/lists/' . $id, 'List created.');
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->lists->findOrFail($id);

        $data = $this->validate($request, [
            'name'        => 'required|max:160',
            'description' => 'nullable|max:255',
        ]);

        $this->lists->update($id, $data);
        $this->audit->log('list_updated', 'list', $id, null, $data);

        return $this->withSuccess('/lists/' . $id, 'List updated.');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->lists->findOrFail($id);

        $this->lists->softDelete($id);
        $this->audit->log('list_deleted', 'list', $id);

        return $this->withSuccess('/lists', 'List deleted.');
    }

    public function addContact(Request $request): Response
    {
        $listId    = (int) $request->route('id');
        $contactId = $request->int('contact_id');

        $this->lists->findOrFail($listId);
        $this->contacts->findOrFail($contactId);

        $this->lists->addContact($listId, $contactId);

        return $this->back($request, '/lists/' . $listId);
    }

    public function removeContact(Request $request): Response
    {
        $listId    = (int) $request->route('id');
        $contactId = (int) $request->route('contactId');

        $this->lists->findOrFail($listId);
        $this->lists->removeContact($listId, $contactId);

        return $this->back($request, '/lists/' . $listId);
    }
}
