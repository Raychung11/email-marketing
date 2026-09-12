<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\SendingDomainRepository;
use App\Services\AnalyticsService;
use App\Services\DashboardService;
use App\Support\TenantContext;

final class AnalyticsController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly AnalyticsService $analytics,
        private readonly DashboardService $dashboard,
        private readonly SendingDomainRepository $domains,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($view, $session, $config);
    }

    /** GET /analytics */
    public function index(Request $request): Response
    {
        return $this->render('analytics.overview', [
            'metrics'    => $this->dashboard->overview(),
            'highlights' => $this->analytics->highlights($this->window($request, 'campaigns')),
            'caveat'     => (string) $this->config->get('analytics.open_rate_caveat', ''),
        ]);
    }

    /** GET /analytics/campaigns */
    public function campaigns(Request $request): Response
    {
        $days = $this->window($request, 'campaigns');

        return $this->render('analytics.campaigns', [
            'campaigns'  => $this->analytics->campaignPerformance($days),
            'highlights' => $this->analytics->highlights($days),
            'days'       => $days,
            'caveat'     => (string) $this->config->get('analytics.open_rate_caveat', ''),
            'currency'   => $this->tenant->currency(),
        ]);
    }

    /** GET /analytics/deliverability */
    public function deliverability(Request $request): Response
    {
        $days = $this->window($request, 'inbox');

        return $this->render('analytics.deliverability', [
            'report'  => $this->analytics->inboxDelivery($days),
            'days'    => $days,
            'domains' => $this->domains->all(),
        ]);
    }

    /**
     * The reporting window, clamped.
     *
     * A query parameter that reached the date arithmetic unchecked would let
     * anybody ask for a million-day report and tie up the database.
     */
    private function window(Request $request, string $key): int
    {
        $default = (int) $this->config->get('analytics.windows.' . $key, 30);
        $days    = $request->int('days');

        return in_array($days, [7, 30, 90, 180, 365], true) ? $days : $default;
    }
}
