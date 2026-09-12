<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\TeamService;

final class TeamController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly TeamService $team,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        return $this->render('settings.team', [
            'members' => $this->team->members(),
            'roles'   => $this->team->assignableRoles(),
        ]);
    }

    public function invite(Request $request): Response
    {
        $data = $this->validate($request, [
            'email' => 'required|email|max:255',
            'role'  => 'required|max:60',
        ]);

        $token = $this->team->invite((string) $data['email'], (string) $data['role']);
        $this->team->sendInvitation((string) $data['email'], $token);

        return $this->withSuccess('/team', 'Invitation sent to ' . $data['email'] . '.');
    }

    public function changeRole(Request $request): Response
    {
        $data = $this->validate($request, ['role' => 'required|max:60']);

        $this->team->changeRole((int) $request->route('id'), (string) $data['role']);

        return $this->withSuccess('/team', 'Role updated.');
    }

    public function remove(Request $request): Response
    {
        $this->team->remove((int) $request->route('id'));

        return $this->withSuccess('/team', 'Access removed.');
    }

    public function showAcceptInvitation(Request $request): Response
    {
        return $this->render('auth.accept_invitation', ['token' => (string) $request->route('token', '')]);
    }

    public function acceptInvitation(Request $request): Response
    {
        $minLength = (int) $this->config->get('security.password.min_length', 12);

        $data = $this->validate($request, [
            'token'      => 'required|max:255',
            'password'   => 'required|min:' . $minLength . '|max:255|confirmed',
            'first_name' => 'nullable|max:100',
            'last_name'  => 'nullable|max:100',
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $this->team->acceptInvitation(
            (string) $data['token'],
            (string) $data['password'],
            (string) ($data['first_name'] ?? ''),
            (string) ($data['last_name'] ?? '')
        );

        return $this->withSuccess('/login', 'Your account is ready. Please sign in.');
    }
}
