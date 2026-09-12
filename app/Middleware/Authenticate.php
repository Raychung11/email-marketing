<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthManager;

final class Authenticate implements Middleware
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Session $session,
    ) {
    }

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            throw HttpException::unauthorized();
        }

        // Remember where they were going, but only a local path — never an
        // absolute URL, which would make this an open redirect.
        $intended = $request->path();

        if (str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
            $this->session->put('intended_url', $intended);
        }

        return Response::redirect('/login');
    }
}
