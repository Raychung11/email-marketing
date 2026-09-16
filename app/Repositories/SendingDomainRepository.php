<?php

declare(strict_types=1);

namespace App\Repositories;

final class SendingDomainRepository extends Repository
{
    protected function table(): string
    {
        return 'sending_domains';
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->scoped()->orderBy('domain')->get();

        foreach ($rows as $index => $row) {
            $rows[$index]['dns_records'] = $this->decodeJson($row['dns_records'] ?? null) ?? [];
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function findByDomain(string $domain): ?array
    {
        $row = $this->scoped()->where('domain', '=', strtolower($domain))->first();

        if ($row !== null) {
            $row['dns_records'] = $this->decodeJson($row['dns_records'] ?? null) ?? [];
        }

        return $row;
    }

    /** @return array<string,mixed> */
    public function findOrFailWithRecords(int $id): array
    {
        $row                = $this->findOrFail($id);
        $row['dns_records'] = $this->decodeJson($row['dns_records'] ?? null) ?? [];

        return $row;
    }

    /** @param array<string,mixed> $attributes */
    public function create(string $domain, array $attributes = []): int
    {
        if (isset($attributes['dns_records'])) {
            $attributes['dns_records'] = $this->encodeJson($attributes['dns_records']);
        }

        return $this->scoped()->insert($this->withTimestamps($this->withTenant(array_merge([
            'domain' => strtolower($domain),
            'status' => 'pending',
        ], $attributes))));
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        if (isset($attributes['dns_records'])) {
            $attributes['dns_records'] = $this->encodeJson($attributes['dns_records']);
        }

        return $this->scoped()->where('id', '=', $id)->update($this->withTimestamps($attributes, false));
    }

    public function delete(int $id): int
    {
        return $this->scoped()->where('id', '=', $id)->delete();
    }

    public function hasVerified(): bool
    {
        return $this->scoped()->where('status', '=', 'verified')->exists();
    }

    /** @return array<int,string> */
    public function verifiedDomains(): array
    {
        return array_map(
            static fn (mixed $value): string => (string) $value,
            $this->scoped()->where('status', '=', 'verified')->pluck('domain')
        );
    }

    /**
     * Is this from-address on a domain this organisation has verified?
     *
     * The campaign validator asks this; nothing sends from an unverified domain.
     */
    public function canSendFrom(string $email): bool
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($email, $at + 1));
        $record = $this->findByDomain($domain);

        return $record !== null && (string) $record['status'] === 'verified';
    }

    /**
     * Domains due a re-check, oldest first. The scheduler walks these so a
     * customer who publishes their DNS records overnight wakes up verified
     * without having to press a button.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueForRecheck(string $before, int $limit = 25): array
    {
        return $this->connection->select(
            "SELECT * FROM sending_domains
             WHERE status IN ('pending', 'failed')
               AND (last_checked_at IS NULL OR last_checked_at < ?)
             ORDER BY last_checked_at IS NULL DESC, last_checked_at ASC
             LIMIT " . max(1, $limit),
            [$before]
        );
    }

    /** @return array<int,string> */
    public function trackingDomains(): array
    {
        return array_map(
            static fn (mixed $value): string => (string) $value,
            $this->scoped()
                ->whereNotNull('tracking_domain')
                ->where('tracking_domain_status', '=', 'verified')
                ->pluck('tracking_domain')
        );
    }
}
