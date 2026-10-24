<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Database\Connection;
use App\Jobs\SendCampaignEmail;
use App\Queue\QueueManager;
use App\Support\Heartbeat;
use App\Support\Str;

/**
 * Read-only reconciliation report for the send pipeline.
 *
 * STRICTLY READ-ONLY, and that is the point rather than a limitation. The
 * states this reports on are states where sending again may put a second copy
 * of an email in somebody's inbox. Nothing here may decide that; a person has
 * to, with the provider's own records in front of them. A command that offered
 * to "fix" an `outcome_unknown` row would be offering to guess.
 *
 * It also reports on the aftermath of the bug the reliability migration fixes:
 * recipients that were marked `sent` before anything had been sent. The
 * migration deliberately does not rewrite those rows, because it cannot tell
 * which of them were real. This is how they are found.
 *
 * WHAT GETS PRINTED. Email addresses are masked, always, with no flag to turn
 * that off. The row id is printed instead, which is what somebody with the
 * authority to look at an address can use to do so through the application. A
 * diagnostic that dumps a tenant's address list to a terminal, a CI log or a
 * support ticket is a data incident waiting for somebody to paste it.
 *
 * Every count is organisation-aware but the report is cross-tenant: it runs
 * from the console, where there is no signed-in user and therefore no tenant to
 * scope to. Queries name organisation_id explicitly rather than relying on the
 * repositories' scoping, precisely because there is nothing bound.
 */
final class SendDiagnostics
{
    /** Rows listed per finding before the report stops enumerating. */
    private const SAMPLE = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly QueueManager $queue,
        private readonly Heartbeat $heartbeat,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     generated_at:string,
     *     timers:array<string,mixed>,
     *     queue:array<string,mixed>,
     *     findings:array<int,array<string,mixed>>
     * }
     */
    public function report(): array
    {
        return [
            'generated_at' => $this->clock->nowString(),
            'timers'       => $this->timers(),
            'queue'        => $this->queueDepth(),
            'findings'     => array_values(array_filter([
                $this->sentWithoutAMessage(),
                $this->awaitingReconciliation(),
                $this->lapsedLeases(),
                $this->orphanedCommitments(),
                $this->readyButNotEnqueued(),
                $this->pastTheAttemptCeiling(),
                $this->abandonedJobs(),
                $this->stalledCampaigns(),
                $this->staleRateLimits(),
            ])),
        ];
    }

    /**
     * When the two things that drive the pipeline last ran.
     *
     * First, because almost every finding below means something different
     * depending on the answer. Recipients stuck in `queued` with a worker that
     * last ran four hours ago is a worker problem; the same rows with a healthy
     * worker is a real inconsistency.
     *
     * @return array<string,mixed>
     */
    private function timers(): array
    {
        $now = $this->clock->timestamp();

        return [
            'scheduler_seconds_ago' => $this->heartbeat->secondsSince('scheduler', $now),
            'worker_seconds_ago'    => $this->heartbeat->secondsSince('worker', $now),
        ];
    }

    /**
     * Commitments against jobs, as two numbers rather than a join.
     *
     * Matching a recipient to its job means reading inside a JSON payload,
     * which is neither portable nor indexed. These two counts answer the same
     * question for a fraction of the cost: every `queued` recipient should have
     * a job, so a queue far shorter than the commitment count means jobs have
     * been lost — the recipients will wait for ever, because the dispatcher only
     * ever re-queues `pending`.
     *
     * Approximate on purpose. The queue also carries retries and other job
     * classes, and a worker holds a reserved row while it works, so small
     * differences are normal and the number to react to is a large one.
     *
     * @return array<string,mixed>
     */
    private function queueDepth(): array
    {
        $queueName = (string) $this->config->get('queue.campaign_queue', 'email_marketing');

        $pending = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM jobs WHERE queue = ? AND job_class = ? AND reserved_at IS NULL',
            [$queueName, SendCampaignEmail::class]
        );

        $reserved = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM jobs WHERE queue = ? AND job_class = ? AND reserved_at IS NOT NULL',
            [$queueName, SendCampaignEmail::class]
        );

        $committed = (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM campaign_recipients WHERE send_status = 'queued'"
        );

        return [
            'queue'              => $queueName,
            'jobs_pending'       => $pending,
            'jobs_reserved'      => $reserved,
            'recipients_queued'  => $committed,
            'shortfall'          => max(0, $committed - ($pending + $reserved)),
            'retry_queue_depth'  => (int) $this->connection->scalar(
                'SELECT COUNT(*) FROM jobs WHERE queue = ?',
                [(string) $this->config->get('queue.retry_queue', 'email_retry')]
            ),
            'failed_jobs'        => (int) $this->connection->scalar('SELECT COUNT(*) FROM failed_jobs'),
        ];
    }

    // ------------------------------------------------------------- findings

    /**
     * Rows that say `sent` but point at no message. The old bug's fingerprint.
     *
     * claimForSending() used to write `sent` before rendering, before the
     * message row and before the provider had been called. Any row that threw
     * in between is still sitting there claiming an email was sent that nobody
     * tried to send. The migration will not guess which, and nothing may resend
     * them automatically — a real send looks identical from here.
     *
     * @return array<string,mixed>|null
     */
    private function sentWithoutAMessage(): ?array
    {
        $rows = $this->connection->select(
            "SELECT id, organisation_id, campaign_id, email, sent_at
               FROM campaign_recipients
              WHERE send_status = 'sent' AND email_message_id IS NULL
              ORDER BY id
              LIMIT " . (self::SAMPLE + 1)
        );

        if ($rows === []) {
            return null;
        }

        return $this->finding(
            'sent_without_a_message',
            'Recipients marked sent with no message record',
            (int) $this->connection->scalar(
                "SELECT COUNT(*) FROM campaign_recipients
                  WHERE send_status = 'sent' AND email_message_id IS NULL"
            ),
            $rows,
            'These predate the reliability fix: the row was marked sent before the provider was '
            . 'called, so some of them were never sent and some were. Check each against the '
            . 'provider\'s own log before resending anything. Nothing in the application will '
            . 'touch them.'
        );
    }

    /**
     * The ones only a person can settle.
     *
     * The request left this process and the answer never came back. The message
     * may be in somebody's inbox and it may not, and nothing here can tell.
     * Each row holds its campaign open, which is the honest outcome: the
     * campaign is not finished, because nobody knows whether it is.
     *
     * @return array<string,mixed>|null
     */
    private function awaitingReconciliation(): ?array
    {
        $rows = $this->connection->select(
            "SELECT id, organisation_id, campaign_id, email, provider_attempted_at, last_error
               FROM campaign_recipients
              WHERE send_status = 'outcome_unknown'
              ORDER BY provider_attempted_at
              LIMIT " . (self::SAMPLE + 1)
        );

        if ($rows === []) {
            return null;
        }

        return $this->finding(
            'awaiting_reconciliation',
            'Sends whose outcome is unknown',
            (int) $this->connection->scalar(
                "SELECT COUNT(*) FROM campaign_recipients WHERE send_status = 'outcome_unknown'"
            ),
            $rows,
            'Look each one up in the provider\'s log by the time in provider_attempted_at. '
            . 'If it was accepted, set the row to sent; if not, set it to pending and it will '
            . 'be picked up on the next tick. Until then the campaign stays open, which is '
            . 'correct rather than stuck.'
        );
    }

    /**
     * Leases the reaper should already have dealt with.
     *
     * The dispatcher sweeps these every tick, so finding any at all means the
     * scheduler is not running — check the timers at the top of the report
     * before reading anything into the rows themselves.
     *
     * @return array<string,mixed>|null
     */
    private function lapsedLeases(): ?array
    {
        $now = $this->clock->nowString();

        $rows = $this->connection->select(
            "SELECT id, organisation_id, campaign_id, email, claimed_at, lease_expires_at,
                    provider_attempted_at
               FROM campaign_recipients
              WHERE send_status = 'sending' AND lease_expires_at IS NOT NULL AND lease_expires_at <= ?
              ORDER BY lease_expires_at
              LIMIT " . (self::SAMPLE + 1),
            [$now]
        );

        if ($rows === []) {
            return null;
        }

        return $this->finding(
            'lapsed_leases',
            'Recipients held by a worker that is not coming back',
            (int) $this->connection->scalar(
                "SELECT COUNT(*) FROM campaign_recipients
                  WHERE send_status = 'sending' AND lease_expires_at IS NOT NULL AND lease_expires_at <= ?",
                [$now]
            ),
            $rows,
            'The scheduler sweeps these on every campaign dispatch tick, so any here at all '
            . 'means it has not run. Fix the scheduler and they clear themselves: a null '
            . 'provider_attempted_at goes back on the queue, anything else is held for a person.'
        );
    }

    /**
     * `queued` work on a campaign that is no longer going anywhere.
     *
     * The dispatcher only ever re-queues `pending`. A `queued` row on a campaign
     * that is paused, cancelled or already completed is work nothing will pick
     * up and nobody will be told about — the exact shape of the lost sends the
     * pause fix addresses, and worth checking for on historical data.
     *
     * @return array<string,mixed>|null
     */
    private function orphanedCommitments(): ?array
    {
        $sql = "FROM campaign_recipients r
                JOIN campaigns c ON c.id = r.campaign_id
               WHERE r.send_status = 'queued'
                 AND c.status NOT IN ('scheduled', 'sending')";

        $rows = $this->connection->select(
            'SELECT r.id, r.organisation_id, r.campaign_id, r.email, c.status AS campaign_status, r.queued_at '
            . $sql . ' ORDER BY r.id LIMIT ' . (self::SAMPLE + 1)
        );

        if ($rows === []) {
            return null;
        }

        return $this->finding(
            'orphaned_commitments',
            'Queued recipients on campaigns that are not sending',
            (int) $this->connection->scalar('SELECT COUNT(*) ' . $sql),
            $rows,
            'Nothing looks at queued rows except a job that already exists, so if the job is '
            . 'gone these people are never written to and nothing says so. Set them to pending '
            . 'to have the campaign pick them up, or to skipped with a reason if the campaign '
            . 'is finished with them. Deciding which is a person\'s call.'
        );
    }

    /**
     * Work that is ready to go and is not going.
     *
     * `pending`, past any retry delay, on a campaign that is actively sending.
     * The dispatcher should have enqueued these on its last tick, so this is
     * either a stopped scheduler or a tenant that has run out of daily
     * allowance — the first is a fault, the second is the system working.
     *
     * @return array<string,mixed>|null
     */
    private function readyButNotEnqueued(): ?array
    {
        $now = $this->clock->nowString();

        $sql = "FROM campaign_recipients r
                JOIN campaigns c ON c.id = r.campaign_id
               WHERE r.send_status = 'pending'
                 AND r.eligibility_status = 'eligible'
                 AND c.status = 'sending'
                 AND (r.retry_after IS NULL OR r.retry_after <= ?)";

        $total = (int) $this->connection->scalar('SELECT COUNT(*) ' . $sql, [$now]);

        if ($total === 0) {
            return null;
        }

        $rows = $this->connection->select(
            'SELECT r.organisation_id, r.campaign_id, COUNT(*) AS waiting '
            . $sql . ' GROUP BY r.organisation_id, r.campaign_id ORDER BY waiting DESC LIMIT '
            . (self::SAMPLE + 1),
            [$now]
        );

        return $this->finding(
            'ready_but_not_enqueued',
            'Eligible recipients waiting on a dispatch tick',
            $total,
            $rows,
            'Normal in small numbers between ticks, and normal in large numbers for a tenant '
            . 'that has used its daily allowance. Otherwise check the scheduler timer above, '
            . 'and whether the campaign\'s organisation is suspended or has sending paused.'
        );
    }

    /**
     * Recipients at or past the retry ceiling that are still open.
     *
     * The dispatcher closes these out before each scan, so they should not
     * persist. They used to accumulate for ever because every tick created a
     * fresh first-attempt job, which meant the ceiling applied to jobs rather
     * than to recipients.
     *
     * @return array<string,mixed>|null
     */
    private function pastTheAttemptCeiling(): ?array
    {
        $max = $this->queue->maxAttempts();

        $sql = "FROM campaign_recipients
                WHERE send_status IN ('pending', 'queued') AND attempt_count >= ?";

        $total = (int) $this->connection->scalar('SELECT COUNT(*) ' . $sql, [$max]);

        if ($total === 0) {
            return null;
        }

        return $this->finding(
            'past_the_attempt_ceiling',
            'Recipients still open after using every attempt (' . $max . ')',
            $total,
            $this->connection->select(
                'SELECT id, organisation_id, campaign_id, email, attempt_count, last_error '
                . $sql . ' ORDER BY attempt_count DESC LIMIT ' . (self::SAMPLE + 1),
                [$max]
            ),
            'The dispatcher closes these as failed before every scan, so they clear on the '
            . 'next tick. Read last_error first: a column of identical errors is one fault '
            . 'affecting a whole audience, not a hundred separate ones.'
        );
    }

    /**
     * A job gave up while its recipient still looks live.
     *
     * failed_jobs is small, so unlike the queue it is worth decoding exactly
     * rather than comparing counts. A recipient left `pending` or `queued` here
     * will be retried by the dispatcher, which may be right; one left `sending`
     * is holding a lease nobody owns.
     *
     * @return array<string,mixed>|null
     */
    private function abandonedJobs(): ?array
    {
        $failures = $this->connection->select(
            'SELECT id, payload, attempt_count, permanent, failed_at
               FROM failed_jobs
              WHERE job_class = ?
              ORDER BY failed_at DESC
              LIMIT 500',
            [SendCampaignEmail::class]
        );

        if ($failures === []) {
            return null;
        }

        $byRecipient = [];

        foreach ($failures as $failure) {
            $payload = json_decode((string) ($failure['payload'] ?? ''), true);

            if (!is_array($payload) || !isset($payload['recipient_id'])) {
                continue;
            }

            $byRecipient[(int) $payload['recipient_id']] = $failure;
        }

        if ($byRecipient === []) {
            return null;
        }

        $ids         = array_keys($byRecipient);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $live = $this->connection->select(
            "SELECT id, organisation_id, campaign_id, email, send_status, attempt_count
               FROM campaign_recipients
              WHERE id IN ({$placeholders})
                AND send_status IN ('pending', 'queued', 'sending')
              ORDER BY id",
            $ids
        );

        if ($live === []) {
            return null;
        }

        foreach ($live as $index => $row) {
            $failure                        = $byRecipient[(int) $row['id']];
            $live[$index]['failed_at']      = $failure['failed_at'] ?? null;
            $live[$index]['permanent']      = (int) ($failure['permanent'] ?? 0);
        }

        return $this->finding(
            'abandoned_jobs',
            'Recipients whose job is in failed_jobs but which still look live',
            count($live),
            array_slice($live, 0, self::SAMPLE + 1),
            'A row still in sending is the one to deal with first: it holds a lease nobody '
            . 'owns, and the lease sweep will decide its fate from provider_attempted_at. '
            . 'pending and queued rows will be retried by the dispatcher, so check '
            . 'failed_jobs.exception before letting that happen again.'
        );
    }

    /**
     * Campaigns that are sending and are not moving.
     *
     * Outstanding work, but none of it ready: everything left is either held
     * for a person or waiting out a retry delay. Reported because "sending" on
     * the campaign list looks like progress and this is not progress.
     *
     * @return array<string,mixed>|null
     */
    private function stalledCampaigns(): ?array
    {
        $now = $this->clock->nowString();

        $rows = $this->connection->select(
            "SELECT c.id, c.organisation_id, c.name, c.send_started_at,
                    SUM(CASE WHEN r.send_status = 'outcome_unknown' THEN 1 ELSE 0 END) AS unknown,
                    SUM(CASE WHEN r.send_status = 'sending' THEN 1 ELSE 0 END) AS in_flight,
                    SUM(CASE WHEN r.send_status = 'queued' THEN 1 ELSE 0 END) AS queued,
                    SUM(CASE WHEN r.send_status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN r.send_status = 'pending'
                                  AND (r.retry_after IS NULL OR r.retry_after <= ?)
                             THEN 1 ELSE 0 END) AS ready
               FROM campaigns c
               JOIN campaign_recipients r ON r.campaign_id = c.id AND r.eligibility_status = 'eligible'
              WHERE c.status = 'sending'
              GROUP BY c.id, c.organisation_id, c.name, c.send_started_at
             HAVING ready = 0 AND (unknown > 0 OR in_flight > 0 OR queued > 0 OR pending > 0)
              ORDER BY c.send_started_at
              LIMIT " . (self::SAMPLE + 1),
            [$now]
        );

        if ($rows === []) {
            return null;
        }

        return $this->finding(
            'stalled_campaigns',
            'Campaigns that are sending but have nothing ready to send',
            count($rows),
            $rows,
            'Read the columns: unknown means it is waiting for a person (see above), '
            . 'in_flight or queued with an old worker timer means the workers are down, '
            . 'and pending with no ready rows means everything is waiting out a retry delay.'
        );
    }

    /**
     * Rate-limit rows the pruner should have removed.
     *
     * Harmless in itself — expired windows are ignored when read — but a table
     * that only grows says the scheduler's prune is not running, and that is
     * the same scheduler everything above depends on.
     *
     * @return array<string,mixed>|null
     */
    private function staleRateLimits(): ?array
    {
        $expired = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM rate_limits WHERE expires_at <= ?',
            [$this->clock->nowString()]
        );

        if ($expired < 1000) {
            return null;
        }

        return $this->finding(
            'stale_rate_limits',
            'Expired rate-limit windows that have not been pruned',
            $expired,
            [],
            'Expired windows are ignored when read, so nothing is being limited wrongly. '
            . 'But the scheduler prunes these every minute, so this many means it is not '
            . 'running — check the scheduler timer above.'
        );
    }

    // ------------------------------------------------------- reconciliation

    /**
     * Every row somebody has to settle, as a flat list ready for a spreadsheet.
     *
     * The terminal report is for finding out whether there is a problem. This is
     * for doing the work: one row per recipient, with the campaign and the
     * organisation named, so it can be matched against the provider's records
     * or simply judged on what the campaign was.
     *
     * Two sets, distinguished by the `finding` column, because they reconcile
     * differently:
     *
     *   sent_without_a_message  marked sent before the provider was called.
     *                           There is no provider message id for these — by
     *                           definition, since the message row was never
     *                           written — so the only match is address and
     *                           time.
     *   outcome_unknown         the request left and the answer never came
     *                           back. These do have a message row, so
     *                           email_message_id and provider_attempted_at
     *                           narrow the search.
     *
     * Still read-only. Still bounded by nothing: this is the export, so it
     * returns everything rather than a sample.
     *
     * @param  bool $maskAddresses false puts real email addresses in the result
     * @return array<int,array<string,mixed>>
     */
    public function unreconciled(bool $maskAddresses = true): array
    {
        /*
         * The UNION is wrapped rather than ordered directly: SQLite will only
         * ORDER BY a compound select's output column names, and which name an
         * unaliased `r.organisation_id` ends up with is not something to depend
         * on. Ordering the outer query is unambiguous on both drivers.
         */
        $rows = $this->connection->select(
            "SELECT * FROM (
             SELECT 'sent_without_a_message' AS finding,
                    r.id AS recipient_id, r.contact_id, r.email,
                    r.organisation_id, o.name AS organisation,
                    r.campaign_id, c.name AS campaign, c.status AS campaign_status,
                    c.send_started_at, r.sent_at, r.provider_attempted_at,
                    r.email_message_id, r.attempt_count, r.last_error
               FROM campaign_recipients r
               JOIN campaigns c ON c.id = r.campaign_id
               JOIN organisations o ON o.id = r.organisation_id
              WHERE r.send_status = 'sent' AND r.email_message_id IS NULL
              UNION ALL
             SELECT 'outcome_unknown' AS finding,
                    r.id AS recipient_id, r.contact_id, r.email,
                    r.organisation_id, o.name AS organisation,
                    r.campaign_id, c.name AS campaign, c.status AS campaign_status,
                    c.send_started_at, r.sent_at, r.provider_attempted_at,
                    r.email_message_id, r.attempt_count, r.last_error
               FROM campaign_recipients r
               JOIN campaigns c ON c.id = r.campaign_id
               JOIN organisations o ON o.id = r.organisation_id
              WHERE r.send_status = 'outcome_unknown'
             ) AS needs_settling
              ORDER BY organisation_id, campaign_id, recipient_id"
        );

        return $maskAddresses ? array_map([$this, 'mask'], $rows) : $rows;
    }

    // ------------------------------------------------------------ internals

    /**
     * @param  array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function finding(string $key, string $title, int $total, array $rows, string $guidance): array
    {
        $truncated = count($rows) > self::SAMPLE;

        return [
            'key'       => $key,
            'title'     => $title,
            'total'     => $total,
            'rows'      => array_map([$this, 'mask'], array_slice($rows, 0, self::SAMPLE)),
            'truncated' => $truncated,
            'guidance'  => $guidance,
        ];
    }

    /**
     * Mask anything that identifies a person before it reaches a terminal.
     *
     * Applied to the rows on their way out rather than left to each query, so a
     * finding added later cannot forget. The id stays, which is how somebody
     * with the authority to see the address looks it up properly.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mask(array $row): array
    {
        foreach (['email', 'email_normalized'] as $column) {
            if (isset($row[$column]) && is_string($row[$column]) && $row[$column] !== '') {
                $row[$column] = Str::maskEmail($row[$column]);
            }
        }

        return $row;
    }
}
