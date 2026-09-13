<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Automation\AutomationService;
use App\Automation\JourneyTemplates;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ListRepository;
use App\Repositories\TagRepository;
use App\Repositories\TemplateRepository;

final class AutomationController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly AutomationService $automations,
        private readonly TagRepository $tags,
        private readonly ListRepository $lists,
        private readonly TemplateRepository $templates,
    ) {
        parent::__construct($view, $session, $config);
    }

    /** GET /automations */
    public function index(Request $request): Response
    {
        return $this->render('automation.index', [
            'automations' => $this->automations->all(),
            'triggers'    => $this->config->get('automation.triggers', []),
            'templates'   => JourneyTemplates::all(),
        ]);
    }

    /** GET /automations/{id} */
    public function show(Request $request): Response
    {
        $id         = (int) $request->route('id');
        $automation = $this->automations->find($id);

        return $this->render('automation.show', [
            'automation'   => $automation,
            'problems'     => $this->automations->problems($id),
            'runs'         => $this->automations->runsFor($id, 25),
            'triggers'     => $this->config->get('automation.triggers', []),
            'actions'      => $this->config->get('automation.actions', []),
            'tags'         => $this->tags->all(),
            'lists'        => $this->lists->all(),
            'emails'       => $this->templates->all(),
        ]);
    }

    /** POST /automations */
    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'         => 'required|max:200',
            'trigger_type' => 'required',
            'description'  => 'nullable|max:255',
        ]);

        $id = $this->automations->create($data + ['trigger_config' => $request->input('trigger_config', [])]);

        return $this->withSuccess('/automations/' . $id, 'Journey created. Add the steps, then switch it on.');
    }

    /** POST /automations/{id}/steps */
    public function addStep(Request $request): Response
    {
        $id = (int) $request->route('id');

        $nodeId = $this->automations->addNode($id, [
            'node_type'    => $request->string('node_type'),
            'action_type'  => $request->string('action_type') ?: null,
            'wait_minutes' => $request->int('wait_minutes'),
            'config'       => (array) $request->input('config', []),
        ]);

        // New steps are appended to the end of the journey, which is what
        // somebody building one from the top down expects.
        $last = $this->lastNodeId($id, $nodeId);

        if ($last !== null) {
            $this->automations->connect($id, $last, $nodeId);
        }

        return $this->withSuccess('/automations/' . $id, 'Step added.');
    }

    /** POST /automations/{id}/activate */
    public function activate(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->automations->activate($id);

        return $this->withSuccess('/automations/' . $id, 'This journey is live. It will start picking people up.');
    }

    /** POST /automations/{id}/pause */
    public function pause(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->automations->pause($id);

        return $this->withSuccess(
            '/automations/' . $id,
            'Paused. Anybody part-way through stays where they are until you switch it back on.'
        );
    }

    /** POST /automations/{id}/delete */
    public function destroy(Request $request): Response
    {
        $this->automations->delete((int) $request->route('id'));

        return $this->withSuccess('/automations', 'Journey deleted.');
    }

    /** GET /automations/runs/{id} */
    public function runLog(Request $request): Response
    {
        return Response::json(['steps' => $this->automations->runLog((int) $request->route('id'))]);
    }

    /**
     * The node the new one should hang off: the last in the chain, ignoring the
     * one just created.
     */
    private function lastNodeId(int $automationId, int $exclude): ?int
    {
        $automation = $this->automations->find($automationId);

        $hasOutgoing = [];

        foreach ($automation['connections'] as $edge) {
            $hasOutgoing[(int) $edge['from_node_id']] = true;
        }

        foreach (array_reverse($automation['nodes']) as $node) {
            $nodeId = (int) $node['id'];

            if ($nodeId !== $exclude && !isset($hasOutgoing[$nodeId])) {
                return $nodeId;
            }
        }

        return null;
    }
}
