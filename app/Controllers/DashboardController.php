<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\ActivityService;
use App\Services\DashboardService;
use App\Services\OnboardingService;

final class DashboardController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly DashboardService $dashboard,
        private readonly OnboardingService $onboarding,
        private readonly ActivityService $activity,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        return $this->render('dashboard.index', [
            'metrics'         => $this->dashboard->overview(),
            'recommendations' => $this->dashboard->recommendations(),
            'progress'        => $this->onboarding->progress(),
            'activity'        => $this->activity->recent(12),
        ]);
    }

    public function analytics(Request $request): Response
    {
        return $this->render('dashboard.analytics', [
            'metrics' => $this->dashboard->overview(),
        ]);
    }
}
