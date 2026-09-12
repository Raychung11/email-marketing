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
use App\Services\AuthService;

final class LoginController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly AuthService $authService,
        private readonly AuthManager $auth,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function show(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/dashboard');
        }

        return $this->render('auth.login');
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'email'    => 'required|email|max:255',
            'password' => 'required|max:255',
        ]);

        $this->authService->attempt(
            (string) $data['email'],
            (string) $data['password'],
            $request->ip(),
            $request->userAgent()
        );

        // Only ever a local path, and it was validated on the way in.
        $intended = $this->session->get('intended_url');
        $this->session->forget('intended_url');

        $target = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')
            ? $intended
            : '/dashboard';

        return $this->redirect($target);
    }

    public function logout(Request $request): Response
    {
        $this->authService->logout();

        return $this->withSuccess('/login', 'You have been signed out.');
    }
}
