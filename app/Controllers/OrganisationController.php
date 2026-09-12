<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\OrganisationService;

final class OrganisationController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly OrganisationService $organisations,
    ) {
        parent::__construct($view, $session, $config);
    }

    /**
     * Switch the active organisation.
     *
     * POST only, CSRF-protected, and the service re-verifies membership: the id in
     * the body is a request, not an authorisation.
     */
    public function switch(Request $request): Response
    {
        $data = $this->validate($request, ['organisation_id' => 'required|integer']);

        $this->organisations->switchTo((int) $data['organisation_id']);

        return $this->redirect('/dashboard');
    }

    public function create(Request $request): Response
    {
        return $this->render('onboarding.create_organisation', [
            'countries'  => $this->config->get('app.supported_countries', []),
            'currencies' => $this->config->get('app.supported_currencies', []),
            'industries' => $this->config->get('app.industries', []),
        ]);
    }
}
