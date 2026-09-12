<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

/**
 * Baseline response hardening, plus TLS enforcement when configured.
 */
final class SecurityHeaders implements Middleware
{
    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        if ((bool) $this->config->get('app.force_https', false) && !$request->isSecure()) {
            return Response::redirect('https://' . $request->host() . $request->uri(), 301);
        }

        $response = $next($request);

        /** @var array<string,string> $headers */
        $headers = $this->config->get('security.headers', []);

        foreach ($headers as $name => $value) {
            $response->withHeader($name, $value);
        }

        // A strict CSP is possible because the application ships no inline
        // scripts: everything lives in public/assets/js.
        $response->withHeader(
            'Content-Security-Policy',
            "default-src 'self'; "
            . "script-src 'self' https://cdn.jsdelivr.net; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
            . "img-src 'self' data: https:; "
            . "font-src 'self' https://cdn.jsdelivr.net data:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'self'; "
            . "form-action 'self'; "
            . "base-uri 'self'"
        );

        return $response;
    }
}
