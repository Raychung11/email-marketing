<?php

declare(strict_types=1);

/**
 * Scheduler. One cron entry, every minute:
 *
 *   * * * * * cd /var/www/aigrowthhub && php cron/scheduler.php >> storage/logs/scheduler.log 2>&1
 *
 * Every job takes a named lock, so a minute that overlaps the previous run cannot
 * double-execute anything — which for a system that sends email is the difference
 * between a campaign and an incident.
 */

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\Application;
use App\Core\Clock;
use App\Core\Logger;
use App\Database\Connection;
use App\Services\SchedulerLock;

$app       = Application::bootConsole(dirname(__DIR__));
$container = $app->container();

/** @var Logger $logger */
$logger = $container->make(Logger::class);
/** @var SchedulerLock $locks */
$locks = $container->make(SchedulerLock::class);
/** @var Connection $connection */
$connection = $container->make(Connection::class);
/** @var Clock $clock */
$clock = $container->make(Clock::class);

$startedAt = microtime(true);
$ran       = [];

/**
 * @param callable():void $callback
 */
$schedule = static function (string $name, int $ttl, callable $callback) use ($locks, $logger, &$ran): void {
    $acquired = $locks->withLock($name, $ttl, static function () use ($callback, $name, $logger, &$ran): void {
        try {
            $callback();
            $ran[] = $name;
        } catch (Throwable $e) {
            $logger->error('Scheduled job failed', ['job' => $name, 'error' => $e->getMessage()]);
        }
    });

    if (!$acquired) {
        $logger->info('Scheduled job skipped: already running', ['job' => $name]);
    }
};

/*
 * ---------------------------------------------------------------------------
 * Every minute
 * ---------------------------------------------------------------------------
 */

// Activate scheduled campaigns, build their recipient snapshots and enqueue the
// sends; also close out campaigns whose queue has drained.
//
// The lock TTL is generous because building a snapshot for a large audience is
// genuinely slow, and a second tick starting half-way through the first is how a
// campaign sends twice.
$schedule('campaigns.dispatch', 900, static function () use ($container, $logger): void {
    /** @var App\Services\CampaignDispatcher $dispatcher */
    $dispatcher = $container->make(App\Services\CampaignDispatcher::class);

    $summary = $dispatcher->tick(25);

    if (array_sum($summary) > 0) {
        $logger->info('Campaign dispatch tick', $summary);
    }
});

/*
 * Move automation runs along.
 *
 * Every journey step happens here or in a worker, never in a web request. A run
 * is a row: this picks up the ones whose timer has elapsed, binds their tenant,
 * advances each until it waits again, and moves on. A journey that waits three
 * weeks costs nothing while it waits.
 */
$schedule('automations.run', 600, static function () use ($container, $logger): void {
    /** @var App\Automation\AutomationService $automations */
    $automations = $container->make(App\Automation\AutomationService::class);

    $moved = $automations->advanceDueRuns();

    if ($moved > 0) {
        $logger->info('Automation runs advanced', ['runs' => $moved]);
    }
});

// Outbound webhook retries.
$schedule('webhooks.retry', 300, static function () use ($connection, $clock, $logger): void {
    $pending = (int) $connection->scalar(
        "SELECT COUNT(*) FROM webhook_deliveries
         WHERE status = 'pending' AND next_attempt_at IS NOT NULL AND next_attempt_at <= ?",
        [$clock->nowString()]
    );

    if ($pending > 0) {
        $logger->info('Webhook deliveries awaiting retry', ['count' => $pending]);
    }
});

// Expire stale locks left behind by a crashed process.
$schedule('locks.prune', 60, static function () use ($connection, $clock): void {
    $connection->execute('DELETE FROM scheduler_locks WHERE expires_at < ?', [$clock->nowString()]);
});

/*
 * ---------------------------------------------------------------------------
 * Every 15 minutes
 * ---------------------------------------------------------------------------
 */
if ((int) $clock->now()->format('i') % 15 === 0) {
    // Refresh cached segment counts. Expensive over large tables, which is exactly
    // why it is a background job rather than something a page render waits on.
    $schedule('segments.refresh', 900, static function () use ($container, $connection, $logger): void {
        $stale = $connection->select(
            'SELECT id, organisation_id FROM segments
             WHERE deleted_at IS NULL
             ORDER BY counts_refreshed_at IS NULL DESC, counts_refreshed_at ASC
             LIMIT 25'
        );

        if ($stale === []) {
            return;
        }

        /** @var App\Support\TenantContext $tenant */
        $tenant = $container->make(App\Support\TenantContext::class);
        /** @var App\Services\SegmentService $segments */
        $segments = $container->make(App\Services\SegmentService::class);
        /** @var App\Repositories\OrganisationRepository $organisations */
        $organisations = $container->make(App\Repositories\OrganisationRepository::class);

        foreach ($stale as $row) {
            $organisation = $organisations->findById((int) $row['organisation_id']);

            if ($organisation === null) {
                continue;
            }

            // The scheduler crosses tenants, so it binds each one explicitly and
            // clears afterwards. Repositories stay organisation-scoped throughout.
            $tenant->clear();
            $tenant->bind((int) $organisation['id'], null, $organisation);

            try {
                $segments->refreshCounts((int) $row['id']);
            } catch (Throwable $e) {
                $logger->warning('Segment count refresh failed', [
                    'segment' => (int) $row['id'],
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $tenant->clear();
    });

    // Journeys that start from the calendar rather than from an event:
    // "a customer has gone quiet", "it is somebody's birthday". Nothing fires
    // them, so something has to go looking.
    $schedule('automations.scheduled_triggers', 900, static function () use ($container, $logger): void {
        /** @var App\Automation\AutomationService $automations */
        $automations = $container->make(App\Automation\AutomationService::class);

        $started = $automations->fireScheduledTriggers();

        if ($started > 0) {
            $logger->info('Scheduled journeys started', ['runs' => $started]);
        }
    });

    // Re-check domains whose DNS was still propagating, so a customer who
    // publishes their records overnight is verified by morning.
    $schedule('domains.recheck', 900, static function () use ($container, $logger): void {
        /** @var App\Services\SendingDomainService $domains */
        $domains = $container->make(App\Services\SendingDomainService::class);

        $results = $domains->recheckPending(25);

        foreach ($results as $result) {
            if ($result['status'] === 'verified') {
                $logger->info('Sending domain verified', ['domain' => $result['domain']]);
            }
        }
    });

    // Deliverability monitoring.
    $schedule('deliverability.monitor', 900, static function () use ($connection, $clock, $container, $logger): void {
        /** @var App\Core\Config $config */
        $config = $container->make(App\Core\Config::class);

        $bounceThreshold    = (float) $config->get('antiabuse.alerts.bounce_rate', 0.05);
        $complaintThreshold = (float) $config->get('antiabuse.alerts.complaint_rate', 0.001);
        $since              = $clock->agoString(1);

        $rows = $connection->select(
            "SELECT organisation_id,
                    COUNT(*) AS sent,
                    SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                    SUM(CASE WHEN status = 'complained' THEN 1 ELSE 0 END) AS complained
             FROM email_messages
             WHERE created_at >= ? AND message_class = 'marketing'
             GROUP BY organisation_id
             HAVING COUNT(*) >= 100",
            [$since]
        );

        foreach ($rows as $row) {
            $sent          = max(1, (int) $row['sent']);
            $bounceRate    = (int) $row['bounced'] / $sent;
            $complaintRate = (int) $row['complained'] / $sent;

            foreach ([
                ['high_bounce_rate', $bounceRate, $bounceThreshold, 'Bounce rate'],
                ['high_complaint_rate', $complaintRate, $complaintThreshold, 'Complaint rate'],
            ] as [$type, $value, $threshold, $label]) {
                if ($value <= $threshold) {
                    continue;
                }

                $connection->table('reputation_alerts')->insert([
                    'organisation_id' => (int) $row['organisation_id'],
                    'alert_type'      => $type,
                    'severity'        => 'critical',
                    'message'         => $label . ' is ' . round($value * 100, 2) . '% over the last 24 hours, '
                        . 'above the ' . round($threshold * 100, 2) . '% threshold.',
                    'metric_value'    => $value,
                    'threshold_value' => $threshold,
                    'created_at'      => $clock->nowString(),
                ]);

                $logger->warning('Deliverability alert raised', [
                    'organisation' => (int) $row['organisation_id'],
                    'type'         => $type,
                    'value'        => $value,
                ]);
            }
        }
    });
}

/*
 * ---------------------------------------------------------------------------
 * Hourly
 * ---------------------------------------------------------------------------
 */
if ((int) $clock->now()->format('i') === 0) {
    // Metered usage rollup, so billing never has to scan raw tables.
    $schedule('usage.rollup', 3600, static function () use ($connection, $clock): void {
        $period = $clock->now()->format('Y-m');

        $organisations = $connection->select('SELECT id FROM organisations WHERE deleted_at IS NULL');

        foreach ($organisations as $organisation) {
            $organisationId = (int) $organisation['id'];

            $contacts = (int) $connection->scalar(
                'SELECT COUNT(*) FROM contacts WHERE organisation_id = ? AND deleted_at IS NULL',
                [$organisationId]
            );

            $emails = (int) $connection->scalar(
                'SELECT COUNT(*) FROM email_messages WHERE organisation_id = ? AND created_at >= ?',
                [$organisationId, $clock->now()->format('Y-m-01 00:00:00')]
            );

            $tokens = (int) $connection->scalar(
                'SELECT COALESCE(SUM(total_tokens), 0) FROM ai_requests WHERE organisation_id = ? AND created_at >= ?',
                [$organisationId, $clock->now()->format('Y-m-01 00:00:00')]
            );

            foreach (['contacts' => $contacts, 'emails_sent' => $emails, 'ai_tokens' => $tokens] as $metric => $quantity) {
                $existing = $connection->table('usage_records')
                    ->where('organisation_id', '=', $organisationId)
                    ->where('metric', '=', $metric)
                    ->where('period', '=', $period)
                    ->first();

                if ($existing !== null) {
                    $connection->table('usage_records')
                        ->where('id', '=', (int) $existing['id'])
                        ->update(['quantity' => $quantity, 'recorded_at' => $clock->nowString()]);

                    continue;
                }

                $connection->table('usage_records')->insert([
                    'organisation_id' => $organisationId,
                    'metric'          => $metric,
                    'period'          => $period,
                    'quantity'        => $quantity,
                    'recorded_at'     => $clock->nowString(),
                    'created_at'      => $clock->nowString(),
                    'updated_at'      => $clock->nowString(),
                ]);
            }
        }
    });

    // Abandoned imports: delete the uploaded file. Raw customer data should not
    // sit on disk because somebody closed a tab.
    $schedule('imports.cleanup', 3600, static function () use ($connection, $clock, $logger): void {
        $cutoff = $clock->now()->modify('-24 hours')->format('Y-m-d H:i:s');

        $stale = $connection->select(
            "SELECT id, stored_path FROM import_batches
             WHERE stored_path IS NOT NULL AND status NOT IN ('completed', 'importing') AND created_at < ?",
            [$cutoff]
        );

        foreach ($stale as $batch) {
            $path = (string) $batch['stored_path'];

            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }

            $connection->table('import_batches')
                ->where('id', '=', (int) $batch['id'])
                ->update(['stored_path' => null, 'status' => 'cancelled']);
        }

        if ($stale !== []) {
            $logger->info('Cleaned up abandoned imports', ['count' => count($stale)]);
        }
    });

    // Expired password reset tokens.
    $schedule('auth.prune_resets', 3600, static function () use ($connection, $clock): void {
        $connection->execute(
            'DELETE FROM password_resets WHERE expires_at < ?',
            [$clock->now()->modify('-7 days')->format('Y-m-d H:i:s')]
        );
    });
}

$logger->info('Scheduler tick complete', [
    'ran'         => $ran,
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
]);

exit(0);
