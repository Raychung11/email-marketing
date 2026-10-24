<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Core\Config;
use App\Database\Connection;
use App\Jobs\SendCampaignEmail;
use App\Mail\EmailProviderInterface;
use App\Mail\LogEmailProvider;
use App\Mail\SendResult;
use App\Queue\DatabaseQueueDriver;
use App\Queue\QueueDriver;
use App\Queue\QueueManager;
use App\Services\CampaignDispatcher;
use App\Services\CampaignService;
use App\Support\DnsResolver;
use App\Support\FakeDnsResolver;
use Tests\Support\QueueRunner;
use Tests\Support\ScriptedEmailProvider;
use Tests\Support\TestCase;

/**
 * The send pipeline on the real database queue.
 *
 * Every other send test uses the sync driver's in-memory buffer, which is fast
 * and proves the application's own logic. It does not prove the part that
 * actually broke. The lost sends and the unresumable pause were both failures
 * of the handover between the queue and the recipient row: a job acknowledged
 * away while its recipient sat in a state nothing looks at again.
 *
 * `database` is also the only queue driver the deployment this runs on can use,
 * so the rows in the `jobs` table — what gets inserted, reserved, released,
 * acknowledged and dead-lettered — are the production mechanics, not a stand-in
 * for them.
 */
final class DatabaseQueueSendTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->container->instance(DnsResolver::class, new FakeDnsResolver());

        // The real driver, writing to the real jobs table.
        $this->container->instance(QueueDriver::class, new DatabaseQueueDriver(
            $this->container->make(Connection::class),
            $this->container->make(Clock::class)
        ));
    }

    public function testACampaignGoesOutThroughRowsInTheJobsTable(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 3);

        $this->dispatcher()->tick();

        $this->assertSame(3, $this->jobsOn('email_marketing'), 'Three job rows, one per recipient');
        $this->assertSame(3, $this->recipientsIn($id, 'queued'));

        $processed = $this->runner()->drain();

        $this->assertSame(3, $processed);
        $this->assertSame(0, $this->jobsOn('email_marketing'), 'Each job is acknowledged, which deletes its row');
        $this->assertSame(3, $this->recipientsIn($id, 'sent'));
        $this->assertSame(
            3,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM email_messages WHERE campaign_id = ?', [$id]),
            'One message record per send'
        );
    }

    /**
     * THE BUG THIS FILE EXISTS FOR.
     *
     * Pausing returned from the job, the worker acknowledged the job away — so
     * the job row was deleted — and the recipient stayed `queued`. The
     * dispatcher only ever re-queues `pending`, so on resume there was no job
     * and no row anybody would look at. Those people were never written to and
     * nothing anywhere said so.
     *
     * On the sync driver this is invisible, because the buffer is not a queue
     * that forgets.
     */
    public function testPausingLosesNothingAndResumingSendsToEverybody(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 4);

        $this->dispatcher()->tick();
        $this->assertSame(4, $this->jobsOn('email_marketing'));

        $this->service()->pause($id);
        $this->bindTenant($context['organisation_id']);

        // The jobs are still on the queue; the worker reaches them and finds a
        // paused campaign. Every one is acknowledged away.
        $this->runner()->drain();

        $this->assertSame(0, $this->jobsOn('email_marketing'), 'The jobs are gone, as they would be in production');
        $this->assertSame(
            0,
            $this->recipientsIn($id, 'queued'),
            'And nobody is left in a state the dispatcher never looks at'
        );
        $this->assertSame(4, $this->recipientsIn($id, 'pending'), 'All four are back in the pool');
        $this->assertSame(4, $this->recipients()->remaining($id));
        $this->assertSame(
            0,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM email_messages WHERE campaign_id = ?', [$id]),
            'Nothing was sent while paused'
        );

        $this->service()->resume($id);
        $this->bindTenant($context['organisation_id']);

        $this->dispatcher()->tick();
        $this->runner()->drain();

        $this->assertSame(4, $this->recipientsIn($id, 'sent'), 'Everybody gets their email');
        $this->assertSame(0, $this->recipients()->remaining($id));

        // The worker does not know it sent the last one, so the campaign is
        // closed by the next scheduler tick rather than by the queue draining.
        $this->dispatcher()->tick();

        $this->assertSame('completed', (string) $this->campaign($id)['status']);
    }

    public function testCancellingClosesTheRowsSoAStragglingJobSendsNothing(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 3);

        $this->dispatcher()->tick();

        $this->service()->cancel($id);
        $this->bindTenant($context['organisation_id']);

        $this->runner()->drain();

        $this->assertSame(3, $this->recipientsIn($id, 'skipped'), 'Closed out, not returned to the pool');
        $this->assertSame(0, $this->recipientsIn($id, 'pending'), 'There is nothing to resume');
        $this->assertSame(
            0,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM email_messages WHERE campaign_id = ?', [$id]),
            'Nothing was sent'
        );
    }

    /**
     * A transient failure has to be retried once and sent once.
     *
     * The backoff is the part the sync driver cannot show: the retry goes onto
     * a different queue with an available_at in the future, so a drain that
     * happens before the delay elapses must find nothing to do.
     */
    public function testATransientFailureRetriesOnTheRetryQueueAfterItsBackoff(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->scriptProvider(
            SendResult::failed('Temporarily unavailable'),
            SendResult::accepted('provider-message-id')
        );

        $this->dispatcher()->tick();
        $this->runner()->drain();

        $this->assertSame(1, $this->jobsOn('email_retry'), 'The retry is on its own queue');
        $this->assertSame(0, $this->availableOn('email_retry'), 'And is not available yet');
        $this->assertSame(0, $this->jobsOn('email_marketing'), 'The original job row is gone');
        $this->assertSame(1, $this->recipientsIn($id, 'pending'), 'And the recipient is back in the pool');

        $retryAfter = (string) $this->connection->scalar(
            'SELECT retry_after FROM campaign_recipients WHERE campaign_id = ?',
            [$id]
        );

        $this->assertTrue($retryAfter > $this->clock->nowString(), 'Waiting out the same backoff the queue applied');

        // Before the backoff elapses, neither the queue nor the scheduler acts.
        $this->assertSame(0, $this->runner()->drain(), 'The retry is not available yet');
        $this->dispatcher()->tick();
        $this->assertSame(0, $this->jobsOn('email_marketing'), 'And the scheduler does not jump the delay');

        $this->clock->travel('+2 minutes');

        $this->assertSame(1, $this->runner()->drain(), 'Once the delay is up, the retry runs');
        $this->assertSame(1, $this->recipientsIn($id, 'sent'));

        $this->dispatcher()->tick();

        // Two message rows: the failed handoff stays on the record, which is
        // what makes "we tried twice" answerable. One send, though — the
        // campaign counts what went, not what was attempted.
        $this->assertSame(
            2,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM email_messages WHERE campaign_id = ?', [$id]),
            'The abandoned attempt is recorded rather than erased'
        );
        $this->assertSame(
            1,
            (int) $this->connection->scalar(
                "SELECT COUNT(*) FROM email_messages WHERE campaign_id = ? AND status = 'sent'",
                [$id]
            ),
            'Exactly one email was sent'
        );
        $this->assertSame(1, (int) $this->campaign($id)['sent_count']);
    }

    public function testAProviderRejectionIsDeadLetteredAndNeverRetried(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->scriptProvider(SendResult::rejected('Address rejected'));

        $this->dispatcher()->tick();
        $this->runner()->drain();

        $this->assertSame(0, $this->jobsOn('email_marketing'));
        $this->assertSame(0, $this->jobsOn('email_retry'), 'A rejection is not a retry');
        $this->assertSame(1, $this->recipientsIn($id, 'failed'));
        $this->assertSame(
            1,
            (int) $this->connection->scalar("SELECT COUNT(*) FROM failed_jobs WHERE permanent = 1"),
            'Dead-lettered as permanent'
        );
    }

    /**
     * A redelivered job — the queue's at-least-once guarantee in action.
     *
     * The same payload arriving twice must send once. The claim is what makes
     * that true, and before the fix it was the opposite: the first job marked
     * the row sent before sending, so the second claimed nothing and the email
     * was never sent at all.
     */
    public function testARedeliveredJobSendsNothingTheSecondTime(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $payload = json_decode(
            (string) $this->connection->scalar('SELECT payload FROM jobs LIMIT 1'),
            true
        );

        $this->runner()->drain();
        $this->assertSame(1, $this->recipientsIn($id, 'sent'));

        // The same job delivered again, exactly as a queue may do.
        $this->queue()->dispatch(
            'email_marketing',
            SendCampaignEmail::class,
            $payload,
            $context['organisation_id']
        );

        $this->runner()->drain();

        $this->assertSame(
            1,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM email_messages WHERE campaign_id = ?', [$id]),
            'Still one message: the second job claimed nothing'
        );
    }

    /**
     * The lease sweep, with a real queue behind it.
     *
     * A worker killed by the shared host leaves a reserved job and a recipient
     * in `sending`. The driver reclaims the job after ten minutes; the
     * dispatcher's sweep decides what the recipient's state means. A row whose
     * provider call provably never started is safe to send; one that may
     * already be in somebody's inbox is not, and is held for a person.
     */
    public function testAKilledWorkerLeavesAMaybeSentRowForAPersonAndRequeuesTheRest(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->dispatcher()->tick();

        $ids = $this->connection->select(
            'SELECT id FROM campaign_recipients WHERE campaign_id = ? ORDER BY id',
            [$id]
        );

        // Two dead workers: one died before calling the provider, one after.
        $this->connection->execute(
            "UPDATE campaign_recipients
                SET send_status = 'sending', lock_token = 'dead-worker', lease_expires_at = ?,
                    provider_attempted_at = NULL
              WHERE id = ?",
            ['2026-06-15 11:00:00', $ids[0]['id']]
        );

        $this->connection->execute(
            "UPDATE campaign_recipients
                SET send_status = 'sending', lock_token = 'dead-worker', lease_expires_at = ?,
                    provider_attempted_at = ?
              WHERE id = ?",
            ['2026-06-15 11:00:00', '2026-06-15 10:59:00', $ids[1]['id']]
        );

        $this->connection->execute('DELETE FROM jobs');

        $this->dispatcher()->tick();

        $this->assertSame(1, $this->recipientsIn($id, 'outcome_unknown'), 'The one that may have been sent is held');
        $this->assertSame(1, $this->jobsOn('email_marketing'), 'The one that provably was not is on the queue again');

        $this->runner()->drain();

        $this->assertSame(1, $this->recipientsIn($id, 'sent'));
        $this->assertSame(
            1,
            $this->recipients()->remaining($id),
            'The unresolved row holds the campaign open until somebody settles it'
        );
        $this->assertSame('sending', (string) $this->campaign($id)['status'], 'So it does not report itself complete');
    }

    // ----------------------------------------------------------- internals

    private function runner(): QueueRunner
    {
        return new QueueRunner(
            $this->container,
            $this->container->make(QueueDriver::class),
            $this->container->make(QueueManager::class),
            $this->container->make(Config::class)
        );
    }

    private function queue(): QueueManager
    {
        return $this->container->make(QueueManager::class);
    }

    /** Rows on a queue, whether or not their delay has elapsed. */
    private function jobsOn(string $queue): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM jobs WHERE queue = ?',
            [$queue]
        );
    }

    /** Rows a worker could pop right now. A delayed retry is not one of them. */
    private function availableOn(string $queue): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM jobs WHERE queue = ? AND available_at <= ?',
            [$queue, $this->clock->nowString()]
        );
    }

    private function recipientsIn(int $campaignId, string $status): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND send_status = ?',
            [$campaignId, $status]
        );
    }

    private function dispatcher(): CampaignDispatcher
    {
        return $this->container->make(CampaignDispatcher::class);
    }

    private function service(): CampaignService
    {
        return $this->container->make(CampaignService::class);
    }

    private function recipients(): \App\Repositories\CampaignRecipientRepository
    {
        return $this->container->make(\App\Repositories\CampaignRecipientRepository::class);
    }

    private function scriptProvider(SendResult ...$results): ScriptedEmailProvider
    {
        $provider = new ScriptedEmailProvider();
        $provider->script(...$results);

        $this->container->instance(EmailProviderInterface::class, $provider);

        return $provider;
    }

    /** @return array<string,mixed> */
    private function campaign(int $id): array
    {
        return $this->connection->selectOne('SELECT * FROM campaigns WHERE id = ?', [$id]) ?? [];
    }

    /**
     * An organisation that can legitimately send, and a campaign ready to go.
     *
     * Duplicated from CampaignSendTest rather than shared: the fixture is what
     * "a tenant who may send" means, and a test file that cannot be read
     * without opening another one is worse than a repeated twenty lines.
     *
     * @return array{organisation_id:int,user_id:int}
     */
    private function sendableOrganisation(): array
    {
        $org = $this->createOrganisation(['name' => 'Perth Plumbing Co', 'country' => 'US', 'timezone' => 'UTC']);

        $this->connection->execute(
            'UPDATE organisations SET address_line1 = ?, address_city = ?, address_state = ?, address_postcode = ?,
                    address_country = ?, contact_phone = ?, contact_email = ?,
                    default_sender_name = ?, default_sender_email = ?, daily_send_limit = 100000
             WHERE id = ?',
            [
                '12 Example Street', 'Perth', 'WA', '6000', 'US',
                '+61 8 5550 1000', 'hello@perthplumbing.test',
                'Perth Plumbing Co', 'hello@perthplumbing.test',
                $org['organisation_id'],
            ]
        );

        $this->connection->table('sending_domains')->insert([
            'organisation_id' => $org['organisation_id'],
            'domain'          => 'perthplumbing.test',
            'status'          => 'verified',
            'dkim_status'     => 'verified',
            'provider'        => 'log',
            'created_at'      => $this->clock->nowString(),
            'updated_at'      => $this->clock->nowString(),
        ]);

        $this->actingAs($org['user_id'], $org['organisation_id']);

        return $org;
    }

    /** @param array{organisation_id:int,user_id:int} $context */
    private function readyToSendCampaign(array $context, int $recipients): int
    {
        for ($i = 1; $i <= $recipients; $i++) {
            $this->createContact(
                ['email' => 'recipient-' . $i . '@example.com', 'first_name' => 'Sam', 'country' => 'US'],
                ['status' => 'granted', 'consent_type' => 'express']
            );
        }

        /** @var \App\Services\SegmentService $segments */
        $segments  = $this->container->make(\App\Services\SegmentService::class);
        $segmentId = $segments->create('Everyone', [
            'match' => 'all',
            'rules' => [['field' => 'email', 'operator' => 'contains', 'value' => '@']],
        ]);

        $id = $this->service()->create([
            'name'          => 'Autumn service reminder',
            'subject'       => 'Your annual plumbing check is due',
            'campaign_type' => 'service',
            'segment_id'    => $segmentId,
        ]);

        $this->service()->update($id, [
            'html_content' => '<p>Hello {{first_name}}, book your check at '
                . '<a href="https://perthplumbing.test/book">our site</a>.</p>'
                . '<p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
        ]);

        $this->service()->submitForReview($id);

        if ((string) $this->campaign($id)['status'] === 'pending_review') {
            $approver = $this->createUserWithRole($context['organisation_id'], 'ADMIN');
            $this->actingAs($approver, $context['organisation_id']);
            $this->service()->approve($id);
            $this->actingAs($context['user_id'], $context['organisation_id']);
        }

        $this->service()->sendNow($id);

        return $id;
    }
}
