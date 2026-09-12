<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendCampaignEmail;
use App\Mail\LogEmailProvider;
use App\Mail\SendResult;
use App\Mail\EmailProviderInterface;
use App\Queue\QueueDriver;
use App\Queue\SyncQueueDriver;
use App\Services\CampaignDispatcher;
use App\Services\CampaignService;
use App\Support\DnsResolver;
use App\Support\FakeDnsResolver;
use App\Queue\PermanentFailure;
use Tests\Support\ScriptedEmailProvider;
use Tests\Support\TestCase;

/**
 * §19 / §20 — the send pipeline.
 *
 * Scheduled campaign → recipient snapshot → queue → worker → provider, and the
 * guarantees that have to hold all the way through it: the snapshot is taken
 * once, compliance is re-checked immediately before the provider call, a
 * redelivered job sends nothing, and bulk mail never leaves an HTTP request.
 */
final class CampaignSendTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->container->instance(DnsResolver::class, new FakeDnsResolver());
    }

    // --------------------------------------------------------- the snapshot

    public function testActivatingACampaignSnapshotsTheAudienceAndEnqueuesTheSends(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 3);

        $summary = $this->dispatcher()->tick();

        $this->assertSame(1, $summary['activated']);
        $this->assertSame(3, $summary['queued']);
        $this->assertSame('sending', (string) $this->campaign($id)['status']);
        $this->assertNotNull($this->campaign($id)['snapshot_completed_at'], 'The snapshot is stamped complete');
        $this->assertCount(3, $this->driver()->dispatched(), 'One job per recipient, never one job per batch');

        $rows = $this->snapshot($id);
        $this->assertCount(3, $rows);

        foreach ($rows as $row) {
            $this->assertSame('eligible', (string) $row['eligibility_status']);
            $this->assertSame('queued', (string) $row['send_status']);
        }
    }

    /**
     * The ineligible contacts are the point of the snapshot. "We sent to 2 of 3,
     * and here is why the third was held back" is a compliance record; sending to
     * 2 and saying nothing is just a number.
     */
    public function testIneligibleContactsAreRecordedWithTheirReasonRatherThanDropped(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->createContact(['email' => 'nope@example.com'], ['status' => 'withdrawn', 'consent_type' => 'express']);
        $this->createContact(['email' => 'bounced@example.com'], ['status' => 'granted', 'consent_type' => 'express']);

        /** @var \App\Services\SuppressionService $suppressions */
        $suppressions = $this->container->make(\App\Services\SuppressionService::class);
        $suppressions->suppress('bounced@example.com', 'hard_bounce', ['source' => 'provider']);

        $this->dispatcher()->tick();

        $rows = [];

        foreach ($this->snapshot($id) as $row) {
            $rows[(string) $row['email']] = $row;
        }

        $this->assertCount(3, $rows, 'Everyone the audience matched is recorded');
        $this->assertSame('no_consent', (string) $rows['nope@example.com']['eligibility_status']);
        $this->assertSame('CONSENT_WITHDRAWN', (string) $rows['nope@example.com']['eligibility_reason']);
        $this->assertSame('suppressed', (string) $rows['bounced@example.com']['eligibility_status']);
        $this->assertSame('SUPPRESSED_HARD_BOUNCE', (string) $rows['bounced@example.com']['eligibility_reason']);

        // Ineligible rows are closed out immediately, so "still to send" never has
        // to reason about eligibility again.
        $this->assertSame('skipped', (string) $rows['nope@example.com']['send_status']);
        $this->assertSame(1, count($this->driver()->dispatched()), 'Only the eligible contact is queued');
    }

    /**
     * A segment is a live query. If it were re-run mid-send, a contact added
     * afterwards would receive a campaign whose audience nobody reviewed.
     */
    public function testTheSnapshotIsNotReEvaluatedOnceComplete(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->dispatcher()->tick();
        $this->assertCount(2, $this->snapshot($id));

        $this->createContact(['email' => 'latecomer@example.com'], ['status' => 'granted', 'consent_type' => 'express']);

        $this->dispatcher()->tick();

        $emails = array_map(static fn (array $r): string => (string) $r['email'], $this->snapshot($id));

        $this->assertCount(2, $emails, 'The audience did not grow under the campaign');
        $this->assertFalse(in_array('latecomer@example.com', $emails, true));
    }

    /**
     * A build that dies half-way must resume, not treat the partial audience as
     * the whole audience. `snapshot_completed_at` is what separates the two.
     */
    public function testAnInterruptedSnapshotBuildResumesInsteadOfSendingToHalfTheAudience(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 3);

        // Simulate a crash after the first contact was written: one row present,
        // no completion stamp.
        $first = $this->connection->selectOne(
            'SELECT id, email FROM contacts WHERE organisation_id = ? ORDER BY id LIMIT 1',
            [$context['organisation_id']]
        ) ?? [];

        $this->connection->table('campaign_recipients')->insert([
            'organisation_id'    => $context['organisation_id'],
            'campaign_id'        => $id,
            'contact_id'         => (int) $first['id'],
            'email'              => (string) $first['email'],
            'email_normalized'   => (string) $first['email'],
            'eligibility_status' => 'eligible',
            'send_status'        => 'pending',
            'created_at'         => $this->clock->nowString(),
        ]);

        $this->dispatcher()->tick();

        $this->assertCount(3, $this->snapshot($id), 'The build resumed from the watermark');
        $this->assertSame(
            1,
            (int) $this->connection->scalar(
                'SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND contact_id = ?',
                [$id, (int) $first['id']]
            ),
            'Resuming did not duplicate the row it had already written'
        );
    }

    // ------------------------------------------------- the second compliance check

    /**
     * The preview never authorises a send. Between the snapshot and the provider
     * call a contact can unsubscribe — and this is the check that catches it.
     */
    public function testAContactWhoUnsubscribesAfterTheSnapshotIsNotSent(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->dispatcher()->tick();

        /** @var \App\Services\SuppressionService $suppressions */
        $suppressions = $this->container->make(\App\Services\SuppressionService::class);
        $suppressions->suppress('recipient-1@example.com', 'unsubscribe', ['source' => 'recipient']);

        $this->runQueue();

        $rows = [];

        foreach ($this->snapshot($id) as $row) {
            $rows[(string) $row['email']] = $row;
        }

        $this->assertSame('skipped', (string) $rows['recipient-1@example.com']['send_status']);
        $this->assertSame('SUPPRESSED_UNSUBSCRIBE', (string) $rows['recipient-1@example.com']['eligibility_reason']);
        $this->assertSame('sent', (string) $rows['recipient-2@example.com']['send_status']);

        $sent = array_map(
            static fn ($message): string => $message->toEmail,
            $this->provider()->sentMessages()
        );

        $this->assertSame(['recipient-2@example.com'], $sent, 'The unsubscribed address was never handed to the provider');
    }

    public function testPausingACampaignStopsTheWorkerBeforeTheProviderCall(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 3);

        $this->dispatcher()->tick();

        $this->service()->pause($id, 'Wrong subject line');

        $this->runQueue();

        $this->assertCount(0, $this->provider()->sentMessages(), 'Pause means pause');
        $this->assertSame(3, $this->recipients()->remaining($id), 'The recipients are still outstanding');
    }

    /**
     * A redelivered queue message, or a worker that died after the provider call
     * but before acknowledging, must not send a second copy.
     */
    public function testAJobThatRunsTwiceSendsOnce(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $jobs = $this->driver()->dispatched();
        $this->assertCount(1, $jobs);

        $this->handle($jobs[0]->payload);
        $this->handle($jobs[0]->payload);

        $this->assertCount(1, $this->provider()->sentMessages());
        $this->assertSame(
            1,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM email_messages WHERE campaign_id = ?', [$id])
        );
    }

    // --------------------------------------------------------------- headers

    public function testEverySendCarriesOneClickUnsubscribeHeaders(): void
    {
        $context = $this->sendableOrganisation();
        $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();
        $this->runQueue();

        $messages = $this->provider()->sentMessages();
        $this->assertCount(1, $messages);

        $headers = $messages[0]->allHeaders();

        // RFC 8058. A recipient who can unsubscribe from their mail client does
        // that instead of pressing "spam", which is what protects the sending
        // reputation of every other tenant on the platform.
        $this->assertContainsString('List-Unsubscribe', implode(' ', array_keys($headers)));
        $this->assertSame('List-Unsubscribe=One-Click', (string) $headers['List-Unsubscribe-Post']);
        $this->assertSame('bulk', (string) $headers['Precedence']);
        $this->assertContainsString('/unsubscribe/', (string) $headers['List-Unsubscribe']);
    }

    // --------------------------------------------------- provider failures

    /**
     * A rejection is the provider saying this will never work. Retrying burns
     * sending reputation for nothing, so the job fails permanently and the
     * recipient is closed out with the reason.
     */
    public function testAProviderRejectionIsNeverRetried(): void
    {
        $context  = $this->sendableOrganisation();
        $id       = $this->readyToSendCampaign($context, 1);
        $provider = $this->scriptProvider(SendResult::rejected('Address rejected by the provider'));

        $this->dispatcher()->tick();

        $job = $this->driver()->dispatched()[0];

        $this->assertThrows(PermanentFailure::class, fn () => $this->handle($job->payload));
        $this->bindTenant($context['organisation_id']);

        $recipient = $this->snapshot($id)[0];

        $this->assertSame('failed', (string) $recipient['send_status']);
        $this->assertContainsString('rejected', (string) $recipient['last_error']);
        $this->assertCount(1, $provider->sentMessages(), 'One attempt, not four');

        $message = $this->connection->selectOne('SELECT * FROM email_messages LIMIT 1') ?? [];
        $this->assertSame('failed', (string) $message['status']);
        $this->assertNull($message['sent_at']);
    }

    /**
     * A throttle or a 5xx is temporary. The recipient goes back in the pool, the
     * abandoned message row is closed as failed rather than left looking queued,
     * and the retry sends exactly one email.
     */
    public function testATransientFailureReleasesTheRecipientAndTheRetrySendsOnce(): void
    {
        $context  = $this->sendableOrganisation();
        $id       = $this->readyToSendCampaign($context, 1);
        $provider = $this->scriptProvider(SendResult::failed('Throttled'));

        $this->dispatcher()->tick();

        $job = $this->driver()->dispatched()[0];

        $this->assertThrows(\RuntimeException::class, fn () => $this->handle($job->payload));
        $this->bindTenant($context['organisation_id']);

        $this->assertSame('pending', (string) $this->snapshot($id)[0]['send_status'], 'Back in the pool');
        $this->assertNull($this->snapshot($id)[0]['email_message_id'], 'Not pointing at a message that never went');

        // The worker's retry. The script is exhausted, so the provider accepts.
        $this->handle($job->payload);
        $this->bindTenant($context['organisation_id']);

        $this->assertSame('sent', (string) $this->snapshot($id)[0]['send_status']);
        $this->assertCount(2, $provider->sentMessages(), 'One failed attempt, one successful retry');

        $this->dispatcher()->tick();

        // Two message rows exist — the failed handoff is on the record — but the
        // campaign reports one send, because that is how many went.
        $this->assertSame(
            2,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM email_messages WHERE campaign_id = ?', [$id])
        );
        $this->assertSame(1, (int) $this->campaign($id)['sent_count']);
    }

    // -------------------------------------------------------------- throttle

    public function testTheDailySendLimitCapsWhatOneTickEnqueues(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 3);

        $this->connection->execute(
            'UPDATE organisations SET daily_send_limit = 2 WHERE id = ?',
            [$context['organisation_id']]
        );
        $this->bindTenant($context['organisation_id']);

        $summary = $this->dispatcher()->tick();

        $this->assertSame(2, $summary['queued'], 'The tick stopped at the tenant limit');
        $this->assertSame('sending', (string) $this->campaign($id)['status'], 'The campaign waits rather than failing');
        $this->assertSame(3, $this->recipients()->remaining($id));
    }

    /**
     * A suspended tenant, or one an administrator has paused, is held rather than
     * having its whole audience marked ineligible — the block applies to everybody
     * and will be lifted.
     */
    public function testAnOrganisationWithSendingPausedIsHeldNotBurned(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->connection->execute(
            'UPDATE organisations SET sending_paused = 1, sending_paused_reason = ? WHERE id = ?',
            ['Deliverability review', $context['organisation_id']]
        );
        $this->bindTenant($context['organisation_id']);

        $summary = $this->dispatcher()->tick();

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(0, $summary['queued']);
        $this->assertSame('scheduled', (string) $this->campaign($id)['status'], 'It stays due, ready to resume');
        $this->assertCount(0, $this->snapshot($id), 'No audience was burned marking everybody ineligible');
    }

    // -------------------------------------------------------------- lifecycle

    public function testACampaignCompletesWhenItsQueueDrainsAndTheCountersRefresh(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->dispatcher()->tick();
        $this->runQueue();

        // The tick that notices there is nothing left outstanding.
        $this->dispatcher()->tick();

        $campaign = $this->campaign($id);

        $this->assertSame('completed', (string) $campaign['status']);
        $this->assertNotNull($campaign['send_completed_at']);
        $this->assertSame(2, (int) $campaign['sent_count'], 'Counters are recomputed from email_messages');
        $this->assertSame(2, (int) $campaign['recipient_count']);
        $this->assertSame(2, (int) $campaign['eligible_count']);
    }

    public function testACampaignWithNobodyContactableCompletesWithoutSending(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        // Everyone in the audience unsubscribes between approval and the send.
        /** @var \App\Services\SuppressionService $suppressions */
        $suppressions = $this->container->make(\App\Services\SuppressionService::class);
        $suppressions->suppress('recipient-1@example.com', 'unsubscribe', ['source' => 'recipient']);

        $summary = $this->dispatcher()->tick();

        $this->assertSame(1, $summary['completed']);
        $this->assertSame(0, $summary['queued']);
        $this->assertSame('completed', (string) $this->campaign($id)['status'], 'Not a failure — nobody was contactable');
        $this->assertCount(0, $this->driver()->dispatched());

        $reasons = $this->recipients()->skipReasons($id);
        $this->assertSame(1, $reasons['SUPPRESSED_UNSUBSCRIBE'] ?? 0, 'The report can explain it');
    }

    /** Bulk mail never leaves an HTTP request: "send now" means "queue now". */
    public function testSendNowOnlySchedulesAndDoesNotSendInline(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->assertSame('scheduled', (string) $this->campaign($id)['status']);
        $this->assertCount(0, $this->driver()->dispatched(), 'Nothing was queued by the request itself');
        $this->assertCount(0, $this->provider()->sentMessages());
        $this->assertCount(0, $this->snapshot($id), 'The snapshot is the scheduler\'s work, not the request\'s');
    }

    /**
     * The campaign page after a send.
     *
     * Rendering it is the assertion: it is the one place the snapshot, the skip
     * reasons and the live counters all meet, and a page that 500s here is a page
     * nobody can use to explain a campaign to a customer.
     */
    public function testTheCampaignPageReportsFromTheSnapshotOnceItHasOne(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $before = $this->get('/campaigns/' . $id);
        $this->assertStatus(200, $before);
        $this->assertContainsString('Audience', $before->body(), 'Before sending it shows the live preview');

        /** @var \App\Services\SuppressionService $suppressions */
        $suppressions = $this->container->make(\App\Services\SuppressionService::class);
        $suppressions->suppress('recipient-1@example.com', 'unsubscribe', ['source' => 'recipient']);

        $this->dispatcher()->tick();
        $this->runQueue();
        $this->dispatcher()->tick();

        $after = $this->get('/campaigns/' . $id);

        $this->assertStatus(200, $after);
        $this->assertContainsString('Who this went to', $after->body());
        $this->assertContainsString('recipient snapshot', $after->body());
        $this->assertContainsString(
            'unsubscribed and is on the suppression list',
            $after->body(),
            'The page explains why somebody was held back'
        );
    }

    // ------------------------------------------------------------- isolation

    public function testASnapshotNeverReachesAcrossTenants(): void
    {
        $other = $this->sendableOrganisation();
        $this->createContact(['email' => 'theirs@example.com'], ['status' => 'granted', 'consent_type' => 'express']);

        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->dispatcher()->tick();

        $emails = array_map(static fn (array $r): string => (string) $r['email'], $this->snapshot($id));

        $this->assertFalse(in_array('theirs@example.com', $emails, true));
        $this->assertCount(2, $emails);

        $foreign = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND organisation_id != ?',
            [$id, $context['organisation_id']]
        );

        $this->assertSame(0, $foreign);
        $this->assertSame($other['organisation_id'] > 0, true);
    }

    // ------------------------------------------------------------- internals

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

    private function driver(): SyncQueueDriver
    {
        /** @var SyncQueueDriver $driver */
        $driver = $this->container->make(QueueDriver::class);

        return $driver;
    }

    private function scriptProvider(SendResult ...$results): ScriptedEmailProvider
    {
        $provider = new ScriptedEmailProvider();
        $provider->script(...$results);

        $this->container->instance(EmailProviderInterface::class, $provider);

        return $provider;
    }

    private function provider(): LogEmailProvider
    {
        /** @var LogEmailProvider $provider */
        $provider = $this->container->make(EmailProviderInterface::class);

        return $provider;
    }

    /** @return array<string,mixed> */
    private function campaign(int $id): array
    {
        return $this->connection->selectOne('SELECT * FROM campaigns WHERE id = ?', [$id]) ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    private function snapshot(int $id): array
    {
        return $this->connection->select(
            'SELECT * FROM campaign_recipients WHERE campaign_id = ? ORDER BY id',
            [$id]
        );
    }

    /** Drain the queue exactly the way the worker does. */
    private function runQueue(): void
    {
        while (($job = $this->driver()->pop('email_marketing', 'test-worker')) !== null) {
            $this->handle($job->payload);
        }
    }

    /** @param array<string,mixed> $payload */
    private function handle(array $payload): void
    {
        /** @var SendCampaignEmail $handler */
        $handler = $this->container->make(SendCampaignEmail::class);
        $handler->handle($payload);
    }

    /**
     * An organisation that can legitimately send: verified domain, postal
     * address, sender identity.
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

        return ['organisation_id' => $org['organisation_id'], 'user_id' => $org['user_id']];
    }

    /**
     * A campaign approved, scheduled and waiting for the scheduler.
     *
     * @param array{organisation_id:int,user_id:int} $context
     */
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
