<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Users are platform-level: one account can belong to several organisations.
 * This repository is therefore deliberately not tenant-scoped; membership is
 * resolved through MembershipRepository.
 */
final class UserRepository extends Repository
{
    protected function table(): string
    {
        return 'users';
    }

    protected function isTenantScoped(): bool
    {
        return false;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->unscoped()
            ->where('email_normalized', '=', normalize_email($email))
            ->whereNull('deleted_at')
            ->first();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->unscoped()->where('id', '=', $id)->whereNull('deleted_at')->first();
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        $attributes['uuid']             = $attributes['uuid'] ?? uuid4();
        $attributes['email_normalized'] = normalize_email((string) $attributes['email']);

        return $this->unscoped()->insert($this->withTimestamps($attributes));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        return $this->unscoped()
            ->where('id', '=', $id)
            ->update($this->withTimestamps($attributes, false));
    }

    public function recordSuccessfulLogin(int $id, string $ip): void
    {
        $this->update($id, [
            'last_login_at'      => $this->now(),
            'last_login_ip'      => $ip,
            'failed_login_count' => 0,
            'locked_until'       => null,
        ]);
    }

    public function recordFailedLogin(int $id, int $maxAttempts, int $lockSeconds): void
    {
        $user = $this->findById($id);

        if ($user === null) {
            return;
        }

        $failures = ((int) ($user['failed_login_count'] ?? 0)) + 1;

        $this->update($id, [
            'failed_login_count' => $failures,
            'locked_until'       => $failures >= $maxAttempts
                ? $this->clock->now()->modify("+{$lockSeconds} seconds")->format('Y-m-d H:i:s')
                : ($user['locked_until'] ?? null),
        ]);
    }

    public function isLocked(array $user): bool
    {
        $lockedUntil = $user['locked_until'] ?? null;

        if (!is_string($lockedUntil) || $lockedUntil === '') {
            return false;
        }

        return $lockedUntil > $this->now();
    }

    /**
     * Invalidate every session this account has open, and return the new version.
     *
     * The increment is done in SQL rather than read-modify-write, so two
     * concurrent revocations (a reset completing while an admin disables the
     * account) both move the number forward instead of one overwriting the
     * other with a value it read before the other wrote.
     *
     * Returning the new version is what lets the caller keep the session it is
     * holding right now — a deliberate password change should not sign the
     * person doing it out of the tab they are in.
     */
    public function revokeSessions(int $id): int
    {
        $this->connection->execute(
            'UPDATE users SET session_version = session_version + 1, sessions_revoked_at = ?, updated_at = ? '
            . 'WHERE id = ?',
            [$this->now(), $this->now(), $id]
        );

        return $this->sessionVersion($id);
    }

    /**
     * The version a session for this account must carry to be accepted.
     *
     * A missing row reads as 0, which no session can ever have been stamped
     * with, so a deleted account's cookie fails the comparison rather than
     * passing it by default.
     */
    public function sessionVersion(int $id): int
    {
        $value = $this->unscoped()->where('id', '=', $id)->value('session_version');

        return $value === null ? 0 : (int) $value;
    }

    public function emailExists(string $email): bool
    {
        return $this->unscoped()
            ->where('email_normalized', '=', normalize_email($email))
            ->exists();
    }
}
