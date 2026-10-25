<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Hash;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Database\Connection;
use App\Repositories\MembershipRepository;
use App\Repositories\UserRepository;

/**
 * Registration, login, logout and password reset.
 *
 * Login is throttled on two axes — by address and by source IP — so that neither
 * a targeted attack on one account nor a spray across many is cheap.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly AuthManager $auth,
        private readonly Hash $hash,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Connection $connection,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array{user:array<string,mixed>,organisation_id:int|null}
     * @throws HttpException on bad credentials, lockout or throttling
     */
    public function attempt(string $email, string $password, string $ip, string $userAgent): array
    {
        $maxAttempts = (int) $this->config->get('security.login.max_attempts', 5);
        $decay       = (int) $this->config->get('security.login.decay_seconds', 900);

        $emailKey = 'login:email:' . hash('sha256', normalize_email($email));
        $ipKey    = 'login:ip:' . hash('sha256', $ip);

        if ($this->limiter->tooManyAttempts($emailKey, $maxAttempts)
            || $this->limiter->tooManyAttempts($ipKey, $maxAttempts * 5)
        ) {
            $wait = max($this->limiter->availableIn($emailKey), $this->limiter->availableIn($ipKey));

            $this->audit->log('login_throttled', 'user', null, null, [
                'email' => \App\Support\Str::maskEmail($email),
                'ip'    => $ip,
            ]);

            throw HttpException::tooManyRequests(
                'Too many login attempts. Please try again in ' . max(1, (int) ceil($wait / 60)) . ' minute(s).'
            );
        }

        $user = $this->users->findByEmail($email);

        // Always burn the hash comparison, even when the user does not exist, so
        // response timing does not reveal which addresses are registered.
        $passwordOk = $this->hash->check($password, (string) ($user['password_hash'] ?? ''));

        if ($user === null || !$passwordOk) {
            $this->limiter->hit($emailKey, $decay);
            $this->limiter->hit($ipKey, $decay);

            if ($user !== null) {
                $this->users->recordFailedLogin((int) $user['id'], $maxAttempts, $decay);
                $this->audit->log('login_failed', 'user', (int) $user['id'], null, ['ip' => $ip]);
            }

            throw HttpException::unauthorized('Those credentials do not match our records.');
        }

        if ($this->users->isLocked($user)) {
            throw HttpException::tooManyRequests(
                'This account is temporarily locked after repeated failed sign-in attempts.'
            );
        }

        if ((string) $user['status'] === 'disabled') {
            throw HttpException::forbidden('This account has been disabled.');
        }

        // Transparently upgrade the hash if the configured cost has changed.
        if ($this->hash->needsRehash((string) $user['password_hash'])) {
            $this->users->update((int) $user['id'], ['password_hash' => $this->hash->make($password)]);
        }

        $this->limiter->clear($emailKey);
        $this->users->recordSuccessfulLogin((int) $user['id'], $ip);

        $membership     = $this->memberships->defaultMembership((int) $user['id']);
        $organisationId = $membership === null ? null : (int) $membership['organisation_id'];

        $this->auth->login((int) $user['id'], $organisationId);
        $this->audit->setActor((int) $user['id']);
        $this->audit->log('login_succeeded', 'user', (int) $user['id'], null, ['ip' => $ip], $organisationId);

        return ['user' => $user, 'organisation_id' => $organisationId];
    }

    public function logout(): void
    {
        $userId = $this->auth->id();

        if ($userId !== null) {
            $this->audit->log('logout', 'user', $userId);
        }

        $this->auth->logout();
    }

    /**
     * Create a user account. Organisation membership is granted separately by
     * OnboardingService or the team invitation flow.
     *
     * @param array<string,mixed> $attributes
     */
    public function createUser(string $email, string $password, array $attributes = []): int
    {
        $this->assertPasswordPolicy($password);

        if ($this->users->emailExists($email)) {
            throw new \App\Core\ValidationException([
                'email' => ['An account with this email address already exists.'],
            ]);
        }

        return $this->users->create(array_merge([
            'email'               => $email,
            'password_hash'       => $this->hash->make($password),
            'status'              => 'active',
            'password_changed_at' => $this->clock->nowString(),
        ], $attributes));
    }

    /**
     * Start a password reset.
     *
     * Returns the plaintext token for delivery by email. Only its hash is
     * stored, so a database dump cannot be used to reset anyone's password. The
     * caller must not reveal whether the address existed.
     */
    public function beginPasswordReset(string $email, string $ip, string $userAgent): ?string
    {
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $ttl   = (int) $this->config->get('security.password_reset.ttl', 3600);

        // Invalidate any outstanding tokens for this user.
        $this->connection->table('password_resets')
            ->where('user_id', '=', (int) $user['id'])
            ->whereNull('used_at')
            ->update(['used_at' => $this->clock->nowString()]);

        $this->connection->table('password_resets')->insert([
            'user_id'              => (int) $user['id'],
            'token_hash'           => hash('sha256', $token),
            'expires_at'           => $this->clock->now()->modify("+{$ttl} seconds")->format('Y-m-d H:i:s'),
            'requested_ip'         => $ip,
            'requested_user_agent' => substr($userAgent, 0, 255),
            'created_at'           => $this->clock->nowString(),
            'updated_at'           => $this->clock->nowString(),
        ]);

        $this->audit->log('password_reset_requested', 'user', (int) $user['id'], null, ['ip' => $ip]);

        return $token;
    }

    /**
     * Redeem a reset token and set the new password.
     *
     * The token is burned by a conditional UPDATE, not by a read followed by a
     * write. Reading the row, deciding it was unused, and only then marking it
     * means two requests arriving together both read "unused" and both set a
     * password — the second silently overwriting the first. The predicate makes
     * the database the arbiter: exactly one UPDATE affects a row and the loser
     * is told by the return value.
     *
     * Expiry is part of that same predicate rather than a separate check, so a
     * token cannot expire between being examined and being used.
     *
     * Every session the account has open is revoked. This is the point of a
     * password reset and it is what the previous implementation did not do: it
     * called logout(), which ends the session of the browser doing the reset —
     * an anonymous visitor who was never signed in. Whoever held a stolen cookie
     * kept it.
     */
    public function completePasswordReset(string $token, string $newPassword): bool
    {
        // Before the token is burned: a password the policy rejects must not
        // cost somebody their one valid link.
        $this->assertPasswordPolicy($newPassword);

        $tokenHash = hash('sha256', $token);
        $now       = $this->clock->nowString();
        $userId    = null;

        $redeemed = $this->connection->transaction(function () use ($tokenHash, $now, $newPassword, &$userId): bool {
            $burned = $this->connection->execute(
                'UPDATE password_resets SET used_at = ?, updated_at = ? '
                . 'WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
                [$now, $now, $tokenHash, $now]
            );

            if ($burned < 1) {
                return false;
            }

            $record = $this->connection->table('password_resets')
                ->where('token_hash', '=', $tokenHash)
                ->first();

            if ($record === null) {
                return false;
            }

            $userId = (int) $record['user_id'];

            $this->users->update($userId, [
                'password_hash'       => $this->hash->make($newPassword),
                'password_changed_at' => $now,
                // A reset is the documented way out of a lockout, so it clears
                // one. Leaving the counter set would lock the account out again
                // on the first attempt with the new password.
                'failed_login_count'  => 0,
                'locked_until'        => null,
            ]);

            // Any other link still outstanding for this account is spent too.
            // Whoever asked for a reset twice does not need two live tokens,
            // and one of them may be the attacker's.
            $this->connection->table('password_resets')
                ->where('user_id', '=', $userId)
                ->whereNull('used_at')
                ->update(['used_at' => $now, 'updated_at' => $now]);

            $this->users->revokeSessions($userId);

            return true;
        });

        if ($redeemed !== true || $userId === null) {
            return false;
        }

        // No token, no hash and no password in the audit record — only that it
        // happened, and to whom.
        $this->audit->log('password_reset_completed', 'user', $userId, null, [
            'sessions_revoked' => true,
        ]);

        // Including this request's own session, if the visitor happened to have
        // one. The revocation above is what reaches the others.
        $this->auth->logout();

        return true;
    }

    /**
     * Change a password for somebody who is signed in and knows the old one.
     *
     * Also revokes every session for the account, for the same reason the reset
     * does: the usual motive for changing a password voluntarily is suspecting
     * somebody else has it. The difference is that this caller is present, so
     * their own session is re-stamped with the new version instead of being
     * thrown away — signing people out of the tab they just used would teach
     * them not to change their password.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $user = $this->users->findById($userId);

        if ($user === null || !$this->hash->check($currentPassword, (string) $user['password_hash'])) {
            return false;
        }

        $this->assertPasswordPolicy($newPassword);

        $version = $this->connection->transaction(function () use ($userId, $newPassword): int {
            $this->users->update($userId, [
                'password_hash'       => $this->hash->make($newPassword),
                'password_changed_at' => $this->clock->nowString(),
            ]);

            // A pending reset link is a credential for the old password's
            // owner. Changing the password voluntarily spends it.
            $this->connection->table('password_resets')
                ->where('user_id', '=', $userId)
                ->whereNull('used_at')
                ->update([
                    'used_at'    => $this->clock->nowString(),
                    'updated_at' => $this->clock->nowString(),
                ]);

            return $this->users->revokeSessions($userId);
        });

        // Only if the caller is the account holder. An administrator resetting
        // somebody else's password must not inherit their session.
        if ($this->auth->id() === $userId) {
            $this->auth->acceptSessionVersion((int) $version);
        }

        $this->audit->log('password_changed', 'user', $userId, null, [
            'sessions_revoked' => true,
        ]);

        return true;
    }

    private function assertPasswordPolicy(string $password): void
    {
        $min = (int) $this->config->get('security.password.min_length', 12);

        if (mb_strlen($password) < $min) {
            throw new \App\Core\ValidationException([
                'password' => ["Password must be at least {$min} characters."],
            ]);
        }

        // Reject the handful of passwords that dominate credential-stuffing
        // lists. A full breach-corpus check belongs behind an API and is on the
        // roadmap; this covers the worst of it at zero cost.
        $common = [
            'password', 'password123', '123456789012', 'qwertyuiop12',
            'letmein12345', 'administrator', 'welcome12345',
        ];

        if (in_array(mb_strtolower($password), $common, true)) {
            throw new \App\Core\ValidationException([
                'password' => ['That password is too common. Please choose another.'],
            ]);
        }
    }
}
