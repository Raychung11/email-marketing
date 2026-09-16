<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Database\Connection;
use App\Repositories\MembershipRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Support\TenantContext;

/**
 * Team membership: invitations, role changes, removal.
 *
 * Two invariants that matter: an organisation can never be left without an owner,
 * and nobody can grant a role they do not themselves hold.
 */
final class TeamService
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly AuthManager $auth,
        private readonly TransactionalMailer $mailer,
        private readonly Connection $connection,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function members(): array
    {
        return $this->memberships->membersOf($this->tenant->organisationId());
    }

    /** @return array<int,array<string,mixed>> */
    public function assignableRoles(): array
    {
        $roles = $this->roles->assignable($this->tenant->organisationId());

        // Privilege escalation guard: an admin cannot mint an owner. Only an
        // existing owner can grant OWNER.
        if ($this->auth->roleKey() !== 'OWNER' && !$this->auth->isSuperAdmin()) {
            $roles = array_values(array_filter(
                $roles,
                static fn (array $role): bool => (string) $role['key'] !== 'OWNER'
            ));
        }

        return $roles;
    }

    /**
     * Invite someone to the organisation.
     *
     * Returns the invitation token so it can be emailed. Only the hash is stored.
     */
    public function invite(string $email, string $roleKey): string
    {
        $role = $this->roles->findByKey($roleKey) ?? $this->roles->findByKey($roleKey, $this->tenant->organisationId());

        if ($role === null) {
            throw new ValidationException(['role' => ['That role does not exist.']]);
        }

        $this->assertCanGrant((string) $role['key']);

        $organisationId = $this->tenant->organisationId();
        $token          = bin2hex(random_bytes(32));

        return $this->connection->transaction(function () use ($email, $role, $organisationId, $token): string {
            $user = $this->users->findByEmail($email);

            if ($user === null) {
                // A placeholder account with an unusable password: the invitee sets
                // a real one when they accept.
                $userId = $this->users->create([
                    'email'         => $email,
                    'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
                    'status'        => 'invited',
                ]);
            } else {
                $userId = (int) $user['id'];

                if ($this->memberships->activeMembership($userId, $organisationId) !== null) {
                    throw new ValidationException(['email' => ['That person is already a member of this organisation.']]);
                }
            }

            $existing = $this->connection->table('organisation_users')
                ->where('organisation_id', '=', $organisationId)
                ->where('user_id', '=', $userId)
                ->first();

            $attributes = [
                'role_id'            => (int) $role['id'],
                'status'             => 'invited',
                'invited_by_user_id' => $this->auth->id(),
                'invite_token_hash'  => hash('sha256', $token),
                'invite_expires_at'  => $this->clock->now()->modify('+7 days')->format('Y-m-d H:i:s'),
            ];

            if ($existing !== null) {
                $this->memberships->update((int) $existing['id'], $attributes);
            } else {
                $this->memberships->create(array_merge($attributes, [
                    'organisation_id' => $organisationId,
                    'user_id'         => $userId,
                ]));
            }

            $this->audit->log('user_invited', 'user', $userId, null, [
                'email' => \App\Support\Str::maskEmail($email),
                'role'  => $role['key'],
            ]);

            return $token;
        });
    }

    /**
     * Send the invitation email.
     *
     * Returns whether the provider accepted it. The caller must not report the
     * invitation as sent on the strength of the database row alone: the
     * membership record and the email are two separate things, and a new account
     * still in its provider's sandbox will happily write the first while the
     * second is refused.
     */
    public function sendInvitation(string $email, string $token): bool
    {
        return $this->mailer->sendTeamInvitation(
            $email,
            (string) ($this->tenant->organisation()['name'] ?? ''),
            $this->auth->displayName(),
            $token,
            $this->tenant->organisationId()
        );
    }

    /** Why the last invitation email did not go out, in words the inviter can act on. */
    public function lastMailError(): ?string
    {
        return $this->mailer->lastError();
    }

    /**
     * Accept an invitation and set a password.
     *
     * @return array{user_id:int,organisation_id:int}
     */
    public function acceptInvitation(string $token, string $password, string $firstName = '', string $lastName = ''): array
    {
        $membership = $this->memberships->findByInviteTokenHash(hash('sha256', $token));

        if ($membership === null) {
            throw HttpException::notFound('That invitation link is not valid.');
        }

        $expiresAt = (string) ($membership['invite_expires_at'] ?? '');

        if ($expiresAt !== '' && $expiresAt < $this->clock->nowString()) {
            throw HttpException::forbidden('That invitation has expired. Ask for a new one.');
        }

        $userId         = (int) $membership['user_id'];
        $organisationId = (int) $membership['organisation_id'];

        $this->connection->transaction(function () use ($membership, $userId, $password, $firstName, $lastName): void {
            $this->users->update($userId, array_filter([
                'password_hash'       => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'status'              => 'active',
                'first_name'          => $firstName !== '' ? $firstName : null,
                'last_name'           => $lastName !== '' ? $lastName : null,
                'password_changed_at' => $this->clock->nowString(),
            ], static fn ($value): bool => $value !== null));

            $this->memberships->update((int) $membership['id'], [
                'status'             => 'active',
                'invite_accepted_at' => $this->clock->nowString(),
                'invite_token_hash'  => null,
            ]);
        });

        $this->audit->log('invitation_accepted', 'user', $userId, null, null, $organisationId);

        return ['user_id' => $userId, 'organisation_id' => $organisationId];
    }

    public function changeRole(int $membershipId, string $roleKey): void
    {
        $membership = $this->requireMembership($membershipId);
        $role       = $this->roles->findByKey($roleKey) ?? $this->roles->findByKey($roleKey, $this->tenant->organisationId());

        if ($role === null) {
            throw new ValidationException(['role' => ['That role does not exist.']]);
        }

        $this->assertCanGrant((string) $role['key']);

        $currentRole = $this->roles->find((int) $membership['role_id']);

        // Never strip the last owner: an organisation with no owner cannot manage
        // billing, invite anyone, or recover itself.
        if ((string) ($currentRole['key'] ?? '') === 'OWNER'
            && (string) $role['key'] !== 'OWNER'
            && $this->memberships->countOwners($this->tenant->organisationId()) <= 1
        ) {
            throw new ValidationException([
                'role' => ['This is the only owner. Make someone else an owner first.'],
            ]);
        }

        $this->memberships->update($membershipId, ['role_id' => (int) $role['id']]);

        $this->audit->log('user_role_changed', 'user', (int) $membership['user_id'], [
            'role' => $currentRole['key'] ?? null,
        ], ['role' => $role['key']]);
    }

    public function remove(int $membershipId): void
    {
        $membership = $this->requireMembership($membershipId);
        $role       = $this->roles->find((int) $membership['role_id']);

        if ((string) ($role['key'] ?? '') === 'OWNER'
            && $this->memberships->countOwners($this->tenant->organisationId()) <= 1
        ) {
            throw new ValidationException([
                'member' => ['This is the only owner. You cannot remove the last one.'],
            ]);
        }

        if ((int) $membership['user_id'] === $this->auth->id()) {
            throw new ValidationException([
                'member' => ['You cannot remove yourself. Ask someone else on the team to do it.'],
            ]);
        }

        $this->memberships->update($membershipId, ['status' => 'suspended']);

        $this->audit->log('user_removed', 'user', (int) $membership['user_id'], null, [
            'membership_id' => $membershipId,
        ]);
    }

    /**
     * Issue a fresh invitation for someone who is already pending.
     *
     * A new token rather than a resend of the old one: the seven-day expiry is
     * counted from the invitation, and someone chasing a colleague a week later
     * should not be handed a link that has already lapsed. Re-issuing also
     * invalidates the previous link, which is the right behaviour if the first
     * one went astray.
     *
     * @return array{email:string,token:string,sent:bool}
     */
    public function resendInvitation(int $membershipId): array
    {
        $membership = $this->requireMembership($membershipId);

        if ((string) $membership['status'] !== 'invited') {
            throw new ValidationException([
                'member' => ['That person has already accepted their invitation.'],
            ]);
        }

        $user = $this->users->findById((int) $membership['user_id']);

        if ($user === null) {
            throw HttpException::notFound();
        }

        $token = bin2hex(random_bytes(32));

        $this->memberships->update($membershipId, [
            'invite_token_hash' => hash('sha256', $token),
            'invite_expires_at' => $this->clock->now()->modify('+7 days')->format('Y-m-d H:i:s'),
        ]);

        $this->audit->log('user_invite_resent', 'user', (int) $membership['user_id'], null, [
            'email' => \App\Support\Str::maskEmail((string) $user['email']),
        ]);

        return [
            'email' => (string) $user['email'],
            'token' => $token,
            'sent'  => $this->sendInvitation((string) $user['email'], $token),
        ];
    }

    /** @return array<string,mixed> */
    private function requireMembership(int $membershipId): array
    {
        $membership = $this->connection->table('organisation_users')
            ->where('id', '=', $membershipId)
            // Scoped to the bound organisation: a membership id from another
            // tenant reads as "not found".
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->first();

        if ($membership === null) {
            throw HttpException::notFound();
        }

        return $membership;
    }

    private function assertCanGrant(string $roleKey): void
    {
        if ($roleKey === 'OWNER' && $this->auth->roleKey() !== 'OWNER' && !$this->auth->isSuperAdmin()) {
            throw HttpException::forbidden('Only an owner can grant the owner role.');
        }
    }
}
