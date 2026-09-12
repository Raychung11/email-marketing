<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\TagRepository;
use App\Services\AuditService;

final class TagController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly TagRepository $tags,
        private readonly AuditService $audit,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        return $this->render('crm.tags_index', ['tags' => $this->tags->all()]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'   => 'required|max:80',
            'colour' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        if ($this->tags->findBySlug(str_slug((string) $data['name'])) !== null) {
            return $this->withError('/tags', 'A tag with that name already exists.');
        }

        $id = $this->tags->create((string) $data['name'], [
            'colour'      => $data['colour'] ?? null,
            'description' => $request->string('description') ?: null,
        ]);

        $this->audit->log('tag_created', 'tag', $id, null, ['name' => $data['name']]);

        return $this->withSuccess('/tags', 'Tag created.');
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->tags->findOrFail($id);

        $data = $this->validate($request, [
            'name'   => 'required|max:80',
            'colour' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $this->tags->update($id, [
            'name'        => $data['name'],
            'colour'      => $data['colour'] ?? null,
            'description' => $request->string('description') ?: null,
        ]);

        $this->audit->log('tag_updated', 'tag', $id, null, $data);

        return $this->withSuccess('/tags', 'Tag updated.');
    }

    public function destroy(Request $request): Response
    {
        $id  = (int) $request->route('id');
        $tag = $this->tags->findOrFail($id);

        $this->tags->delete($id);
        $this->audit->log('tag_deleted', 'tag', $id, ['name' => $tag['name']]);

        return $this->withSuccess('/tags', 'Tag deleted.');
    }
}
