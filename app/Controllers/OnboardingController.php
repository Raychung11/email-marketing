<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\OnboardingService;
use App\Support\TenantContext;

final class OnboardingController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly OnboardingService $onboarding,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        return $this->render('onboarding.wizard', [
            'progress'     => $this->onboarding->progress(),
            'steps'        => OnboardingService::STEPS,
            'checklist'    => $this->onboarding->checklist(),
            'organisation' => $this->tenant->organisation(),
            'countries'    => $this->config->get('app.supported_countries', []),
            'currencies'   => $this->config->get('app.supported_currencies', []),
            'industries'   => $this->config->get('app.industries', []),
            'timezones'    => timezone_identifiers_list(),
        ]);
    }

    public function completeStep(Request $request): Response
    {
        $step = max(1, min((int) $request->route('step'), count(OnboardingService::STEPS)));

        $this->onboarding->completeStep($step, $request->all());

        if ($step >= count(OnboardingService::STEPS)) {
            return $this->withSuccess('/dashboard', 'Setup complete. Welcome aboard.');
        }

        return $this->redirect('/onboarding');
    }

    public function skip(Request $request): Response
    {
        $this->onboarding->skip();

        return $this->withSuccess(
            '/dashboard',
            'Setup skipped. You can finish it any time from Settings — note that a verified sending domain and a '
            . 'postal address are required before marketing email can be sent.'
        );
    }
}
