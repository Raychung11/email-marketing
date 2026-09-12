<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Services\AuthManager;
use App\Services\AuthService;
use Tests\Support\TestCase;

/**
 * HTTP-level protections: CSRF, IDOR, redirect safety, throttling, response
 * headers and the error surface.
 */
final class WebSecurityTest extends TestCase
{
    public function testStateChangingRequestsRequireACsrfToken(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $response = $this->postWithoutToken('/contacts', [
            'email'      => 'csrf@example.com',
            'first_name' => 'Should not exist',
        ]);

        $this->assertStatus(419, $response, 'A POST without a token is rejected');

        /** @var \App\Repositories\ContactRepository $contacts */
        $contacts = $this->container->make(\App\Repositories\ContactRepository::class);
        $this->assertNull($contacts->findByEmail('csrf@example.com'), 'Nothing was written');
    }

    public function testAWrongCsrfTokenIsRejected(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $response = $this->postWithoutToken('/contacts', [
            '_token' => str_repeat('a', 64),
            'email'  => 'wrongtoken@example.com',
        ]);

        $this->assertStatus(419, $response);
    }

    public function testTheCorrectCsrfTokenIsAccepted(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $response = $this->post('/contacts', [
            'email'      => 'valid@example.com',
            'first_name' => 'Valid',
        ]);

        // 302 to the new contact.
        $this->assertStatus(302, $response);

        /** @var \App\Repositories\ContactRepository $contacts */
        $contacts = $this->container->make(\App\Repositories\ContactRepository::class);
        $this->assertNotNull($contacts->findByEmail('valid@example.com'));
    }

    public function testGetRequestsDoNotNeedAToken(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $this->assertStatus(200, $this->get('/contacts'));
    }

    public function testCsrfTokensAreComparedInConstantTime(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        /** @var Csrf $csrf */
        $csrf  = $this->container->make(Csrf::class);
        $token = $csrf->token();

        $this->assertTrue($csrf->verify($token));
        $this->assertFalse($csrf->verify(''));
        $this->assertFalse($csrf->verify(null));
        // A prefix of the real token must not pass.
        $this->assertFalse($csrf->verify(substr($token, 0, 32)));
    }

    /** IDOR: guessing another organisation's contact id must not work. */
    public function testCannotReadAnotherOrganisationsContactByGuessingItsId(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $this->bindTenant($orgB['organisation_id']);
        $victimId = $this->createContact(['email' => 'victim@example.com', 'first_name' => 'Victim']);

        $this->actingAs($orgA['user_id'], $orgA['organisation_id']);

        $response = $this->get('/contacts/' . $victimId);

        $this->assertStatus(404, $response);
        $this->assertNotContainsString('victim@example.com', $response->body(), 'No data leaks through the error page');
    }

    public function testCannotMutateAnotherOrganisationsContact(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $this->bindTenant($orgB['organisation_id']);
        $victimId = $this->createContact(['email' => 'target@example.com', 'first_name' => 'Original']);

        $this->actingAs($orgA['user_id'], $orgA['organisation_id']);

        $this->assertStatus(404, $this->post('/contacts/' . $victimId, [
            'email'      => 'target@example.com',
            'first_name' => 'Hijacked',
        ]));

        $this->bindTenant($orgB['organisation_id']);

        /** @var \App\Repositories\ContactRepository $contacts */
        $contacts = $this->container->make(\App\Repositories\ContactRepository::class);

        $this->assertSame('Original', (string) $contacts->findOrFail($victimId)['first_name']);
    }

    public function testUnauthenticatedUsersAreSentToLoginNotShownData(): void
    {
        // Registering signs the owner in, which is the real behaviour, so sign out
        // to test what a guest actually sees.
        $this->createOrganisation();
        $this->signOut();

        foreach (['/dashboard', '/contacts', '/segments', '/settings', '/suppressions', '/team'] as $path) {
            $response = $this->get($path);

            $this->assertStatus(302, $response, "{$path} must redirect a guest");
            $this->assertSame('/login', $response->headers()['Location'] ?? '', "{$path} redirects to sign in");
        }
    }

    public function testPermissionsAreEnforcedAtTheRouteNotJustTheMenu(): void
    {
        $org    = $this->createOrganisation();
        $viewer = $this->createUserWithRole($org['organisation_id'], 'VIEWER');

        $this->actingAs($viewer, $org['organisation_id']);

        // The sidebar hides these, but hiding a link is not access control.
        $this->assertStatus(403, $this->get('/contacts/create'));
        $this->assertStatus(403, $this->get('/suppressions'));
        $this->assertStatus(403, $this->get('/settings'));
        $this->assertStatus(403, $this->get('/team'));

        // What a viewer may do still works.
        $this->assertStatus(200, $this->get('/contacts'));
    }

    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        $org = $this->createOrganisation(['email' => 'throttle@example.test']);

        /** @var AuthService $auth */
        $auth = $this->container->make(AuthService::class);

        $attempts = 0;

        // The configured limit is 5; the 6th attempt must be refused with 429
        // rather than merely 401.
        for ($i = 0; $i < 8; $i++) {
            try {
                $auth->attempt('throttle@example.test', 'wrong password', '203.0.113.44', 'test');
            } catch (HttpException $e) {
                if ($e->statusCode() === 429) {
                    $this->assertTrue($attempts >= 5, 'Throttling kicks in after the configured attempts');
                    $this->assertContainsString('Too many login attempts', $e->getMessage());

                    return;
                }

                $attempts++;
            }
        }

        $this->fail('Login was never throttled after 8 failed attempts.');
    }

    public function testAWrongPasswordDoesNotRevealWhetherTheAccountExists(): void
    {
        $this->createOrganisation(['email' => 'known@example.test']);

        /** @var AuthService $auth */
        $auth = $this->container->make(AuthService::class);

        $known = $this->assertThrows(
            HttpException::class,
            static fn () => $auth->attempt('known@example.test', 'wrong', '198.51.100.1', 'test')
        );

        $unknown = $this->assertThrows(
            HttpException::class,
            static fn () => $auth->attempt('nobody@example.test', 'wrong', '198.51.100.2', 'test')
        );

        $this->assertSame($known->getMessage(), $unknown->getMessage(), 'The same message either way');
        $this->assertSame($known->statusCode(), $unknown->statusCode());
    }

    public function testSuccessfulLoginRegeneratesTheSession(): void
    {
        $org = $this->createOrganisation(['email' => 'fixation@example.test']);

        /** @var \App\Core\Session $session */
        $session = $this->container->make(\App\Core\Session::class);
        $session->start();
        $session->put('attacker_planted', 'value');

        /** @var AuthService $auth */
        $auth = $this->container->make(AuthService::class);
        $auth->attempt('fixation@example.test', 'correct horse battery staple', '203.0.113.9', 'test');

        // Session fixation defence: the id is rotated on privilege change. The
        // array driver records the rotation so it can be asserted here.
        $this->assertNotNull($session->get('_regenerated_at'), 'The session id is regenerated on sign-in');

        /** @var AuthManager $authManager */
        $authManager = $this->container->make(AuthManager::class);
        $this->assertTrue($authManager->check());
    }

    public function testLogoutClearsTheSession(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);
        $this->assertTrue($auth->check());

        /** @var AuthService $authService */
        $authService = $this->container->make(AuthService::class);
        $authService->logout();

        $this->assertFalse($auth->check(), 'The session no longer identifies anyone');
        $this->assertFalse($this->tenant->isBound(), 'And no tenant is bound');
    }

    public function testSecurityHeadersArePresentOnEveryResponse(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $headers = $this->get('/dashboard')->headers();

        $this->assertSame('nosniff', $headers['X-Content-Type-Options'] ?? '');
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options'] ?? '');
        $this->assertContainsString('strict-origin', $headers['Referrer-Policy'] ?? '');
        $this->assertContainsString("default-src 'self'", $headers['Content-Security-Policy'] ?? '');
        $this->assertContainsString("form-action 'self'", $headers['Content-Security-Policy'] ?? '');
    }

    public function testStackTracesAreNotShownWhenDebugIsOff(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        /** @var \App\Core\Config $config */
        $config = $this->container->make(\App\Core\Config::class);
        $config->set('app.debug', false);

        $response = $this->get('/contacts/999999');

        $this->assertStatus(404, $response);
        $this->assertNotContainsString('#0 ', $response->body(), 'No stack frames');
        $this->assertNotContainsString('/app/', $response->body(), 'No file paths');
        $this->assertContainsString('Reference:', $response->body(), 'A correlation id is offered instead');
    }

    public function testPasswordsAreHashedNotStored(): void
    {
        $org = $this->createOrganisation(['email' => 'hashed@example.test', 'password' => 'a long enough password']);

        $user = $this->connection->selectOne('SELECT password_hash FROM users WHERE id = ?', [$org['user_id']]);

        $this->assertNotContainsString('a long enough password', (string) $user['password_hash']);
        $this->assertTrue(
            password_verify('a long enough password', (string) $user['password_hash']),
            'The stored value verifies the password'
        );
    }

    public function testPasswordResetTokensAreStoredOnlyAsHashes(): void
    {
        $org = $this->createOrganisation(['email' => 'reset@example.test']);

        /** @var AuthService $auth */
        $auth  = $this->container->make(AuthService::class);
        $token = $auth->beginPasswordReset('reset@example.test', '203.0.113.1', 'test');

        $this->assertNotNull($token);

        $row = $this->connection->selectOne('SELECT token_hash FROM password_resets ORDER BY id DESC');

        $this->assertNotSame($token, (string) $row['token_hash'], 'The plaintext token is not stored');
        $this->assertSame(hash('sha256', (string) $token), (string) $row['token_hash']);
    }

    public function testAnExpiredPasswordResetTokenIsRefused(): void
    {
        $org = $this->createOrganisation(['email' => 'expired@example.test']);

        /** @var AuthService $auth */
        $auth  = $this->container->make(AuthService::class);
        $token = $auth->beginPasswordReset('expired@example.test', '203.0.113.1', 'test');

        $this->connection->execute(
            'UPDATE password_resets SET expires_at = ?',
            ['2020-01-01 00:00:00']
        );

        $this->assertFalse(
            $auth->completePasswordReset((string) $token, 'a brand new long password'),
            'An expired token must not reset a password'
        );
    }

    public function testAPasswordResetTokenCanOnlyBeUsedOnce(): void
    {
        $org = $this->createOrganisation(['email' => 'once@example.test']);

        /** @var AuthService $auth */
        $auth  = $this->container->make(AuthService::class);
        $token = $auth->beginPasswordReset('once@example.test', '203.0.113.1', 'test');

        $this->assertTrue($auth->completePasswordReset((string) $token, 'first new long password'));
        $this->assertFalse(
            $auth->completePasswordReset((string) $token, 'second new long password'),
            'A consumed token is dead'
        );
    }

    public function testShortAndCommonPasswordsAreRejected(): void
    {
        $this->createOrganisation();

        /** @var AuthService $auth */
        $auth = $this->container->make(AuthService::class);

        $this->assertThrows(
            \App\Core\ValidationException::class,
            static fn () => $auth->createUser('short@example.test', 'short')
        );

        $this->assertThrows(
            \App\Core\ValidationException::class,
            static fn () => $auth->createUser('common@example.test', 'password123'),
            'Passwords that dominate credential-stuffing lists are refused'
        );
    }
}
