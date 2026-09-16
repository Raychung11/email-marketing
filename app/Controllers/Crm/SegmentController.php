<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ListRepository;
use App\Repositories\TagRepository;
use App\Services\AuthManager;
use App\Services\SegmentService;

final class SegmentController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly SegmentService $segments,
        private readonly \App\Services\AiSegmentService $aiSegments,
        private readonly TagRepository $tags,
        private readonly ListRepository $lists,
        private readonly AuthManager $auth,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        $segments = $this->segments->all();

        foreach ($segments as $index => $segment) {
            $segments[$index]['summary'] = $this->segments->describe($segment['definition']);
            $segments[$index]['stale']   = $this->segments->countsAreStale($segment);
        }

        return $this->render('crm.segments_index', ['segments' => $segments]);
    }

    public function show(Request $request): Response
    {
        $id      = (int) $request->route('id');
        $segment = $this->segments->find($id);

        if ($segment === null) {
            throw \App\Core\HttpException::notFound();
        }

        return $this->render('crm.segment_show', [
            'segment' => $segment,
            'summary' => $this->segments->describe($segment['definition']),
            'preview' => $this->segments->preview($segment['definition'], 15),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('crm.segment_form', [
            'segment'   => null,
            'fields'    => $this->segments->availableFields(),
            'operators' => $this->config->get('segments.operators_by_type', []),
            'tags'      => $this->tags->all(),
            'lists'     => $this->lists->all(),
            'countries' => $this->config->get('app.supported_countries', []),
            'statuses'  => $this->config->get('crm.customer_statuses', []),
            'stages'    => $this->config->get('crm.lifecycle_stages', []),
            'aiAvailable' => $this->aiSegments->isAvailable(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'        => 'required|max:160',
            'description' => 'nullable|max:255',
        ]);

        // The definition arrives as JSON from the builder. It is validated against
        // the field registry before it goes anywhere near SQL.
        $definition = $this->definitionFrom($request);

        $id = $this->segments->create((string) $data['name'], $definition, [
            'description'        => $data['description'] ?? null,
            'created_by_user_id' => $this->auth->id(),
            'created_via'        => 'manual',
        ]);

        return $this->withSuccess('/segments/' . $id, 'Segment created.');
    }

    public function edit(Request $request): Response
    {
        $segment = $this->segments->find((int) $request->route('id'));

        if ($segment === null) {
            throw \App\Core\HttpException::notFound();
        }

        return $this->render('crm.segment_form', [
            'segment'   => $segment,
            'fields'    => $this->segments->availableFields(),
            'operators' => $this->config->get('segments.operators_by_type', []),
            'tags'      => $this->tags->all(),
            'lists'     => $this->lists->all(),
            'countries' => $this->config->get('app.supported_countries', []),
            'statuses'  => $this->config->get('crm.customer_statuses', []),
            'stages'    => $this->config->get('crm.lifecycle_stages', []),
            'aiAvailable' => $this->aiSegments->isAvailable(),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->route('id');

        if ($this->segments->find($id) === null) {
            throw \App\Core\HttpException::notFound();
        }

        $data = $this->validate($request, [
            'name'        => 'required|max:160',
            'description' => 'nullable|max:255',
        ]);

        $this->segments->update($id, [
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ], $this->definitionFrom($request));

        return $this->withSuccess('/segments/' . $id, 'Segment updated.');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->route('id');

        if ($this->segments->find($id) === null) {
            throw \App\Core\HttpException::notFound();
        }

        $this->segments->delete($id);

        return $this->withSuccess('/segments', 'Segment deleted.');
    }

    /**
     * Live audience preview for the builder (AJAX).
     *
     * Returns the eligibility breakdown rather than one total, because "1,482
     * contacts" without "155 of them cannot be emailed" is a misleading number.
     */
    public function preview(Request $request): Response
    {
        $definition = $this->definitionFrom($request);
        $preview    = $this->segments->preview($definition, 5);

        return Response::json([
            'summary'    => $this->segments->describe($definition),
            'total'      => $preview['total'],
            'eligible'   => $preview['eligible'],
            'suppressed' => $preview['suppressed'],
            'no_consent' => $preview['no_consent'],
            'invalid'    => $preview['invalid'],
            'blocked'    => $preview['blocked'],
            'sample'     => array_map(static fn (array $c): array => [
                'email'      => $c['email'],
                'first_name' => $c['first_name'],
                'last_name'  => $c['last_name'],
                'country'    => $c['country'],
            ], $preview['sample']),
        ]);
    }

    public function refreshCounts(Request $request): Response
    {
        $id = (int) $request->route('id');

        if ($this->segments->find($id) === null) {
            throw \App\Core\HttpException::notFound();
        }

        return Response::json($this->segments->refreshCounts($id));
    }

    /** @return array<string,mixed> */
    private function definitionFrom(Request $request): array
    {
        $raw = $request->input('definition');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($raw)) {
            throw new \App\Core\ValidationException([
                'definition' => ['Add at least one condition to the segment.'],
            ]);
        }

        return $raw;
    }
}
