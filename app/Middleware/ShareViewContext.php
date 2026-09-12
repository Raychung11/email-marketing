<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Services\AuthManager;

/**
 * Bind the request's identity into the view layer and the log services, so no
 * controller has to pass the actor around by hand.
 */
final class ShareViewContext implements Middleware
{
    public function __construct(
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly Session $session,
        private readonly AuthManager $auth,
        private readonly AuditService $audit,
        private readonly ActivityService $activity,
        private readonly Config $config,
    ) {
    }

    public function handle(Request $request, callable $next, ?string $argument = null): Response
    {
        $userId = $this->auth->id();

        $this->audit->setActor($userId, $userId === null ? 'system' : 'user');
        $this->audit->setRequestContext($request->ip(), $request->userAgent());
        $this->activity->setActor($userId);

        $this->view->shareMany([
            'csrfToken'   => $this->csrf->token(),
            'currentUser' => $this->auth->user(),
            'auth'        => $this->auth,
            'flash'       => $this->session->pullFlash(),
            'currentPath' => $request->path(),
            'appName'     => $this->config->get('app.name'),
            'navigation'  => $this->config->get('navigation', []),
            'organisation' => null,
            'organisations' => [],
        ]);

        return $next($request);
    }
}
