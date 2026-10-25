<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Database\Connection;
use App\Support\Str;

/**
 * A read-only account of what the send pipeline cannot explain.
 *
 * Strictly a report. Nothing here writes, retries, requeues or resends, and
 * that is not timidity — every finding below is a case where the right answer
 * depends on something only a person can establish, usually by opening the
 * provider's own console. An automatic repair would be guessing, and the way
 * guessing shows up here is a customer receiving the same email twice.
 *
 * Addresses are masked by default. A diagnostic that nobody can run without
 * exporting a customer list is a diagnostic nobody runs.
 */
final class SendDoctor
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<int,array{
     *     key:string,title:string,count:int,severity:string,
     *     explanation:string,action:string,rows:array<int,array<string,mixed>>
     * }>
     */
    public function report(int $sampleSize = 10, bool $unmask = false): array
    {
        $lease   = (int) $this->config->get('queue.claim_lease_seconds', 300);
        $now     = $this->clock->nowString();
        $stale   = $this->clock->now()->modify('-' . max(60, $lease) . ' seconds')->format('Y-m-d H:i:s');
        // Long enough that a recipient this old is not simply waiting its turn:
        // the whole backoff schedule plus a lease.
        $orphan  = $this->clock->now()
            ->modify('-' . (array_sum((array) $this->config->get('queue.retry_backoff', [60, 300, 1800, 7200])) + $lease) . ' seconds')
            ->format('Y-m-d H:i:s');

        return array_values(array_filter([
            $this->finding(
                'outcome_unknown',
                'Sends whose outcome nobody knows',
                'critical',
                'A request went to the provider and the answer never came back — a timeout, a '
                . 'worker killed mid-call, or an acceptance we failed to write down. The message '
                . 'may or may not have been delivered.',
                'For each one, search the provider console for the message id or the recipient '
                . 'address around the time shown. Found and delivered: mark the recipient sent. '
                . 'No trace at all: mark it pending so it is sent again. Never resend in bulk '
                . 'without checking — that is the one action that cannot be undone.',
                "SELECT r.id, r.campaign_id, r.email, r.attempt_count, r.claimed_at, r.last_error
                 FROM campaign_recipients r
                 WHERE r.send_status = 'outcome_unknown'
                 ORDER BY r.claimed_at DESC",
                [],
                $sampleSize,
                $unmask
            ),

            $this->finding(
                'stuck_sending',
                'Claims held past their lease',
                'warning',
                'A worker took these out and never came back. The scheduler reclaims them on its '
                . 'next tick, so a handful at any moment is normal; a persistent population means '
                . 'the scheduler is not running.',
                'Check the scheduler heartbeat under storage/framework. If it is current and these '
                . 'are not clearing, look for a worker that is being killed by a memory or process '
                . 'limit mid-send.',
                "SELECT id, campaign_id, email, attempt_count, claimed_at, claim_expires_at,
                        provider_attempted_at
                 FROM campaign_recipients
                 WHERE send_status = 'sending' AND claim_expires_at < ?
                 ORDER BY claim_expires_at",
                [$now],
                $sampleSize,
                $unmask
            ),

            $this->finding(
                'orphaned_queued',
                'Queued with nothing left to run them',
                'warning',
                'These were enqueued long enough ago that every retry would have been exhausted. '
                . 'Usually their job was dead-lettered, or the queue was cleared while they sat on '
                . 'it. Nothing will pick them up: the dispatcher only looks at pending rows.',
                'Check failed_jobs for the same campaign first — if the job failed for a reason '
                . 'that still applies, requeueing will only reproduce it. Otherwise resuming the '
                . 'campaign puts them back in the pool.',
                "SELECT id, campaign_id, email, attempt_count, queued_at
                 FROM campaign_recipients
                 WHERE send_status = 'queued' AND queued_at IS NOT NULL AND queued_at < ?
                 ORDER BY queued_at",
                [$orphan],
                $sampleSize,
                $unmask
            ),

            $this->finding(
                'sent_without_message',
                'Marked sent with no message behind it',
                'critical',
                'The recipient says the email went, and there is no email_messages row to show '
                . 'for it. Rows written by the version of this application that marked recipients '
                . 'sent before calling the provider look exactly like this, so on an upgraded '
                . 'database these are historic and not necessarily lost mail.',
                'These are not safe to resend automatically. Check the provider\'s own send log '
                . 'for the period the campaign ran before deciding; a bulk resend here mails '
                . 'people who already heard from you.',
                "SELECT r.id, r.campaign_id, r.email, r.sent_at
                 FROM campaign_recipients r
                 LEFT JOIN email_messages m ON m.id = r.email_message_id
                 WHERE r.send_status = 'sent' AND (r.email_message_id IS NULL OR m.id IS NULL)
                 ORDER BY r.sent_at DESC",
                [],
                $sampleSize,
                $unmask
            ),

            $this->finding(
                'sent_message_never_left',
                'Sent, but the message row says otherwise',
                'warning',
                'The recipient is sent and the message it points at was never accepted by the '
                . 'provider. The two disagree, and the campaign report is built from the message '
                . 'rows, so the campaign is under-reporting rather than over-reporting.',
                'Read the failure reason on the message row. It is usually a provider rejection '
                . 'that arrived after the recipient had been closed out.',
                "SELECT r.id, r.campaign_id, r.email, m.status AS message_status, m.failure_reason
                 FROM campaign_recipients r
                 JOIN email_messages m ON m.id = r.email_message_id
                 WHERE r.send_status = 'sent' AND m.sent_at IS NULL
                 ORDER BY r.sent_at DESC",
                [],
                $sampleSize,
                $unmask
            ),

            $this->finding(
                'dead_lettered_sends',
                'Dead-lettered send jobs whose recipient is still open',
                'warning',
                'The job gave up, and the recipient it was for is still waiting to be sent. '
                . 'Left alone these are owed an email that nothing will ever produce.',
                'Read the exception on the failed_jobs row. Fix what it names, then resume the '
                . 'campaign to put its outstanding recipients back in the pool.',
                "SELECT f.id, f.organisation_id, f.attempt_count, f.failed_at,
                        SUBSTR(f.exception, 1, 120) AS exception
                 FROM failed_jobs f
                 WHERE f.job_class LIKE '%SendCampaignEmail'
                 ORDER BY f.failed_at DESC",
                [],
                $sampleSize,
                $unmask
            ),

            $this->finding(
                'stuck_campaigns',
                'Campaigns that have been sending for a long time',
                'warning',
                'Still in `sending` well after they started. Either the workers are not draining '
                . 'the queue, or the campaign is held open by recipients in one of the states '
                . 'above — which is deliberate: a campaign is not complete while somebody in its '
                . 'audience is unaccounted for.',
                'Resolve the findings above first; most stuck campaigns close themselves on the '
                . 'next scheduler tick once their recipients are accounted for.',
                "SELECT c.id, c.organisation_id, c.name, c.send_started_at,
                        (SELECT COUNT(*) FROM campaign_recipients r
                          WHERE r.campaign_id = c.id
                            AND r.send_status IN ('pending', 'queued', 'sending', 'outcome_unknown')
                        ) AS outstanding
                 FROM campaigns c
                 WHERE c.status = 'sending' AND c.send_started_at IS NOT NULL AND c.send_started_at < ?
                 ORDER BY c.send_started_at",
                [$this->clock->now()->modify('-6 hours')->format('Y-m-d H:i:s')],
                $sampleSize,
                $unmask
            ),
        ], static fn (array $finding): bool => $finding['count'] > 0));
    }

    /**
     * @param  array<int,mixed> $bindings
     * @return array{key:string,title:string,count:int,severity:string,explanation:string,action:string,rows:array<int,array<string,mixed>>}
     */
    private function finding(
        string $key,
        string $title,
        string $severity,
        string $explanation,
        string $action,
        string $sql,
        array $bindings,
        int $sampleSize,
        bool $unmask,
    ): array {
        $rows  = $this->connection->select($sql . ' LIMIT ' . max(1, $sampleSize), $bindings);
        $total = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM (' . $sql . ') AS findings',
            $bindings
        );

        return [
            'key'         => $key,
            'title'       => $title,
            'count'       => $total,
            'severity'    => $severity,
            'explanation' => $explanation,
            'action'      => $action,
            'rows'        => array_map(
                static function (array $row) use ($unmask): array {
                    if (!$unmask && isset($row['email'])) {
                        $row['email'] = Str::maskEmail((string) $row['email']);
                    }

                    return $row;
                },
                $rows
            ),
        ];
    }
}
