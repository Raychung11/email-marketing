<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * The single source of truth for "which organisation is this request acting on".
 *
 * It is populated by TenantMiddleware from the authenticated session's verified
 * membership — never from a URL segment, query parameter or form field. Every
 * tenant-scoped repository reads the id from here, so a cross-tenant query is
 * not something a caller can request by passing the wrong argument.
 */
final class TenantContext
{
    private ?int $organisationId = null;

    private ?int $workspaceId = null;

    /** @var array<string,mixed>|null */
    private ?array $organisation = null;

    public function bind(int $organisationId, ?int $workspaceId = null, ?array $organisation = null): void
    {
        if ($organisationId <= 0) {
            throw new RuntimeException('Refusing to bind an invalid organisation id.');
        }

        $this->organisationId = $organisationId;
        $this->workspaceId    = $workspaceId;
        $this->organisation   = $organisation;
    }

    public function clear(): void
    {
        $this->organisationId = null;
        $this->workspaceId    = null;
        $this->organisation   = null;
    }

    public function isBound(): bool
    {
        return $this->organisationId !== null;
    }

    public function organisationId(): int
    {
        if ($this->organisationId === null) {
            throw new RuntimeException(
                'No organisation is bound to this request. A tenant-scoped query was attempted '
                . 'outside of TenantMiddleware — this is a bug, not a permission error.'
            );
        }

        return $this->organisationId;
    }

    public function workspaceId(): ?int
    {
        return $this->workspaceId;
    }

    /** @return array<string,mixed> */
    public function organisation(): array
    {
        return $this->organisation ?? [];
    }

    public function timezone(): string
    {
        $timezone = $this->organisation['timezone'] ?? 'UTC';

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    public function currency(): string
    {
        $currency = $this->organisation['currency'] ?? 'USD';

        return is_string($currency) && $currency !== '' ? $currency : 'USD';
    }

    public function country(): string
    {
        $country = $this->organisation['country'] ?? 'US';

        return is_string($country) && $country !== '' ? $country : 'US';
    }

    /**
     * Capture the current binding so a cross-tenant operation can put it back.
     *
     * The scheduler and the queue worker walk several organisations in one
     * process, binding and clearing as they go. Without this they would leave the
     * caller with no tenant bound, which turns an unrelated later query into a
     * confusing "no organisation is bound" rather than doing the right thing.
     *
     * @return array{organisation_id:int,workspace_id:?int,organisation:array<string,mixed>|null}|null
     */
    public function capture(): ?array
    {
        if ($this->organisationId === null) {
            return null;
        }

        return [
            'organisation_id' => $this->organisationId,
            'workspace_id'    => $this->workspaceId,
            'organisation'    => $this->organisation,
        ];
    }

    /** @param array{organisation_id:int,workspace_id:?int,organisation:array<string,mixed>|null}|null $captured */
    public function restore(?array $captured): void
    {
        $this->clear();

        if ($captured === null) {
            return;
        }

        $this->bind(
            (int) $captured['organisation_id'],
            $captured['workspace_id'],
            $captured['organisation']
        );
    }

    /** @param array<string,mixed> $organisation */
    public function refresh(array $organisation): void
    {
        $this->organisation = $organisation;
    }
}
