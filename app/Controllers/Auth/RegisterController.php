<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthManager;
use App\Services\OnboardingService;

final class RegisterController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly OnboardingService $onboarding,
        private readonly AuthManager $auth,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function show(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/dashboard');
        }

        return $this->render('auth.register', [
            'countries'  => $this->config->get('app.supported_countries', []),
            'timezones'  => $this->commonTimezones(),
            'currencies' => $this->config->get('app.supported_currencies', []),
            'industries' => $this->config->get('app.industries', []),
        ]);
    }

    public function store(Request $request): Response
    {
        $minLength = (int) $this->config->get('security.password.min_length', 12);

        $data = $this->validate($request, [
            'organisation_name' => 'required|max:200',
            'first_name'        => 'nullable|max:100',
            'last_name'         => 'nullable|max:100',
            'email'             => 'required|email|max:255',
            'password'          => 'required|min:' . $minLength . '|max:255|confirmed',
            'country'           => 'required|in:' . implode(',', array_keys((array) $this->config->get('app.supported_countries', []))),
            'timezone'          => 'required|timezone',
            'currency'          => 'required|in:' . implode(',', (array) $this->config->get('app.supported_currencies', [])),
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $this->onboarding->registerOrganisationWithOwner(
            (string) $data['organisation_name'],
            (string) $data['email'],
            (string) $data['password'],
            (string) $data['country'],
            (string) $data['timezone'],
            (string) $data['currency'],
            (string) ($data['first_name'] ?? ''),
            (string) ($data['last_name'] ?? ''),
        );

        return $this->withSuccess('/onboarding', 'Welcome. Let us get your account set up.');
    }

    /** @return array<int,string> */
    private function commonTimezones(): array
    {
        // The full IANA list is 400+ entries; these cover the launch markets, and
        // the field still validates against the complete list.
        return [
            'UTC',
            'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
            'America/Phoenix', 'America/Anchorage', 'Pacific/Honolulu',
            'Australia/Sydney', 'Australia/Melbourne', 'Australia/Brisbane',
            'Australia/Adelaide', 'Australia/Perth', 'Australia/Darwin', 'Australia/Hobart',
            'Pacific/Auckland', 'Europe/London', 'America/Toronto', 'America/Vancouver',
        ];
    }
}
