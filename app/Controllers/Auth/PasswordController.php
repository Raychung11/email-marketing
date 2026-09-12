<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthService;
use App\Services\TransactionalMailer;

final class PasswordController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly AuthService $authService,
        private readonly TransactionalMailer $mailer,
        private readonly Logger $logger,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function showForgot(Request $request): Response
    {
        return $this->render('auth.forgot');
    }

    public function sendReset(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email|max:255']);

        $token = $this->authService->beginPasswordReset(
            (string) $data['email'],
            $request->ip(),
            $request->userAgent()
        );

        if ($token !== null) {
            $this->mailer->sendPasswordReset((string) $data['email'], $token);
        }

        // The same response either way: whether an address is registered is not
        // something an unauthenticated visitor gets to discover.
        return $this->withSuccess(
            '/forgot-password',
            'If an account exists for that address, a password reset link is on its way.'
        );
    }

    public function showReset(Request $request): Response
    {
        return $this->render('auth.reset', ['token' => (string) $request->route('token', '')]);
    }

    public function reset(Request $request): Response
    {
        $minLength = (int) $this->config->get('security.password.min_length', 12);

        $data = $this->validate($request, [
            'token'    => 'required|max:255',
            'password' => 'required|min:' . $minLength . '|max:255|confirmed',
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $completed = $this->authService->completePasswordReset(
            (string) $data['token'],
            (string) $data['password']
        );

        if (!$completed) {
            return $this->withError(
                '/forgot-password',
                'That password reset link is invalid or has expired. Please request a new one.'
            );
        }

        return $this->withSuccess('/login', 'Your password has been changed. Please sign in.');
    }
}
