<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class StartSession implements Middleware
{
    public function __construct(private readonly Session $session)
    {
    }

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        $this->session->start();
        $this->session->beginRequest();

        return $next($request);
    }
}
