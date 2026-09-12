<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\OrganisationRepository;
use App\Services\ApiKeyService;
use App\Services\AuditService;
use App\Support\TenantContext;

/**
 * Bearer-token authentication for /api/v1.
 *
 * The tenant is derived from the key, exactly as it is derived from the session
 * in the web app: an organisation_id in a request body or query string is never
 * consulted. Scopes are checked per route via the middleware argument.
 */
final class AuthenticateApiKey implements Middleware
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly OrganisationRepository $organisations,
        private readonly TenantContext $tenant,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly AuditService $audit,
    ) {
    }

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        $presented = $this->extractKey($request);

        if ($presented === null) {
            throw HttpException::unauthorized('An API key is required. Send it as: Authorization: Bearer <key>');
        }

        $key = $this->keys->resolve($presented);

        if ($key === null) {
            throw HttpException::unauthorized('That API key is not valid.');
        }

        if (!$this->keys->ipAllowed($key, $request->ip())) {
            throw HttpException::forbidden('This API key is not permitted from this IP address.');
        }

        // Rate limit per key, not per IP: one customer's integration must not be
        // able to exhaust another's allowance.
        $limit  = (int) ($key['rate_limit_per_minute'] ?? $this->config->get('security.api.rate_limit', 120));
        $window = (int) $this->config->get('security.api.rate_window', 60);
        $bucket = 'api:' . (int) $key['id'];

        if ($this->limiter->tooManyAttempts($bucket, $limit)) {
            throw HttpException::tooManyRequests(
                'Rate limit exceeded (' . $limit . ' requests per minute). Retry in '
                . $this->limiter->availableIn($bucket) . ' seconds.'
            );
        }

        $this->limiter->hit($bucket, $window);

        if ($argument !== null && $argument !== '' && !$this->keys->hasScope($key, $argument)) {
            throw HttpException::forbidden('This API key does not have the "' . $argument . '" scope.');
        }

        $organisation = $this->organisations->findById((int) $key['organisation_id']);

        if ($organisation === null) {
            throw HttpException::unauthorized('That API key is not valid.');
        }

        if ((string) $organisation['status'] === 'suspended') {
            throw HttpException::forbidden('This organisation is suspended.');
        }

        $this->tenant->bind((int) $organisation['id'], null, $organisation);

        $this->keys->recordUse((int) $key['id'], $request->ip());
        $this->audit->setActor(null, 'api');
        $this->audit->setRequestContext($request->ip(), $request->userAgent());

        $response = $next($request);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $limit - $this->limiter->attempts($bucket)));
    }

    private function extractKey(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (is_string($header) && preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) === 1) {
            return $matches[1];
        }

        $alternative = $request->header('X-Api-Key');

        return is_string($alternative) && $alternative !== '' ? $alternative : null;
    }
}
