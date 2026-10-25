<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Session;
use App\Services\AuthManager;
use App\Services\AuthService;
use Tests\Support\TestCase;

/**
 * What a password change is supposed to do to everybody else.
 *
 * The old code called `logout()` on the way out of a password reset, which ends
 * the session of whoever is holding the current request. During a reset that is
 * somebody who is not signed in — they are recovering a password they forgot.
 * It did nothing whatsoever to the attacker with a live session on another
 * machine, which is the single scenario a password reset exists for.
 *
 * The fix is a version number on the account. Every session records the version
 * it was issued under, every request compares it, and a password change moves
 * the account forward — which reaches devices this process has never heard of
 * and never will.
 */
final class SessionRevocationTest extends TestCase
{
    /** The scenario in one test: two browsers, one of them not yours. */
    public function testResettingAPasswordSignsOutEveryOtherDevice(): void
    {
        $org    = $this->createOrganisation(['email' => 'owner@example.com']);
        $userId = $org['user_id'];

        // The attacker's browser, already signed in.
        $browser = $this->signedInBrowser($userId, $org['organisation_id']);

        $this->assertTrue($this->request($browser)->check(), 'they are in');

        // The owner resets their password from a different machine.
        $this->resetPasswordFor($userId, 'a brand new passphrase');

        // The attacker's next request, with the same cookie.
        $next = $this->request($browser);

        $this->assertFalse($next->check(), 'and now they are not');
        $this->assertNull($next->user());
    }

    /** The same guarantee from the ordinary change-password screen. */
    public function testChangingAPasswordFromInsideTheAccountAlsoSignsOutOtherDevices(): void
    {
        $org    = $this->createOrganisation(['email' => 'owner@example.com']);
        $userId = $org['user_id'];

        $other = $this->signedInBrowser($userId, $org['organisation_id']);
        $this->assertTrue($this->request($other)->check());

        $this->actingAs($userId, $org['organisation_id']);

        /** @var AuthService $auth */
        $auth = $this->container->make(AuthService::class);

        $this->assertTrue(
            $auth->changePassword($userId, 'correct horse battery staple', 'a different long passphrase')
        );

        $this->assertFalse($this->request($other)->check(), 'the other device is out');
    }

    /**
     * Somebody changing their own password should not be thrown out of the page
     * they are standing on. Every other session goes; theirs is re-stamped.
     */
    public function testTheSessionThatChangedThePasswordStaysSignedIn(): void
    {
        $org    = $this->createOrganisation(['email' => 'owner@example.com']);
        $userId = $org['user_id'];

        $this->actingAs($userId, $org['organisation_id']);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);
        /** @var AuthService $service */
        $service = $this->container->make(AuthService::class);

        $service->changePassword($userId, 'correct horse battery staple', 'a different long passphrase');

        $this->assertTrue($auth->check(), 'still signed in on this device');
    }

    /**
     * Sessions created before the version column existed carry nothing. They are
     * accepted once and stamped, so deploying this does not sign the entire
     * customer base out at the moment of release.
     */
    public function testSessionsPredatingTheVersionColumnAreNotSignedOut(): void
    {
        $org    = $this->createOrganisation(['email' => 'owner@example.com']);
        $userId = $org['user_id'];

        $legacy = $this->signedInBrowser($userId, $org['organisation_id']);

        // Strip the stamp, as an upgrade from the previous release would.
        $legacy->forget('auth_version');

        $this->assertTrue($this->request($legacy)->check(), 'an old session keeps working');
        $this->assertSame(1, (int) $legacy->get('auth_version'), 'and is stamped on the way through');
    }

    // ------------------------------------------------- one-time reset tokens

    /**
     * Two requests redeeming the same reset token at the same time.
     *
     * The old code read the row, checked `used_at IS NULL`, then wrote. Both
     * requests passed the read, both set a password, and the second one won —
     * so a token that leaked could be redeemed twice, by two different people.
     * The claim is now a conditional UPDATE and the database picks the winner.
     */
    public function testAResetTokenCanOnlyBeRedeemedOnce(): void
    {
        $org = $this->createOrganisation(['email' => 'owner@example.com']);

        /** @var AuthService $auth */
        $auth  = $this->container->make(AuthService::class);
        $token = $auth->beginPasswordReset('owner@example.com', '203.0.113.9', 'test');

        $this->assertNotNull($token);

        $first  = $auth->completePasswordReset((string) $token, 'the first new passphrase');
        $second = $auth->completePasswordReset((string) $token, 'the second new passphrase');

        $this->assertTrue($first, 'one caller succeeds');
        $this->assertFalse($second, 'and the other is told the token is spent');

        // And the password is the first one's, not the second's.
        /** @var \App\Core\Hash $hash */
        $hash = $this->container->make(\App\Core\Hash::class);
        $user = $this->connection->selectOne('SELECT password_hash FROM users WHERE id = ?', [$org['user_id']]) ?? [];

        $this->assertTrue($hash->check('the first new passphrase', (string) $user['password_hash']));
        $this->assertFalse($hash->check('the second new passphrase', (string) $user['password_hash']));
    }

    public function testAnExpiredResetTokenIsRefused(): void
    {
        $this->createOrganisation(['email' => 'owner@example.com']);

        /** @var AuthService $auth */
        $auth  = $this->container->make(AuthService::class);
        $token = $auth->beginPasswordReset('owner@example.com', '203.0.113.9', 'test');

        $this->clock->travel('+2 hours');

        $this->assertFalse($auth->completePasswordReset((string) $token, 'a brand new passphrase'));
    }

    /** The audit trail records the event without recording the credential. */
    public function testNeitherTheTokenNorThePasswordReachesTheAuditLog(): void
    {
        $this->createOrganisation(['email' => 'owner@example.com']);

        /** @var AuthService $auth */
        $auth  = $this->container->make(AuthService::class);
        $token = (string) $auth->beginPasswordReset('owner@example.com', '203.0.113.9', 'test');

        $auth->completePasswordReset($token, 'a brand new passphrase');

        $entries = $this->connection->select('SELECT action, old_values, new_values FROM audit_logs');
        $dump    = json_encode($entries, JSON_UNESCAPED_SLASHES);

        $this->assertContainsString('password_reset_completed', (string) $dump);
        $this->assertNotContainsString($token, (string) $dump, 'the token is a bearer credential');
        $this->assertNotContainsString('a brand new passphrase', (string) $dump);
    }

    // ------------------------------------------------------------ internals

    /**
     * Another browser, signed in and holding its own session.
     *
     * The session is the browser; it is what survives between requests. Its own
     * Session instance is the point — two devices sharing one would prove
     * nothing.
     */
    private function signedInBrowser(int $userId, int $organisationId): Session
    {
        $session = new Session([], Session::DRIVER_ARRAY);
        $session->start();

        $this->request($session)->login($userId, $organisationId);

        return $session;
    }

    /**
     * One request from that browser.
     *
     * A fresh AuthManager each time, because that is what production does: every
     * request is a new process and the manager caches the user it loads for the
     * length of one. Reusing a single manager would be testing its cache rather
     * than the revocation.
     */
    private function request(Session $session): AuthManager
    {
        return new AuthManager(
            $session,
            $this->container->make(\App\Repositories\UserRepository::class),
            $this->container->make(\App\Repositories\MembershipRepository::class),
            $this->container->make(\App\Repositories\RoleRepository::class),
            $this->container->make(\App\Support\TenantContext::class),
        );
    }

    private function resetPasswordFor(int $userId, string $newPassword): void
    {
        $email = (string) $this->connection->scalar('SELECT email FROM users WHERE id = ?', [$userId]);

        /** @var AuthService $auth */
        $auth  = $this->container->make(AuthService::class);
        $token = $auth->beginPasswordReset($email, '203.0.113.9', 'test');

        $this->assertTrue($auth->completePasswordReset((string) $token, $newPassword));
    }
}
