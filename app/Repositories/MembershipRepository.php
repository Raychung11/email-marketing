<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * organisation_users is the ONLY authority on which organisations a user may
 * act on. TenantMiddleware consults this table on every request.
 */
final class MembershipRepository extends Repository
{
    protected function table(): string
    {
        return 'organisation_users';
    }

    protected function isTenantScoped(): bool
    {
        return false;
    }

    /**
     * The tenancy check. Returns the membership row with the role key, or null
     * when the user is not an active member of that organisation.
     *
     * @return array<string,mixed>|null
     */
    public function activeMembership(int $userId, int $organisationId): ?array
    {
        return $this->connection->selectOne(
            'SELECT ou.*, r.key AS role_key, r.name AS role_name
             FROM organisation_users ou
             INNER JOIN roles r ON r.id = ou.role_id
             WHERE ou.user_id = ? AND ou.organisation_id = ? AND ou.status = ?',
            [$userId, $organisationId, 'active']
        );
    }

    /** @return array<string,mixed>|null */
    public function defaultMembership(int $userId): ?array
    {
        return $this->connection->selectOne(
            'SELECT ou.*, r.key AS role_key
             FROM organisation_users ou
             INNER JOIN roles r ON r.id = ou.role_id
             INNER JOIN organisations o ON o.id = ou.organisation_id
             WHERE ou.user_id = ? AND ou.status = ? AND o.deleted_at IS NULL
             ORDER BY ou.is_default DESC, ou.created_at ASC',
            [$userId, 'active']
        );
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        return $this->unscoped()->insert($this->withTimestamps($attributes));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        return $this->unscoped()->where('id', '=', $id)->update($this->withTimestamps($attributes, false));
    }

    /** @return array<int,array<string,mixed>> */
    public function membersOf(int $organisationId): array
    {
        return $this->connection->select(
            'SELECT ou.id, ou.status, ou.created_at, ou.last_active_at, ou.invite_accepted_at,
                    u.id AS user_id, u.email, u.first_name, u.last_name, u.last_login_at,
                    r.id AS role_id, r.key AS role_key, r.name AS role_name
             FROM organisation_users ou
             INNER JOIN users u ON u.id = ou.user_id
             INNER JOIN roles r ON r.id = ou.role_id
             WHERE ou.organisation_id = ? AND u.deleted_at IS NULL
             ORDER BY r.rank DESC, u.email',
            [$organisationId]
        );
    }

    public function countOwners(int $organisationId): int
    {
        return (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM organisation_users ou
             INNER JOIN roles r ON r.id = ou.role_id
             WHERE ou.organisation_id = ? AND r.key = 'OWNER' AND ou.status = 'active'",
            [$organisationId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByInviteTokenHash(string $hash): ?array
    {
        return $this->unscoped()->where('invite_token_hash', '=', $hash)->first();
    }

    public function touchActivity(int $userId, int $organisationId): void
    {
        $this->unscoped()
            ->where('user_id', '=', $userId)
            ->where('organisation_id', '=', $organisationId)
            ->update(['last_active_at' => $this->now()]);
    }
}
