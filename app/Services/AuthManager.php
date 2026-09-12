<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Session;
use App\Repositories\MembershipRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Support\TenantContext;

/**
 * Who is acting, and what are they allowed to do.
 *
 * Permission checks are always by permission key. Nothing in the application
 * branches on a role name, so adding a custom role never means editing code.
 */
final class AuthManager
{
    private const SESSION_USER_ID = 'auth_user_id';
    private const SESSION_ORG_ID  = 'auth_organisation_id';

    /** @var array<string,mixed>|null */
    private ?array $user = null;

    /** @var array<int,string>|null */
    private ?array $permissions = null;

    /** @var array<string,mixed>|null */
    private ?array $membership = null;

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly RoleRepository $roles,
        private readonly TenantContext $tenant,
    ) {
    }

    // ------------------------------------------------------------- identity

    public function login(int $userId, ?int $organisationId = null): void
    {
        // Regenerate on privilege change: defeats session fixation.
        $this->session->regenerate();
        $this->session->put(self::SESSION_USER_ID, $userId);

        if ($organisationId !== null) {
            $this->session->put(self::SESSION_ORG_ID, $organisationId);
        }

        $this->reset();
    }

    public function logout(): void
    {
        $this->session->invalidate();
        $this->tenant->clear();
        $this->reset();
    }

    public function check(): bool
    {
        return $this->id() !== null && $this->user() !== null;
    }

    public function id(): ?int
    {
        $id = $this->session->get(self::SESSION_USER_ID);

        return is_int($id) || (is_string($id) && $id !== '') ? (int) $id : null;
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $id = $this->id();

        if ($id === null) {
            return null;
        }

        $user = $this->users->findById($id);

        if ($user === null || (string) $user['status'] === 'disabled') {
            return null;
        }

        return $this->user = $user;
    }

    /** @return array<string,mixed> */
    public function userOrFail(): array
    {
        $user = $this->user();

        if ($user === null) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    public function isSuperAdmin(): bool
    {
        $user = $this->user();

        return $user !== null && (int) ($user['is_super_admin'] ?? 0) === 1;
    }

    public function displayName(): string
    {
        $user = $this->user();

        if ($user === null) {
            return '';
        }

        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));

        return $name !== '' ? $name : (string) $user['email'];
    }

    // ------------------------------------------------------------- tenancy

    public function organisationIdFromSession(): ?int
    {
        $id = $this->session->get(self::SESSION_ORG_ID);

        return is_int($id) || (is_string($id) && $id !== '') ? (int) $id : null;
    }

    public function setActiveOrganisation(int $organisationId): void
    {
        $this->session->put(self::SESSION_ORG_ID, $organisationId);
        // Switching tenant is a privilege change.
        $this->session->regenerate();
        $this->reset();
    }

    /**
     * The verified membership for the active organisation, or null.
     *
     * This is the authorisation boundary: it reads organisation_users, never a
     * request parameter.
     *
     * @return array<string,mixed>|null
     */
    public function membership(): ?array
    {
        if ($this->membership !== null) {
            return $this->membership;
        }

        $userId         = $this->id();
        $organisationId = $this->tenant->isBound()
            ? $this->tenant->organisationId()
            : $this->organisationIdFromSession();

        if ($userId === null || $organisationId === null) {
            return null;
        }

        $membership = $this->memberships->activeMembership($userId, $organisationId);

        return $this->membership = $membership;
    }

    public function roleKey(): ?string
    {
        $membership = $this->membership();

        return $membership === null ? null : (string) $membership['role_key'];
    }

    // --------------------------------------------------------- permissions

    /** @return array<int,string> */
    public function permissions(): array
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }

        $membership = $this->membership();

        if ($membership === null) {
            return $this->permissions = [];
        }

        return $this->permissions = $this->roles->permissionKeys((int) $membership['role_id']);
    }

    public function can(string $permission): bool
    {
        // A platform super admin is a break-glass role for support and abuse
        // handling. It bypasses RBAC but not compliance: nothing anywhere lets
        // any role send to a suppressed address or without a consent basis.
        if ($this->isSuperAdmin()) {
            return true;
        }

        $permissions = $this->permissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function cannot(string $permission): bool
    {
        return !$this->can($permission);
    }

    /** @param array<int,string> $permissions */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function authorise(string $permission): void
    {
        if ($this->cannot($permission)) {
            throw HttpException::forbidden(
                'You do not have permission to do this (' . $permission . ' is required).'
            );
        }
    }

    private function reset(): void
    {
        $this->user        = null;
        $this->permissions = null;
        $this->membership  = null;
    }
}
