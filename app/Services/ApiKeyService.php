<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Database\Connection;
use App\Support\TenantContext;

/**
 * API keys.
 *
 * The plaintext key is shown exactly once, at creation. Only a hash is stored, so
 * a database dump does not hand over API access. Keys carry scopes, a rate limit
 * and an optional IP allowlist.
 */
final class ApiKeyService
{
    private const PREFIX = 'aigh_';

    public function __construct(
        private readonly Connection $connection,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int,string> */
    public function availableScopes(): array
    {
        return [
            'contacts:read', 'contacts:write',
            'lists:read', 'lists:write',
            'segments:read',
            'events:write',
            'conversions:write',
            'campaigns:read', 'campaigns:write',
            'suppressions:read', 'suppressions:write',
        ];
    }

    /**
     * @param array<int,string> $scopes
     * @return array{id:int,key:string} the plaintext key is returned once and never stored
     */
    public function create(string $name, array $scopes, ?int $userId = null, ?string $allowedIps = null): array
    {
        $scopes = array_values(array_intersect($scopes, $this->availableScopes()));

        if ($scopes === []) {
            throw new \App\Core\ValidationException(['scopes' => ['Select at least one scope.']]);
        }

        $secret = self::PREFIX . bin2hex(random_bytes(24));

        $id = $this->connection->table('api_keys')->insert([
            'organisation_id'       => $this->tenant->organisationId(),
            'name'                  => $name,
            // Displayed in the UI so a key can be recognised without revealing it.
            'key_prefix'            => substr($secret, 0, (int) $this->config->get('security.api.key_prefix_len', 8)),
            'key_hash'              => hash('sha256', $secret),
            'scopes'                => json_encode($scopes, JSON_UNESCAPED_SLASHES),
            'rate_limit_per_minute' => (int) $this->config->get('security.api.rate_limit', 120),
            'allowed_ips'           => $allowedIps,
            'created_by_user_id'    => $userId,
            'created_at'            => $this->clock->nowString(),
            'updated_at'            => $this->clock->nowString(),
        ]);

        $this->audit->log('api_key_created', 'api_key', $id, null, ['name' => $name, 'scopes' => $scopes]);

        return ['id' => $id, 'key' => $secret];
    }

    /**
     * Resolve a presented key.
     *
     * Runs outside a bound tenant — the organisation is a *result* of
     * authentication, never an input to it.
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $presented): ?array
    {
        if ($presented === '') {
            return null;
        }

        $row = $this->connection->table('api_keys')
            ->where('key_hash', '=', hash('sha256', $presented))
            ->whereNull('revoked_at')
            ->first();

        if ($row === null) {
            return null;
        }

        $expiresAt = (string) ($row['expires_at'] ?? '');

        if ($expiresAt !== '' && $expiresAt < $this->clock->nowString()) {
            return null;
        }

        $scopes        = json_decode((string) $row['scopes'], true);
        $row['scopes'] = is_array($scopes) ? $scopes : [];

        return $row;
    }

    /** @param array<string,mixed> $key */
    public function hasScope(array $key, string $scope): bool
    {
        /** @var array<int,string> $scopes */
        $scopes = $key['scopes'] ?? [];

        return in_array($scope, $scopes, true);
    }

    /** @param array<string,mixed> $key */
    public function ipAllowed(array $key, string $ip): bool
    {
        $allowed = trim((string) ($key['allowed_ips'] ?? ''));

        if ($allowed === '') {
            return true;
        }

        foreach (array_map('trim', explode(',', $allowed)) as $candidate) {
            if ($candidate === $ip) {
                return true;
            }
        }

        return false;
    }

    public function recordUse(int $keyId, string $ip): void
    {
        $this->connection->execute(
            'UPDATE api_keys SET last_used_at = ?, last_used_ip = ?, request_count = request_count + 1
             WHERE id = ?',
            [$this->clock->nowString(), $ip, $keyId]
        );
    }

    public function revoke(int $keyId): bool
    {
        $updated = $this->connection->table('api_keys')
            ->where('id', '=', $keyId)
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->update(['revoked_at' => $this->clock->nowString()]);

        if ($updated > 0) {
            $this->audit->log('api_key_revoked', 'api_key', $keyId);
        }

        return $updated > 0;
    }

    /**
     * Revoke every live key this organisation has, and return how many.
     *
     * The deliberate, visible counterpart to the session revocation a password
     * reset performs. A reset does NOT touch API keys: they are separate
     * credentials, and silently breaking a customer's integration — where the
     * only symptom is a 401 nobody can explain — is not something to do on an
     * inference about what the person was worried about. So it is a button they
     * press, having read what it does.
     *
     * One conditional UPDATE guarded on `revoked_at IS NULL`, so a key revoked
     * a moment ago by somebody else keeps its original timestamp rather than
     * having this one written over it.
     *
     * The audit record keeps the key ids and names, which identify a key
     * without being a credential. Never the hash: that IS the credential, as
     * far as anybody holding a database dump is concerned.
     */
    public function revokeAllForOrganisation(): int
    {
        $live = $this->connection->table('api_keys')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->whereNull('revoked_at')
            ->get();

        if ($live === []) {
            return 0;
        }

        $revoked = $this->connection->table('api_keys')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $this->clock->nowString()]);

        if ($revoked > 0) {
            $this->audit->log('api_keys_revoked_all', 'api_key', null, null, [
                'count' => $revoked,
                'keys'  => array_map(
                    static fn (array $row): array => [
                        'id'   => (int) $row['id'],
                        'name' => (string) $row['name'],
                    ],
                    $live
                ),
            ]);
        }

        return $revoked;
    }

    /** How many keys would still authenticate a request right now. */
    public function activeCount(): int
    {
        $now = $this->clock->nowString();

        return $this->connection->table('api_keys')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->whereNull('revoked_at')
            // An expired key is already refused by resolve(), so counting it as
            // active would overstate what revoking achieves.
            ->whereGroup(static function ($query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->count();
    }

    /** @return array<int,array<string,mixed>> */
    public function forOrganisation(): array
    {
        $rows = $this->connection->table('api_keys')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($rows as $index => $row) {
            $scopes                  = json_decode((string) $row['scopes'], true);
            $rows[$index]['scopes'] = is_array($scopes) ? $scopes : [];
        }

        return $rows;
    }
}
