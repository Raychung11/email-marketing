<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Services\AuthManager;
use App\Services\TeamService;
use Tests\Support\TestCase;

/**
 * RBAC. Every check is by permission key; nothing anywhere branches on a role
 * name, which is what lets an organisation get a custom role without a code
 * change — and what makes these boundaries hold in the API too.
 */
final class PermissionTest extends TestCase
{
    public function testOwnerHoldsEveryPermission(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);

        foreach (['contacts.delete', 'campaigns.send', 'billing.manage', 'users.manage', 'compliance.manage'] as $permission) {
            $this->assertTrue($auth->can($permission), "An owner should hold {$permission}");
        }
    }

    /**
     * The separation that makes campaign review meaningful: an author cannot
     * approve, and an approver cannot author.
     */
    public function testMarketerCanAuthorButNotApproveOrSend(): void
    {
        $org      = $this->createOrganisation();
        $marketer = $this->createUserWithRole($org['organisation_id'], 'MARKETER');

        $this->actingAs($marketer, $org['organisation_id']);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);

        $this->assertTrue($auth->can('campaigns.create'));
        $this->assertTrue($auth->can('campaigns.edit'));
        $this->assertFalse($auth->can('campaigns.approve'), 'A marketer must not approve their own work');
        $this->assertFalse($auth->can('campaigns.send'));
    }

    public function testApproverCanApproveButNotAuthor(): void
    {
        $org      = $this->createOrganisation();
        $approver = $this->createUserWithRole($org['organisation_id'], 'APPROVER');

        $this->actingAs($approver, $org['organisation_id']);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);

        $this->assertTrue($auth->can('campaigns.approve'));
        $this->assertFalse($auth->can('campaigns.create'));
        $this->assertFalse($auth->can('campaigns.edit'));
    }

    public function testViewerCannotChangeAnything(): void
    {
        $org    = $this->createOrganisation();
        $viewer = $this->createUserWithRole($org['organisation_id'], 'VIEWER');

        $this->actingAs($viewer, $org['organisation_id']);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);

        $this->assertTrue($auth->can('contacts.view'));

        foreach ([
            'contacts.create', 'contacts.edit', 'contacts.delete', 'contacts.import',
            'campaigns.create', 'settings.manage', 'users.manage', 'compliance.manage',
        ] as $permission) {
            $this->assertFalse($auth->can($permission), "A viewer must not hold {$permission}");
        }
    }

    public function testOnlyComplianceManagersReachTheSuppressionList(): void
    {
        $org = $this->createOrganisation();

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);

        foreach (['MARKETER', 'SALES', 'ANALYST', 'VIEWER'] as $roleKey) {
            $userId = $this->createUserWithRole($org['organisation_id'], $roleKey);
            $this->actingAs($userId, $org['organisation_id']);

            $this->assertFalse(
                $auth->can('compliance.manage'),
                "{$roleKey} must not be able to alter the suppression list"
            );
        }

        $admin = $this->createUserWithRole($org['organisation_id'], 'ADMIN');
        $this->actingAs($admin, $org['organisation_id']);

        $this->assertTrue($auth->can('compliance.manage'));
    }

    public function testAuthoriseThrowsForbiddenWithTheRequiredPermission(): void
    {
        $org    = $this->createOrganisation();
        $viewer = $this->createUserWithRole($org['organisation_id'], 'VIEWER');

        $this->actingAs($viewer, $org['organisation_id']);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);

        $exception = $this->assertThrows(
            HttpException::class,
            static fn () => $auth->authorise('contacts.delete')
        );

        $this->assertSame(403, $exception->statusCode());
        $this->assertContainsString('contacts.delete', $exception->getMessage(), 'The message names what was needed');
    }

    /**
     * Privilege escalation: an admin must not be able to mint an owner, which
     * would otherwise be a one-step path to full control including billing.
     */
    public function testAdminCannotGrantTheOwnerRole(): void
    {
        $org   = $this->createOrganisation();
        $admin = $this->createUserWithRole($org['organisation_id'], 'ADMIN');

        $this->actingAs($admin, $org['organisation_id']);

        /** @var TeamService $team */
        $team = $this->container->make(TeamService::class);

        $assignable = array_column($team->assignableRoles(), 'key');

        $this->assertFalse(in_array('OWNER', $assignable, true), 'OWNER is not offered to a non-owner');

        $exception = $this->assertThrows(
            HttpException::class,
            static fn () => $team->invite('newowner@example.test', 'OWNER')
        );

        $this->assertSame(403, $exception->statusCode());
    }

    public function testOwnerCanGrantTheOwnerRole(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        /** @var TeamService $team */
        $team = $this->container->make(TeamService::class);

        $assignable = array_column($team->assignableRoles(), 'key');

        $this->assertTrue(in_array('OWNER', $assignable, true));
    }

    public function testTheLastOwnerCannotBeDemotedOrRemoved(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $membership = $this->connection->selectOne(
            'SELECT id FROM organisation_users WHERE organisation_id = ? AND user_id = ?',
            [$org['organisation_id'], $org['user_id']]
        );

        /** @var TeamService $team */
        $team = $this->container->make(TeamService::class);

        $this->assertThrows(
            ValidationException::class,
            static fn () => $team->changeRole((int) $membership['id'], 'VIEWER'),
            'Demoting the only owner would strand the organisation'
        );

        $this->assertThrows(
            ValidationException::class,
            static fn () => $team->remove((int) $membership['id'])
        );
    }

    public function testMembershipIdsFromAnotherOrganisationAreNotAddressable(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $victim = $this->connection->selectOne(
            'SELECT id FROM organisation_users WHERE organisation_id = ?',
            [$orgB['organisation_id']]
        );

        // Signed in to A, attempt to change a role in B by guessing its id.
        $this->actingAs($orgA['user_id'], $orgA['organisation_id']);

        /** @var TeamService $team */
        $team = $this->container->make(TeamService::class);

        $exception = $this->assertThrows(
            HttpException::class,
            static fn () => $team->changeRole((int) $victim['id'], 'VIEWER')
        );

        $this->assertSame(404, $exception->statusCode(), 'A cross-tenant membership id is simply not found');
    }

    public function testPermissionsFollowTheActiveOrganisation(): void
    {
        // One person, two organisations, different roles in each.
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $userId = $orgA['user_id'];

        /** @var \App\Repositories\RoleRepository $roles */
        $roles = $this->container->make(\App\Repositories\RoleRepository::class);
        /** @var \App\Repositories\MembershipRepository $memberships */
        $memberships = $this->container->make(\App\Repositories\MembershipRepository::class);

        $memberships->create([
            'organisation_id'    => $orgB['organisation_id'],
            'user_id'            => $userId,
            'role_id'            => (int) $roles->findByKey('VIEWER')['id'],
            'status'             => 'active',
            'invite_accepted_at' => $this->clock->nowString(),
        ]);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);

        $this->actingAs($userId, $orgA['organisation_id']);
        $this->assertTrue($auth->can('contacts.delete'), 'Owner in organisation A');

        $this->actingAs($userId, $orgB['organisation_id']);
        $this->assertFalse($auth->can('contacts.delete'), 'Only a viewer in organisation B');
    }
}
