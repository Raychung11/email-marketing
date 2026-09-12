<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganisationRepository;
use App\Services\AuthManager;
use App\Support\TenantContext;

/**
 * The tenancy boundary.
 *
 * The active organisation comes from the session and is re-verified against
 * organisation_users on every request. It is NEVER read from the URL, the query
 * string or the request body — which is why a tampered id cannot reach a
 * repository, and why repositories do not accept one.
 */
final class BindTenant implements Middleware
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly MembershipRepository $memberships,
        private readonly OrganisationRepository $organisations,
        private readonly TenantContext $tenant,
        private readonly View $view,
    ) {
    }

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return Response::redirect('/login');
        }

        $organisationId = $this->auth->organisationIdFromSession();

        // No organisation in session (first login, or the previous one was
        // removed): fall back to the user's default membership.
        if ($organisationId === null) {
            $membership = $this->memberships->defaultMembership($userId);

            if ($membership === null) {
                return Response::redirect('/onboarding/organisation');
            }

            $organisationId = (int) $membership['organisation_id'];
            $this->auth->setActiveOrganisation($organisationId);
        }

        $membership = $this->memberships->activeMembership($userId, $organisationId);

        if ($membership === null) {
            // Membership revoked mid-session, or a tampered session value.
            $fallback = $this->memberships->defaultMembership($userId);

            if ($fallback === null) {
                $this->auth->logout();

                return Response::redirect('/login');
            }

            $organisationId = (int) $fallback['organisation_id'];
            $this->auth->setActiveOrganisation($organisationId);
        }

        $organisation = $this->organisations->findById($organisationId);

        if ($organisation === null) {
            $this->auth->logout();

            return Response::redirect('/login');
        }

        $this->tenant->bind($organisationId, null, $organisation);
        $this->memberships->touchActivity($userId, $organisationId);

        $this->view->shareMany([
            'organisation'  => $organisation,
            'organisations' => $this->organisations->forUser($userId),
            'tenant'        => $this->tenant,
            'roleKey'       => $this->auth->roleKey(),
        ]);

        return $next($request);
    }
}
