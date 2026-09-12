<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

/**
 * CSRF protection for every state-changing request.
 *
 * Exemptions are explicit and narrow: inbound provider webhooks (which are
 * authenticated by signature instead) and the API (which is authenticated by a
 * bearer key, not a cookie, so it is not vulnerable to CSRF in the first place).
 */
final class VerifyCsrfToken implements Middleware
{
    private const EXEMPT_PREFIXES = [
        '/webhooks/',
        '/api/',
        '/track/',
    ];

    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($request->path(), $prefix)) {
                return $next($request);
            }
        }

        $token = $request->input('_token');
        $token = is_string($token) ? $token : null;
        $token ??= $request->header('X-CSRF-Token');

        if (!$this->csrf->verify($token)) {
            throw HttpException::tokenMismatch();
        }

        return $next($request);
    }
}
