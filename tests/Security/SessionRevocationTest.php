<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Session;
use App\Repositories\UserRepository;
use App\Services\AuthManager;
use App\Services\AuthService;
use Tests\Support\TestCase;

/**
 * A password reset has to reach sessions we cannot see.
 *
 * The bug these cover: completePasswordReset() ended with $auth->logout(),
 * which destroys the session of the browser performing the reset — on that
 * flow, an anonymous visitor. Every session already open elsewhere survived, so
 * a stolen cookie outlived the password it was obtained with. changePassword()
 * revoked nothing.
 *
 * A second session is modelled the way it actually works: a cookie is nothing
 * but a reference to stored session data, so copying the store and presenting it
 * back later is precisely what an attacker's browser does.
 */
final class SessionRevocationTest extends TestCase
{
    public function testAPasswordResetSignsOutASessionOpenedElsewhere(): void
    {
        $org = $this->createOrganisation(['email' => 'victim@example.test']);
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $stolen = $this->snapshotSession();

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);
        $this->assertTrue($auth->check(), 'The session under test is signed in to begin with');

        /** @var AuthService $authService */
        $authService = $this->container->make(AuthService::class);
        $token       = $authService->beginPasswordReset('victim@example.test', '203.0.113.9', 'test');

        $this->assertNotNull($token);
        $this->assertTrue($authService->completePasswordReset((string) $token, 'a brand new long password'));

        // The attacker returns with the cookie they copied before the reset.
        $this->presentSession($stolen);

        $this->assertFalse(
            $auth->check(),
            'A session opened before the reset must not survive it'
        );
        $this->assertNull($auth->user(), 'And must not resolve to a user');
    }

    public function testARevokedSessionIsDestroyedRatherThanMerelyRefused(): void
    {
        $org = $this->createOrganisation(['email' => 'destroyed@example.test']);
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $stolen = $this->snapshotSession();

        /** @var AuthService $authService */
        $authService = $this->container->make(AuthService::class);
        $token       = $authService->beginPasswordReset('destroyed@example.test', '203.0.113.9', 'test');
        $authService->completePasswordReset((string) $token, 'another brand new password');

        $this->presentSession($stolen);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);
        $auth->check();

        /** @var Session $session */
        $session = $this->container->make(Session::class);

        $this->assertNull(
            $session->get('auth_user_id'),
            'The rejected session is emptied, so the next request does not have to reject it again'
        );
    }

    public function testChangingYourOwnPasswordKeepsTheSessionYouAreUsing(): void
    {
        $org = $this->createOrganisation(['email' => 'self@example.test', 'password' => 'the original password']);
        $this->actingAs($org['user_id'], $org['organisation_id']);

        /** @var AuthService $authService */
        $authService = $this->container->make(AuthService::class);

        $this->assertTrue($authService->changePassword(
            (int) $org['user_id'],
            'the original password',
            'a replacement long password'
        ));

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);

        $this->assertTrue(
            $auth->check(),
            'The person who just typed their password is not signed out of the tab they typed it in'
        );
    }

    public function testChangingYourPasswordStillSignsOutEverywhereElse(): void
    {
        $org = $this->createOrganisation(['email' => 'elsewhere@example.test', 'password' => 'the original password']);
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $stolen = $this->snapshotSession();

        /** @var AuthService $authService */
        $authService = $this->container->make(AuthService::class);
        $authService->changePassword(
            (int) $org['user_id'],
            'the original password',
            'a replacement long password'
        );

        $this->presentSession($stolen);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);

        $this->assertFalse(
            $auth->check(),
            'Only the session that performed the change is kept'
        );
    }

    public function testASessionCarryingNoVersionIsNotTrusted(): void
    {
        $org = $this->createOrganisation(['email' => 'legacy@example.test']);
        $this->actingAs($org['user_id'], $org['organisation_id']);

        // What a session created before this control existed looks like.
        $legacy = $this->snapshotSession();
        unset($legacy['auth_session_version']);

        $this->presentSession($legacy);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);

        $this->assertFalse(
            $auth->check(),
            'There is nothing to compare, so the session is not accepted'
        );
    }

    public function testRevocationMovesTheVersionForwardRatherThanOverwritingIt(): void
    {
        $org = $this->createOrganisation(['email' => 'counter@example.test']);

        /** @var UserRepository $users */
        $users = $this->container->make(UserRepository::class);

        $before = $users->sessionVersion((int) $org['user_id']);
        $first  = $users->revokeSessions((int) $org['user_id']);
        $second = $users->revokeSessions((int) $org['user_id']);

        $this->assertSame($before + 1, $first, 'Each revocation increments');
        $this->assertSame($before + 2, $second, 'Two revocations do not collapse into one');

        $row = $this->connection->selectOne(
            'SELECT sessions_revoked_at FROM users WHERE id = ?',
            [$org['user_id']]
        );

        $this->assertNotNull($row['sessions_revoked_at'] ?? null, 'When it happened is recorded');
    }

    public function testAResetSpendsEveryOtherOutstandingLinkForTheAccount(): void
    {
        $org = $this->createOrganisation(['email' => 'twolinks@example.test']);

        /** @var AuthService $authService */
        $authService = $this->container->make(AuthService::class);

        $first = $authService->beginPasswordReset('twolinks@example.test', '203.0.113.9', 'test');

        // Requesting a second link while the first is outstanding.
        $second = $authService->beginPasswordReset('twolinks@example.test', '203.0.113.9', 'test');

        $this->assertTrue($authService->completePasswordReset((string) $second, 'a perfectly fine password'));

        $this->assertFalse(
            $authService->completePasswordReset((string) $first, 'a different fine password'),
            'The earlier link is dead too'
        );

        $outstanding = $this->connection->selectOne(
            'SELECT COUNT(*) AS c FROM password_resets WHERE user_id = ? AND used_at IS NULL',
            [$org['user_id']]
        );

        $this->assertSame(0, (int) $outstanding['c'], 'Nothing is left live for this account');
    }

    public function testACompletedResetClearsALockout(): void
    {
        $org = $this->createOrganisation(['email' => 'locked@example.test']);

        $this->connection->execute(
            'UPDATE users SET failed_login_count = 9, locked_until = ? WHERE id = ?',
            ['2099-01-01 00:00:00', $org['user_id']]
        );

        /** @var AuthService $authService */
        $authService = $this->container->make(AuthService::class);
        $token       = $authService->beginPasswordReset('locked@example.test', '203.0.113.9', 'test');

        $this->assertTrue($authService->completePasswordReset((string) $token, 'a password that is long enough'));

        $row = $this->connection->selectOne(
            'SELECT failed_login_count, locked_until FROM users WHERE id = ?',
            [$org['user_id']]
        );

        $this->assertSame(0, (int) $row['failed_login_count']);
        $this->assertNull($row['locked_until'], 'A reset is the documented way out of a lockout');
    }

    public function testTheAuditTrailRecordsTheRevocationWithoutTheToken(): void
    {
        $org = $this->createOrganisation(['email' => 'audited@example.test']);

        /** @var AuthService $authService */
        $authService = $this->container->make(AuthService::class);
        $token       = $authService->beginPasswordReset('audited@example.test', '203.0.113.9', 'test');
        $authService->completePasswordReset((string) $token, 'yet another long password');

        $row = $this->connection->selectOne(
            "SELECT action, new_values FROM audit_logs WHERE action = 'password_reset_completed' ORDER BY id DESC"
        );

        $this->assertNotNull($row, 'The reset is audited');

        $metadata = (string) ($row['new_values'] ?? '');

        $this->assertContainsString('sessions_revoked', $metadata);
        $this->assertNotContainsString((string) $token, $metadata, 'The token is not in the audit record');
        $this->assertNotContainsString(
            hash('sha256', (string) $token),
            $metadata,
            'Nor is its hash'
        );
        $this->assertNotContainsString('yet another long password', $metadata, 'Nor is the new password');
    }

    // ----------------------------------------------------------- internals

    /**
     * Everything a session cookie refers to — i.e. what an attacker who has the
     * cookie can present.
     *
     * @return array<string,mixed>
     */
    private function snapshotSession(): array
    {
        /** @var Session $session */
        $session = $this->container->make(Session::class);

        return $session->all();
    }

    /**
     * Arrive with a previously captured session, as a second browser would.
     *
     * The sign-out first is not part of the scenario — it clears the auth
     * manager's per-request caches so the assertion exercises the check rather
     * than a value remembered from earlier in the test.
     *
     * @param array<string,mixed> $data
     */
    private function presentSession(array $data): void
    {
        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);
        $auth->logout();

        /** @var Session $session */
        $session = $this->container->make(Session::class);

        foreach ($data as $key => $value) {
            $session->put((string) $key, $value);
        }
    }
}
