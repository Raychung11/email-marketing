<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthManager;

/**
 * Route-level authorisation: `RequirePermission::class . ':contacts.edit'`.
 *
 * Checks a permission key, never a role name.
 */
final class RequirePermission implements Middleware
{
    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        if ($argument === null || $argument === '') {
            throw new \RuntimeException('RequirePermission middleware used without a permission key.');
        }

        // Several permissions may be given, comma-separated: any one grants.
        $permissions = array_map('trim', explode(',', $argument));

        if (!$this->auth->canAny($permissions)) {
            // authorise() throws with the specific permission in the message,
            // which is what the error screen shows.
            $this->auth->authorise($permissions[0]);
        }

        return $next($request);
    }
}
