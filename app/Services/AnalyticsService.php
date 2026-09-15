<?php

declare(strict_types=1);

namespace App\Services;

use App\Compliance\ReasonCode;
use App\Core\Clock;
use App\Core\Config;
use App\Database\Connection;
use App\Repositories\CampaignRecipientRepository;
use App\Support\TenantContext;

/**
 * Reporting.
 *
 * Two opinions run through all of it.
 *
 * FIRST: clicks over opens. An open means an image was requested. Apple Mail
 * Privacy Protection and its equivalents request that image on the recipient's
 * behalf whether or not they ever looked at the message, so a 60% open rate can
 * mean nothing at all. A click required a person, a device and an intent. Opens
 * are still reported — customers expect the number and would distrust a tool
 * that hid it — but they are labelled, and nothing in the product makes a
 * decision on them.
 *
 * SECOND: a rate is useless without the number underneath it. "100% click rate"
 * from four recipients is noise, and showing it next to a real campaign's 3.1%
 * invites exactly the wrong conclusion. Every rate here travels with its
 * denominator so the UI can show both.
 *
 * All the SQL is written to run on MySQL and SQLite alike, because the test
 * suite runs on SQLite and a report that is only exercised in production is a
 * report nobody has checked.
 */
final class AnalyticsService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CampaignRecipientRepository $recipients,
        private readonly LinkTracker $links,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    // ------------------------------------------------------ campaign report

    /**
     * Everything the campaign page needs to explain one send.
     *
     * @return array{funnel:array<string,mixed>,links:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>,daily:array<int,array<string,mixed>>}
     */
    public function campaignReport(int $campaignId): array
    {
        return [
            'funnel'  => $this->campaignFunnel($campaignId),
            'links'   => $this->topLinks($campaignId),
            'skipped' => $this->skipReasons($campaignId),
            'daily'   => $this->campaignActivity($campaignId),
        ];
    }

    /** @return array<string,mixed> */
    public function campaignFunnel(int $campaignId): array
    {
        $row = $this->connection->selectOne(
            "SELECT
                SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'delivered' OR opened_at IS NOT NULL OR clicked_at IS NOT NULL
                         THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                SUM(CASE WHEN status = 'soft_bounced' THEN 1 ELSE 0 END) AS soft_bounced,
                SUM(CASE WHEN status = 'complained' THEN 1 ELSE 0 END) AS complained,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
             FROM email_messages
             WHERE organisation_id = ? AND campaign_id = ?",
            [$this->tenant->organisationId(), $campaignId]
        ) ?? [];

        $unsubscribed = (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM suppressions
             WHERE organisation_id = ? AND campaign_id = ? AND reason = 'unsubscribe'",
            [$this->tenant->organisationId(), $campaignId]
        );

        return $this->rates($row, ['unsubscribed' => $unsubscribed]);
    }

    /**
     * Which links people actually pressed.
     *
     * The most directly useful report in the product: it tells a plumber that
     * nobody pressed "book a service" and forty people pressed the phone number,
     * which is a decision they can act on next week.
     *
     * @return array<int,array<string,mixed>>
     */
    public function topLinks(int $campaignId): array
    {
        $rows = $this->links->linksForCampaign($campaignId);

        $total = 0;

        foreach ($rows as $row) {
            $total += (int) $row['click_count'];
        }

        $links = [];

        foreach ($rows as $row) {
            $clicks = (int) $row['click_count'];

            $links[] = [
                'url'          => (string) $row['original_url'],
                'label'        => (string) ($row['label'] ?? '') ?: $this->describeUrl((string) $row['original_url']),
                'clicks'       => $clicks,
                'people'       => (int) $row['unique_click_count'],
                'share'        => $total > 0 ? round($clicks / $total * 100, 1) : 0.0,
            ];
        }

        return $links;
    }

    /**
     * Why people were left out, in words rather than reason codes.
     *
     * @return array<int,array{reason:string,code:string,count:int}>
     */
    public function skipReasons(int $campaignId): array
    {
        $reasons = [];

        foreach ($this->recipients->skipReasons($campaignId) as $code => $count) {
            $reasons[] = [
                'reason' => ReasonCode::describe($code),
                'code'   => $code,
                'count'  => $count,
            ];
        }

        return $reasons;
    }

    /**
     * Day-by-day engagement for one campaign.
     *
     * Worth a chart because the shape says something: most opens land in the
     * first few hours, and a long tail usually means the send was throttled
     * rather than that people are unusually thoughtful.
     *
     * @return array<int,array{date:string,opens:int,clicks:int}>
     */
    public function campaignActivity(int $campaignId): array
    {
        $rows = $this->connection->select(
            "SELECT SUBSTR(event_at, 1, 10) AS day,
                    SUM(CASE WHEN event_type = 'open' THEN 1 ELSE 0 END) AS opens,
                    SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) AS clicks
             FROM email_events
             WHERE organisation_id = ? AND campaign_id = ?
             GROUP BY SUBSTR(event_at, 1, 10)
             ORDER BY day",
            [$this->tenant->organisationId(), $campaignId]
        );

        return array_map(static fn (array $row): array => [
            'date'   => (string) $row['day'],
            'opens'  => (int) $row['opens'],
            'clicks' => (int) $row['clicks'],
        ], $rows);
    }

    // ------------------------------------------------- campaign comparison

    /**
     * Every campaign side by side.
     *
     * @return array<int,array<string,mixed>>
     */
    public function campaignPerformance(int $days = 90, int $limit = 50): array
    {
        $rows = $this->connection->select(
            "SELECT c.id, c.name, c.subject, c.campaign_type, c.status, c.send_started_at,
                    SUM(CASE WHEN m.sent_at IS NOT NULL THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN m.status = 'delivered' OR m.opened_at IS NOT NULL OR m.clicked_at IS NOT NULL
                             THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN m.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN m.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                    SUM(CASE WHEN m.status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                    SUM(CASE WHEN m.status = 'complained' THEN 1 ELSE 0 END) AS complained
             FROM campaigns c
             LEFT JOIN email_messages m ON m.campaign_id = c.id AND m.organisation_id = c.organisation_id
             WHERE c.organisation_id = ? AND c.deleted_at IS NULL
               AND c.send_started_at IS NOT NULL AND c.send_started_at >= ?
             GROUP BY c.id, c.name, c.subject, c.campaign_type, c.status, c.send_started_at
             ORDER BY c.send_started_at DESC
             LIMIT " . max(1, $limit),
            [$this->tenant->organisationId(), $this->clock->agoString($days)]
        );

        return array_map(fn (array $row): array => $this->rates($row, [
            'id'              => (int) $row['id'],
            'name'            => (string) $row['name'],
            'subject'         => (string) ($row['subject'] ?? ''),
            'type'            => (string) $row['campaign_type'],
            'status'          => (string) $row['status'],
            'send_started_at' => (string) ($row['send_started_at'] ?? ''),
        ]), $rows);
    }

    /**
     * The best and worst of the period, with a floor on how small a campaign may
     * be and still be called a winner.
     *
     * Without the floor, "your best campaign had a 100% click rate" would be a
     * three-recipient test send — a confident, useless answer.
     *
     * @return array{best:?array<string,mixed>,worst:?array<string,mixed>,average_click_rate:float,minimum:int}
     */
    public function highlights(int $days = 90): array
    {
        $minimum   = max(1, (int) $this->config->get('analytics.minimum_meaningful_send', 50));
        $campaigns = array_values(array_filter(
            $this->campaignPerformance($days),
            static fn (array $c): bool => $c['delivered'] >= $minimum
        ));

        if ($campaigns === []) {
            return ['best' => null, 'worst' => null, 'average_click_rate' => 0.0, 'minimum' => $minimum];
        }

        usort($campaigns, static fn (array $a, array $b): int => $b['click_rate'] <=> $a['click_rate']);

        $sum = array_sum(array_map(static fn (array $c): float => $c['click_rate'], $campaigns));

        return [
            'best'               => $campaigns[0],
            'worst'              => $campaigns[count($campaigns) - 1],
            'average_click_rate' => round($sum / count($campaigns), 2),
            'minimum'            => $minimum,
        ];
    }

    // --------------------------------------------------------- inbox health

    /**
     * Is our mail getting through, and is this tenant's behaviour putting the
     * rest of the platform at risk?
     *
     * @return array<string,mixed>
     */
    public function inboxDelivery(int $days = 30): array
    {
        $since = $this->clock->agoString($days);

        $totals = $this->rates($this->connection->selectOne(
            "SELECT
                SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'delivered' OR opened_at IS NOT NULL OR clicked_at IS NOT NULL
                         THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                SUM(CASE WHEN status = 'soft_bounced' THEN 1 ELSE 0 END) AS soft_bounced,
                SUM(CASE WHEN status = 'complained' THEN 1 ELSE 0 END) AS complained,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
             FROM email_messages
             WHERE organisation_id = ? AND message_class = 'marketing' AND created_at >= ?",
            [$this->tenant->organisationId(), $since]
        ) ?? []);

        return [
            'days'       => $days,
            'totals'     => $totals,
            'daily'      => $this->dailyDelivery($since),
            'providers'  => $this->byMailboxProvider($since),
            'thresholds' => [
                'bounce'    => (float) $this->config->get('antiabuse.alerts.bounce_rate', 0.05) * 100,
                'complaint' => (float) $this->config->get('antiabuse.alerts.complaint_rate', 0.001) * 100,
            ],
            'verdict' => $this->verdict($totals),
            'alerts'  => $this->recentAlerts(),
        ];
    }

    /**
     * A plain-English answer to "am I OK?".
     *
     * Numbers alone do not help somebody who has never heard of a complaint rate.
     * This is the sentence they read first.
     *
     * @param array<string,mixed> $totals
     * @return array{level:string,headline:string,detail:string}
     */
    public function verdict(array $totals): array
    {
        $bounceLimit    = (float) $this->config->get('antiabuse.alerts.bounce_rate', 0.05) * 100;
        $complaintLimit = (float) $this->config->get('antiabuse.alerts.complaint_rate', 0.001) * 100;

        if ((int) $totals['sent'] < 100) {
            return [
                'level'    => 'unknown',
                'headline' => 'Not enough sent yet to tell',
                'detail'   => 'Once you have sent a few hundred emails we can say how you are doing. '
                    . 'Until then the percentages jump around too much to mean anything.',
            ];
        }

        if ($totals['complaint_rate'] > $complaintLimit) {
            return [
                'level'    => 'bad',
                'headline' => 'Too many people are marking your email as spam',
                'detail'   => 'This is the one that gets sending shut down. It usually means people do not '
                    . 'remember signing up. Email the people who have bought from you recently, more often, '
                    . 'and stop emailing anyone who has ignored you for a year.',
            ];
        }

        if ($totals['bounce_rate'] > $bounceLimit) {
            return [
                'level'    => 'bad',
                'headline' => 'Too many of your addresses do not exist',
                'detail'   => 'Gmail and Outlook read this as a sign the list was bought or is very old, and '
                    . 'start sending your email to junk. Check where these addresses came from before you '
                    . 'send to them again.',
            ];
        }

        if ($totals['bounce_rate'] > $bounceLimit / 2 || $totals['complaint_rate'] > $complaintLimit / 2) {
            return [
                'level'    => 'watch',
                'headline' => 'Worth keeping an eye on',
                'detail'   => 'You are inside the safe range but heading the wrong way. Sending to people who '
                    . 'have not opened anything in a year is usually the cause.',
            ];
        }

        return [
            'level'    => 'good',
            'headline' => 'Your email is getting through',
            'detail'   => 'Bounces and spam complaints are both well inside the safe range. Keep doing what '
                . 'you are doing.',
        ];
    }

    /**
     * Split by mailbox provider.
     *
     * Worth its own view because the failure is usually lopsided: Gmail quietly
     * junking your mail while Outlook delivers it fine is invisible in a single
     * overall number, and it is the most common shape of a deliverability
     * problem.
     *
     * @return array<int,array<string,mixed>>
     */
    public function byMailboxProvider(string $since, int $limit = 8): array
    {
        // SUBSTR + INSTR rather than SUBSTRING_INDEX: both MySQL and SQLite have
        // these, so the same query is exercised by the test suite.
        $rows = $this->connection->select(
            "SELECT SUBSTR(email_normalized, INSTR(email_normalized, '@') + 1) AS provider,
                    SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN status = 'delivered' OR opened_at IS NOT NULL OR clicked_at IS NOT NULL
                             THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                    SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                    SUM(CASE WHEN status = 'complained' THEN 1 ELSE 0 END) AS complained
             FROM email_messages
             WHERE organisation_id = ? AND message_class = 'marketing' AND created_at >= ?
             GROUP BY SUBSTR(email_normalized, INSTR(email_normalized, '@') + 1)
             ORDER BY sent DESC
             LIMIT " . max(1, $limit),
            [$this->tenant->organisationId(), $since]
        );

        return array_map(fn (array $row): array => $this->rates($row, [
            'provider' => $this->friendlyProvider((string) $row['provider']),
            'domain'   => (string) $row['provider'],
        ]), $rows);
    }

    /**
     * Day by day, so a problem can be traced to the send that caused it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dailyDelivery(string $since): array
    {
        $rows = $this->connection->select(
            "SELECT SUBSTR(created_at, 1, 10) AS day,
                    SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN status = 'delivered' OR opened_at IS NOT NULL OR clicked_at IS NOT NULL
                             THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                    SUM(CASE WHEN status = 'complained' THEN 1 ELSE 0 END) AS complained
             FROM email_messages
             WHERE organisation_id = ? AND message_class = 'marketing' AND created_at >= ?
             GROUP BY SUBSTR(created_at, 1, 10)
             ORDER BY day",
            [$this->tenant->organisationId(), $since]
        );

        return array_map(static fn (array $row): array => [
            'date'      => (string) $row['day'],
            'sent'      => (int) $row['sent'],
            'delivered' => (int) $row['delivered'],
            'bounced'   => (int) $row['bounced'],
            'complained' => (int) $row['complained'],
        ], $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function recentAlerts(int $limit = 10): array
    {
        return $this->connection->select(
            'SELECT * FROM reputation_alerts WHERE organisation_id = ?
             ORDER BY created_at DESC LIMIT ' . max(1, $limit),
            [$this->tenant->organisationId()]
        );
    }

    // ------------------------------------------------------------ internals

    /**
     * Turn raw counts into counts plus rates.
     *
     * Every rate keeps its denominator alongside it: a rate with no idea how many
     * it is out of is how "100% click rate" from four recipients ends up next to
     * a real campaign in a report.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function rates(array $row, array $extra = []): array
    {
        $sent      = (int) ($row['sent'] ?? 0);
        $delivered = (int) ($row['delivered'] ?? 0);
        $opened    = (int) ($row['opened'] ?? 0);
        $clicked   = (int) ($row['clicked'] ?? 0);

        // Engagement is measured against what was delivered, not what was sent:
        // an address that bounced never had the chance to click.
        $base = max(1, $delivered);

        return $extra + [
            'sent'           => $sent,
            'delivered'      => $delivered,
            'opened'         => $opened,
            'clicked'        => $clicked,
            'bounced'        => (int) ($row['bounced'] ?? 0),
            'soft_bounced'   => (int) ($row['soft_bounced'] ?? 0),
            'complained'     => (int) ($row['complained'] ?? 0),
            'failed'         => (int) ($row['failed'] ?? 0),
            'delivery_rate'  => $sent > 0 ? round($delivered / $sent * 100, 1) : 0.0,
            'open_rate'      => $delivered > 0 ? round($opened / $base * 100, 1) : 0.0,
            'click_rate'     => $delivered > 0 ? round($clicked / $base * 100, 2) : 0.0,
            'bounce_rate'    => $sent > 0 ? round(((int) ($row['bounced'] ?? 0)) / $sent * 100, 2) : 0.0,
            'complaint_rate' => $sent > 0 ? round(((int) ($row['complained'] ?? 0)) / $sent * 100, 3) : 0.0,
        ];
    }

    private function friendlyProvider(string $domain): string
    {
        return match (strtolower($domain)) {
            'gmail.com', 'googlemail.com'                      => 'Gmail',
            'outlook.com', 'hotmail.com', 'live.com', 'msn.com' => 'Outlook / Hotmail',
            'yahoo.com', 'yahoo.com.au', 'ymail.com'           => 'Yahoo',
            'icloud.com', 'me.com', 'mac.com'                  => 'iCloud',
            'bigpond.com', 'bigpond.net.au', 'telstra.com'     => 'Telstra / Bigpond',
            'optusnet.com.au'                                  => 'Optus',
            'aol.com'                                          => 'AOL',
            default                                            => $domain,
        };
    }

    /** A readable name for a link when the author did not give it one. */
    private function describeUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $last = trim((string) strrchr('/' . trim($path, '/'), '/'), '/');

        if ($last === '') {
            return (string) parse_url($url, PHP_URL_HOST) ?: $url;
        }

        return ucfirst(str_replace(['-', '_'], ' ', $last));
    }
}
