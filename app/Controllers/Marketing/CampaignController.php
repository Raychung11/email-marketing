<?php

declare(strict_types=1);

namespace App\Controllers\Marketing;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Mail\EmailRenderer;
use App\Repositories\ListRepository;
use App\Repositories\SegmentRepository;
use App\Repositories\TemplateRepository;
use App\Services\AiInsightService;
use App\Services\AnalyticsService;
use App\Services\AuthManager;
use App\Services\CampaignService;
use App\Support\TenantContext;

final class CampaignController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly CampaignService $campaigns,
        private readonly SegmentRepository $segments,
        private readonly ListRepository $lists,
        private readonly TemplateRepository $templates,
        private readonly EmailRenderer $renderer,
        private readonly AuthManager $auth,
        private readonly AnalyticsService $analytics,
        private readonly AiInsightService $insights,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => $request->string('status'),
            'type'   => $request->string('type'),
            'search' => $request->string('search'),
        ];

        return $this->render('marketing.campaigns_index', [
            'result'   => $this->campaigns->paginate($filters, $this->page($request), $this->perPage($request)),
            'filters'  => $filters,
            'counts'   => $this->campaigns->statusCounts(),
            'statuses' => $this->statuses(),
            'types'    => $this->types(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->render('marketing.campaign_form', [
            'campaign'  => null,
            'segments'  => $this->segments->all(),
            'lists'     => $this->lists->all(),
            'templates' => $this->templates->all(),
            'types'     => $this->types(),
            'goals'     => $this->config->get('ai.campaign_goals', []),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'          => 'required|max:200',
            'subject'       => 'nullable|max:255',
            'preview_text'  => 'nullable|max:255',
            'campaign_type' => 'required|in:' . implode(',', array_keys($this->types())),
            'segment_id'    => 'nullable|integer',
            'list_id'       => 'nullable|integer',
            'template_id'   => 'nullable|integer',
        ]);

        $id = $this->campaigns->create($data);

        if ((int) ($data['template_id'] ?? 0) > 0) {
            $this->campaigns->applyTemplate($id, (int) $data['template_id']);
        }

        return $this->withSuccess('/campaigns/' . $id, 'Campaign created.');
    }

    public function show(Request $request): Response
    {
        $id       = (int) $request->route('id');
        $campaign = $this->campaigns->find($id);

        // Once a campaign has a recipient snapshot, that is the authoritative
        // answer to "who did this go to" — re-running the segment would report the
        // audience as it is today, which for a campaign sent last week is a
        // different set of people.
        $delivery = $this->campaigns->deliveryReport($id);

        return $this->render('marketing.campaign_show', [
            'campaign' => $campaign,
            'audience' => $delivery ?? $this->campaigns->audience($id),
            'delivery' => $delivery,
            // Only worth querying once something has actually been sent.
            'report'   => $delivery === null ? null : $this->analytics->campaignReport($id),
            'review'   => $delivery === null ? null : $this->insights->storedReview($id),
            'aiAvailable' => $this->insights->isAvailable(),
            'findings' => $campaign['validation_findings'],
            'statuses' => $this->statuses(),
            'types'    => $this->types(),
            'canApprove' => $this->auth->can('campaigns.approve')
                && (int) ($campaign['created_by_user_id'] ?? 0) !== (int) $this->auth->id(),
            'isAuthor'   => (int) ($campaign['created_by_user_id'] ?? 0) === (int) $this->auth->id(),
            'timezone'   => $this->tenant->timezone(),
        ]);
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->route('id');

        return $this->render('marketing.campaign_form', [
            'campaign'  => $this->campaigns->find($id),
            'segments'  => $this->segments->all(),
            'lists'     => $this->lists->all(),
            'templates' => $this->templates->all(),
            'types'     => $this->types(),
            'goals'     => $this->config->get('ai.campaign_goals', []),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->route('id');

        $data = $this->validate($request, [
            'name'          => 'required|max:200',
            'subject'       => 'nullable|max:255',
            'preview_text'  => 'nullable|max:255',
            'from_name'     => 'nullable|max:120',
            'from_email'    => 'nullable|email|max:255',
            'reply_to'      => 'nullable|email|max:255',
            'campaign_type' => 'required|in:' . implode(',', array_keys($this->types())),
            'segment_id'    => 'nullable|integer',
            'list_id'       => 'nullable|integer',
            'utm_source'    => 'nullable|max:80',
            'utm_medium'    => 'nullable|max:80',
            'utm_campaign'  => 'nullable|max:120',
        ]);

        $this->campaigns->update($id, $data);

        if ((int) $request->int('template_id') > 0) {
            $this->campaigns->applyTemplate($id, $request->int('template_id'));
        }

        return $this->withSuccess('/campaigns/' . $id, 'Campaign saved.');
    }

    /** The rendered body, in a sandboxed frame. */
    public function preview(Request $request): Response
    {
        $campaign     = $this->campaigns->find((int) $request->route('id'));
        $organisation = $this->tenant->organisation();

        $sample = [
            'uuid'       => '00000000-0000-4000-8000-000000000000',
            'first_name' => 'Alex',
            'last_name'  => 'Taylor',
            'email'      => 'alex.taylor@example.com',
            'company'    => 'Example Pty Ltd',
            'city'       => (string) ($organisation['address_city'] ?? ''),
            'country'    => (string) ($organisation['country'] ?? ''),
        ];

        $variables = $this->renderer->variablesFor($organisation, $sample, '#preview', '#preview');

        $html = $this->renderer->substitute((string) $campaign['html_content'], $variables, true);

        return Response::html($html)
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'")
            ->withHeader('Cache-Control', 'no-store');
    }

    public function validateCampaign(Request $request): Response
    {
        $id     = (int) $request->route('id');
        $result = $this->campaigns->validate($id);

        return $result['valid']
            ? $this->withSuccess('/campaigns/' . $id, 'Validation passed. This campaign is ready to be reviewed.')
            : $this->withError('/campaigns/' . $id, 'Validation found ' . count($result['blocking']) . ' problem(s).');
    }

    public function submit(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->campaigns->submitForReview($id);

        return $this->withSuccess('/campaigns/' . $id, 'Sent for review.');
    }

    public function approve(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->campaigns->approve($id);

        return $this->withSuccess('/campaigns/' . $id, 'Approved. It can now be scheduled or sent.');
    }

    public function requestChanges(Request $request): Response
    {
        $id   = (int) $request->route('id');
        $data = $this->validate($request, ['reason' => 'required|max:1000']);

        $this->campaigns->requestChanges($id, (string) $data['reason']);

        return $this->withSuccess('/campaigns/' . $id, 'Returned to the author with your notes.');
    }

    public function schedule(Request $request): Response
    {
        $id   = (int) $request->route('id');
        $data = $this->validate($request, ['scheduled_at' => 'required|date']);

        $this->campaigns->schedule($id, (string) $data['scheduled_at']);

        return $this->withSuccess('/campaigns/' . $id, 'Scheduled.');
    }

    public function sendNow(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->campaigns->sendNow($id);

        return $this->withSuccess(
            '/campaigns/' . $id,
            'Queued. Sending starts within a minute — bulk email is never sent from a web request.'
        );
    }

    public function unschedule(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->campaigns->unschedule($id);

        return $this->withSuccess('/campaigns/' . $id, 'Unscheduled.');
    }

    public function pause(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->campaigns->pause($id, $request->string('reason', 'Paused by a user'));

        return $this->withSuccess('/campaigns/' . $id, 'Paused. Messages already with the provider cannot be recalled.');
    }

    public function resume(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->campaigns->resume($id);

        return $this->withSuccess('/campaigns/' . $id, 'Resumed.');
    }

    public function cancel(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->campaigns->cancel($id, $request->string('reason', 'Cancelled by a user'));

        return $this->withSuccess('/campaigns/' . $id, 'Cancelled.');
    }

    public function duplicate(Request $request): Response
    {
        $id = $this->campaigns->duplicate((int) $request->route('id'));

        return $this->withSuccess('/campaigns/' . $id . '/edit', 'Campaign duplicated as a new draft.');
    }

    public function destroy(Request $request): Response
    {
        $this->campaigns->delete((int) $request->route('id'));

        return $this->withSuccess('/campaigns', 'Campaign deleted.');
    }

    /** @return array<string,string> */
    private function statuses(): array
    {
        return [
            'draft'          => 'Draft',
            'pending_review' => 'Awaiting review',
            'approved'       => 'Approved',
            'scheduled'      => 'Scheduled',
            'sending'        => 'Sending',
            'paused'         => 'Paused',
            'completed'      => 'Completed',
            'cancelled'      => 'Cancelled',
            'failed'         => 'Failed',
        ];
    }

    /** @return array<string,string> */
    private function types(): array
    {
        return [
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
