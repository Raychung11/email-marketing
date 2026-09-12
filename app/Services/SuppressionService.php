<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Repositories\ContactRepository;
use App\Repositories\SuppressionRepository;

/**
 * Suppression outranks everything.
 *
 * There are exactly two ways a suppression is ever cleared, and both are audited:
 *   1. {@see remove()} — a deliberate action on the compliance screen, behind the
 *      compliance.manage permission.
 *   2. {@see clearOnContactReaffirmation()} — the contact themselves re-subscribing
 *      through a signed link in their own email, and only for an `unsubscribe`
 *      suppression.
 *
 * Nothing in the import pipeline, the API, the automation engine or the AI layer
 * can reach either one.
 */
final class SuppressionService
{
    public function __construct(
        private readonly SuppressionRepository $suppressions,
        private readonly ContactRepository $contacts,
        private readonly AuditService $audit,
        private readonly Clock $clock,
    ) {
    }

    public function isSuppressed(string $email): bool
    {
        return $this->suppressions->isSuppressed($email);
    }

    /** @return array<string,mixed>|null */
    public function find(string $email): ?array
    {
        return $this->suppressions->findActive($email);
    }

    /** @param array<string,mixed> $context */
    public function suppress(string $email, string $reason, array $context = []): int
    {
        $id = $this->suppressions->suppress($email, $reason, $context);

        // Keep the contact list-view mirror in step for every contact sharing the
        // address (there is only one per organisation, but this is keyed on the
        // address exactly as suppression is).
        $this->contacts->setSuppressionCacheByEmail(normalize_email($email), true);

        $this->audit->log('contact_suppressed', 'suppression', $id, null, [
            'email'  => \App\Support\Str::maskEmail($email),
            'reason' => $reason,
            'source' => $context['source'] ?? null,
        ]);

        return $id;
    }

    /** Unsubscribe path. Always creates a suppression row. */
    public function suppressForUnsubscribe(
        string $email,
        ?int $campaignId = null,
        ?int $messageId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): int {
        return $this->suppress($email, 'unsubscribe', [
            'source'           => 'user',
            'campaign_id'      => $campaignId,
            'email_message_id' => $messageId,
            'ip_address'       => $ip,
            'user_agent'       => $userAgent,
        ]);
    }

    /**
     * Permanent bounce. Soft/transient bounces must NOT come through here — they
     * are counted and escalated separately.
     */
    public function suppressForHardBounce(
        string $email,
        string $provider,
        ?int $campaignId = null,
        ?int $messageId = null,
        ?string $detail = null,
    ): int {
        return $this->suppress($email, 'hard_bounce', [
            'source'           => 'provider',
            'provider'         => $provider,
            'campaign_id'      => $campaignId,
            'email_message_id' => $messageId,
            'detail'           => $detail,
        ]);
    }

    /** A complaint suppresses immediately: there is no threshold to reach. */
    public function suppressForComplaint(
        string $email,
        string $provider,
        ?int $campaignId = null,
        ?int $messageId = null,
        ?string $detail = null,
    ): int {
        return $this->suppress($email, 'complaint', [
            'source'           => 'provider',
            'provider'         => $provider,
            'campaign_id'      => $campaignId,
            'email_message_id' => $messageId,
            'detail'           => $detail,
        ]);
    }

    public function suppressManually(string $email, int $userId, ?string $detail = null): int
    {
        return $this->suppress($email, 'manual', [
            'source'             => 'user',
            'created_by_user_id' => $userId,
            'detail'             => $detail,
        ]);
    }

    /**
     * Removing a suppression is a deliberate, permission-gated, audited act.
     *
     * It exists because a contact can legitimately re-subscribe through a
     * verified opt-in form, and because a mistaken manual suppression needs an
     * undo. It is never triggered by an import, an API write or a merge.
     */
    public function remove(int $suppressionId, int $userId, string $reason): bool
    {
        $record = $this->suppressions->find($suppressionId);

        if ($record === null) {
            return false;
        }

        $this->suppressions->remove($suppressionId, $userId, $reason);
        $this->contacts->setSuppressionCacheByEmail((string) $record['email_normalized'], false);

        $this->audit->log('suppression_removed', 'suppression', $suppressionId, $record, [
            'reason'     => $reason,
            'removed_by' => $userId,
        ]);

        return true;
    }

    /**
     * The one automatic path that clears a suppression.
     *
     * A contact who re-affirms consent through a link in their own email (the
     * preference centre, or a verified opt-in form) has demonstrably asked to be
     * mailed again. Only an `unsubscribe` suppression is cleared: a hard bounce,
     * a complaint, a legal hold or an admin block are NOT the contact's to undo,
     * and are left in place.
     *
     * Returns true only when a suppression was actually cleared.
     */
    public function clearOnContactReaffirmation(string $email, string $via): bool
    {
        $record = $this->suppressions->findActive($email);

        if ($record === null) {
            return false;
        }

        if ((string) $record['reason'] !== 'unsubscribe') {
            return false;
        }

        $this->suppressions->remove((int) $record['id'], null, 'Contact re-affirmed consent via ' . $via);
        $this->contacts->setSuppressionCacheByEmail((string) $record['email_normalized'], false);

        $this->audit->log('suppression_removed', 'suppression', (int) $record['id'], $record, [
            'reason'     => 'contact_reaffirmed_consent',
            'via'        => $via,
            'actor'      => 'contact',
        ]);

        return true;
    }

    /**
     * @param array<int,string> $emails
     * @return array<string,string> normalized email => suppression reason
     */
    public function suppressedAmong(array $emails): array
    {
        return $this->suppressions->suppressedAmong($emails);
    }

    /** @return array<string,int> */
    public function reasonCounts(): array
    {
        return $this->suppressions->reasonCounts();
    }

    public function activeCount(): int
    {
        return $this->suppressions->activeCount();
    }

    /** @param array{search?:string,reason?:string} $filters */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $query = $this->suppressions->filtered($filters);
        $total = (clone $query)->count();

        return [
            'rows'     => $query->forPage($page, $perPage)->get(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / max(1, $perPage)),
        ];
    }
}
