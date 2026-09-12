<?php

declare(strict_types=1);

namespace App\Controllers\Ai;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Mail\TemplateRenderer;
use App\Services\AiCampaignService;
use App\Services\AiSegmentService;
use App\Services\TemplateService;

/**
 * The AI Campaign Studio.
 *
 * Three steps and no fourth: describe it, read what came back, keep it as a
 * draft. There is no "generate and send", and there never will be — the draft
 * lands in the ordinary campaign workflow and needs the same review and
 * approval as one somebody typed.
 */
final class StudioController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly AiCampaignService $studio,
        private readonly AiSegmentService $aiSegments,
        private readonly TemplateService $templates,
        private readonly TemplateRenderer $renderer,
    ) {
        parent::__construct($view, $session, $config);
    }

    /** GET /ai/studio */
    public function index(Request $request): Response
    {
        return $this->render('ai.studio', [
            'context' => $this->studio->studioContext(),
            'brief'   => [],
            'draft'   => null,
            'preview' => null,
        ]);
    }

    /** POST /ai/studio — write me a draft. Saves nothing. */
    public function draft(Request $request): Response
    {
        $brief = $this->validate($request, [
            'goal'       => 'required',
            'tone'       => 'nullable',
            'segment_id' => 'nullable|integer',
            'offer'      => 'nullable|max:300',
            'link'       => 'nullable|max:255',
            'notes'      => 'nullable|max:1000',
        ]);

        $draft = $this->studio->draft($brief);

        return $this->render('ai.studio', [
            'context' => $this->studio->studioContext(),
            'brief'   => $brief,
            'draft'   => $draft,
            // Rendered through the real email renderer, so what is on screen is
            // what would actually be sent.
            'preview' => $this->renderer->renderHtml($this->templates->validateBlocks($draft['blocks'])),
        ]);
    }

    /** POST /ai/studio/keep — turn the draft into a campaign. */
    public function keep(Request $request): Response
    {
        $draft = json_decode((string) $request->input('draft', ''), true);
        $brief = json_decode((string) $request->input('brief', ''), true);

        if (!is_array($draft) || !is_array($brief)) {
            return $this->withError('/ai/studio', 'That draft has expired. Generate a new one.');
        }

        // Whatever arrives here is re-sanitised inside the service: the round trip
        // through a form field is exactly as untrusted as the model's first reply.
        $chosen = (string) $request->input('subject', '');

        if ($chosen !== '') {
            array_unshift($draft['subject_options'], $chosen);
        }

        $campaignId = $this->studio->createCampaign($draft, $brief);

        return $this->withSuccess(
            '/campaigns/' . $campaignId,
            'Saved as a draft. Read it over, then send it for checking when you are happy.'
        );
    }

    /** POST /segments/ai — describe a group of people, get rules back. */
    public function suggestSegment(Request $request): Response
    {
        $data = $this->validate($request, ['description' => 'required|max:1000']);

        return Response::json($this->aiSegments->suggest((string) $data['description']));
    }

    /** POST /campaigns/{id}/ai/subjects — alternative subject lines. */
    public function subjects(Request $request): Response
    {
        $id = (int) $request->route('id');

        return Response::json([
            'subjects' => $this->studio->subjectLines($id, $request->int('count') ?: 5),
        ]);
    }
}
