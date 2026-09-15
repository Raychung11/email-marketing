<?php

declare(strict_types=1);

namespace App\Controllers\Marketing;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\AuthManager;
use App\Services\TemplateService;

final class TemplateController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly TemplateService $templates,
        private readonly AuthManager $auth,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        return $this->render('marketing.templates_index', [
            'templates'  => $this->templates->all($request->string('category') ?: null),
            'category'   => $request->string('category'),
            'categories' => $this->categories(),
        ]);
    }

    public function create(Request $request): Response
    {
        $category = $request->string('category', 'blank');

        return $this->render('marketing.template_edit', [
            'template'   => null,
            'blocks'     => $this->templates->starterBlocks($category),
            'registry'   => $this->templates->registry(),
            'categories' => $this->categories(),
            'category'   => $category,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'        => 'required|max:160',
            'description' => 'nullable|max:255',
            'category'    => 'nullable|in:' . implode(',', array_keys($this->categories())),
        ]);

        $id = $this->templates->create(
            (string) $data['name'],
            $this->blocksFrom($request),
            [
                'description'        => $data['description'] ?? null,
                'category'           => $data['category'] ?? 'blank',
                'created_by_user_id' => $this->auth->id(),
            ]
        );

        return $this->withSuccess('/templates/' . $id . '/edit', 'Template saved.');
    }

    public function edit(Request $request): Response
    {
        $template = $this->templates->find((int) $request->route('id'));

        return $this->render('marketing.template_edit', [
            'template'   => $template,
            'blocks'     => $template['blocks'],
            'registry'   => $this->templates->registry(),
            'categories' => $this->categories(),
            'category'   => (string) $template['category'],
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->route('id');

        $data = $this->validate($request, [
            'name'        => 'required|max:160',
            'description' => 'nullable|max:255',
            'category'    => 'nullable|in:' . implode(',', array_keys($this->categories())),
        ]);

        $this->templates->update($id, [
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'category'    => $data['category'] ?? 'blank',
        ], $this->blocksFrom($request));

        return $this->withSuccess('/templates/' . $id . '/edit', 'Template saved.');
    }

    /**
     * Live preview for the editor (AJAX).
     *
     * Returns rendered HTML for an iframe. The editor never inserts it into the
     * page directly — an email body is full of markup the app has no business
     * executing in its own origin.
     */
    public function preview(Request $request): Response
    {
        try {
            $html = $this->templates->preview($this->blocksFrom($request));
        } catch (ValidationException $e) {
            return Response::json(['error' => ['message' => implode(' ', $e->firstErrors())]], 422);
        }

        return Response::html($html)
            // The preview is sandboxed: no scripts, no framing by anyone else.
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'")
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function duplicate(Request $request): Response
    {
        $id = $this->templates->duplicate((int) $request->route('id'));

        return $this->withSuccess('/templates/' . $id . '/edit', 'Template duplicated.');
    }

    public function sendTest(Request $request): Response
    {
        $id   = (int) $request->route('id');
        $data = $this->validate($request, ['recipient' => 'required|email|max:255']);

        $sent = $this->templates->sendTest($id, (string) $data['recipient']);

        return $sent
            ? $this->withSuccess('/templates/' . $id . '/edit', 'Test sent to ' . $data['recipient'] . '.')
            : $this->withError('/templates/' . $id . '/edit', 'The provider refused the test message.');
    }

    public function destroy(Request $request): Response
    {
        $this->templates->delete((int) $request->route('id'));

        return $this->withSuccess('/templates', 'Template deleted.');
    }

    /** @return array<int,array<string,mixed>> */
    private function blocksFrom(Request $request): array
    {
        $raw = $request->input('blocks');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($raw)) {
            throw new ValidationException(['blocks' => ['Add at least one block to the template.']]);
        }

        return $raw;
    }

    /** @return array<string,string> */
    private function categories(): array
    {
        return [
            'blank'             => 'Blank',
            'newsletter'        => 'Newsletter',
            'promotion'         => 'Promotion',
            'reactivation'      => 'Reactivation',
            'announcement'      => 'Announcement',
            'event'             => 'Event',
            'lead_followup'     => 'Lead follow-up',
            'customer_followup' => 'Customer follow-up',
            'review_request'    => 'Review request',
            'seasonal'          => 'Seasonal',
            'product'           => 'Product',
            'service'           => 'Service',
        ];
    }
}
