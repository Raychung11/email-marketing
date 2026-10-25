<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Queue\DatabaseQueueDriver;
use App\Queue\QueueDriver;
use App\Queue\Worker;
use Tests\Support\SendsCampaigns;
use Tests\Support\TestCase;

/**
 * Pause, resume and cancel, over a real database queue.
 *
 * Deliberately not a handler-level test, because a handler-level test is what
 * let the original bug through. The job returned without sending — correctly —
 * and the WORKER then acknowledged it and deleted it from the queue, while the
 * recipient sat at `queued`, a state the dispatcher never looks at. Every
 * assertion about the handler passed. The campaign resumed into silence and
 * those customers were never emailed.
 *
 * So these run the actual queue driver and the actual worker loop: push, pop,
 * acknowledge, retry, dead-letter. The only thing standing in for production is
 * the provider.
 */
final class CampaignPauseResumeTest extends TestCase
{
    use SendsCampaigns;

    public function setUp(): void
    {
        parent::setUp();
        $this->useFakeDns();

        // The real driver, before anything that depends on it is resolved.
        $this->container->instance(
            QueueDriver::class,
            new DatabaseQueueDriver($this->connection, $this->container->make(Clock::class))
        );
    }

    /**
     * The regression. Pausing mid-send must not quietly drop the recipients
     * whose jobs were already on the queue.
     */
    public function testWorkPausedOnTheQueueIsStillSentAfterResuming(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 3);

        $this->dispatcher()->tick();

        $this->assertSame(3, $this->queueDepth('email_marketing'), 'three jobs are really on the queue');

        $this->campaignService()->pause($id, 'Wrong subject line');

        // The worker runs while the campaign is stopped — which is exactly what
        // happens in production, where nothing tells the workers to stand down.
        $this->worker()->drain(['email_marketing'], 'worker-1');

        $this->assertCount(0, $this->logProvider()->sentMessages(), 'pause means pause');
        $this->assertSame(0, $this->queueDepth('email_marketing'), 'the jobs were consumed');
        $this->assertSame(
            3,
            $this->recipientRepository()->remaining($id),
            'and the work is still owed — this is what used to be lost'
        );

        $this->campaignService()->resume($id);
        $this->dispatcher()->tick();
        $this->worker()->drain(['email_marketing'], 'worker-1');

        $this->assertCount(3, $this->logProvider()->sentMessages(), 'everybody got their email');
        $this->assertSame(0, $this->recipientRepository()->remaining($id));

        foreach ($this->snapshotRows($id) as $row) {
            $this->assertSame('sent', (string) $row['send_status']);
        }
    }

    /**
     * A recipient left claimed when everything stopped.
     *
     * A pause can always land after a job has taken its claim — there is no
     * lock around "check the campaign, then send" — and if the worker then goes
     * away, that row is in-flight work with nobody flying it. It must not sit at
     * `sending` forever: the lease lapses, the scheduler reclaims it, and
     * because the provider was never reached it is safe to send.
     */
    public function testARecipientStrandedMidClaimIsRecoveredOnceItsLeaseLapses(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $recipientId = (int) $this->snapshotRows($id)[0]['id'];

        // Claimed, nothing sent, and then the worker is gone.
        $this->recipientRepository()->claim($recipientId, 'abandoned', 300);
        $this->campaignService()->pause($id, 'Stop now');
        $this->connection->execute('DELETE FROM jobs');

        $this->assertSame('sending', (string) $this->recipientRow($recipientId)['send_status']);

        $this->campaignService()->resume($id);
        $this->dispatcher()->tick();

        $this->assertSame(
            'sending',
            (string) $this->recipientRow($recipientId)['send_status'],
            'while the lease holds, nobody touches it'
        );

        $this->clock->travel('+10 minutes');
        $this->dispatcher()->tick();
        $this->worker()->drain(['email_marketing'], 'worker-1');

        $row = $this->recipientRow($recipientId);

        $this->assertSame('sent', (string) $row['send_status'], 'reclaimed and sent');
        $this->assertCount(1, $this->logProvider()->sentMessages(), 'exactly once');
    }

    /**
     * A pause is not a failure. Spending the retry budget on it would mean a
     * campaign paused for an afternoon came back with its recipients
     * dead-lettered.
     */
    public function testPausingDoesNotConsumeTheRetryBudget(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->dispatcher()->tick();
        $this->campaignService()->pause($id, 'Holding');

        // Four pause cycles: more than the four attempts a job is allowed.
        for ($cycle = 0; $cycle < 4; $cycle++) {
            $this->worker()->drain(['email_marketing', 'email_retry'], 'worker-1');
            $this->dispatcher()->tick();
        }

        $this->assertSame(
            0,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM failed_jobs'),
            'nothing was dead-lettered'
        );

        $this->campaignService()->resume($id);
        $this->dispatcher()->tick();
        $this->worker()->drain(['email_marketing'], 'worker-1');

        $this->assertCount(2, $this->logProvider()->sentMessages());
    }

    /**
     * Cancel is the opposite of pause: the work is not owed any more, and a job
     * still sitting on the queue must not send it later.
     */
    public function testCancellingStopsQueuedJobsFromEverSending(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 3);

        $this->dispatcher()->tick();
        $this->campaignService()->cancel($id, 'Wrong list');

        $this->worker()->drain(['email_marketing'], 'worker-1');

        $this->assertCount(0, $this->logProvider()->sentMessages());

        foreach ($this->snapshotRows($id) as $row) {
            $this->assertSame('skipped', (string) $row['send_status']);
            $this->assertSame('CAMPAIGN_CANCELLED', (string) $row['eligibility_reason']);
        }

        // And nothing brings them back.
        $this->dispatcher()->tick();
        $this->worker()->drain(['email_marketing'], 'worker-1');

        $this->assertCount(0, $this->logProvider()->sentMessages());
    }

    /**
     * Resume has to pick up recipients whose job no longer exists — dead-lettered,
     * flushed, or acknowledged while the campaign was stopped. The dispatcher
     * only ever looks at `pending`, so a recipient stranded at `queued` is owed
     * an email that nothing will produce.
     */
    public function testResumingRecoversRecipientsWhoseJobHasVanished(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->dispatcher()->tick();
        $this->campaignService()->pause($id, 'Holding');

        // The queue is wiped — a flush, a failed deploy, a dead letter.
        $this->connection->execute('DELETE FROM jobs');

        $this->assertSame(0, $this->queueDepth('email_marketing'));

        $this->campaignService()->resume($id);
        $this->dispatcher()->tick();

        $this->assertSame(2, $this->queueDepth('email_marketing'), 'they were enqueued again');

        $this->worker()->drain(['email_marketing'], 'worker-1');

        $this->assertCount(2, $this->logProvider()->sentMessages());
    }

    /**
     * Pause and resume in quick succession leaves two jobs for one recipient.
     * Exactly one of them may send.
     */
    public function testADuplicateJobFromAPauseResumeRaceSendsOnlyOnce(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        // Resume while the first job is still sitting there, so the dispatcher
        // enqueues a second one for the same recipient.
        $this->campaignService()->pause($id, 'Holding');
        $recipientId = (int) $this->snapshotRows($id)[0]['id'];
        $this->connection->execute(
            "UPDATE campaign_recipients SET send_status = 'pending' WHERE id = ?",
            [$recipientId]
        );
        $this->campaignService()->resume($id);
        $this->dispatcher()->tick();

        $this->assertSame(2, $this->queueDepth('email_marketing'), 'two jobs for one recipient');

        $this->worker()->drain(['email_marketing'], 'worker-1');

        $this->assertCount(1, $this->logProvider()->sentMessages(), 'one of them did nothing');
        $this->assertSame(
            1,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM email_messages WHERE campaign_id = ?', [$id])
        );
    }

    /** Two workers on one queue is the normal production shape, not an edge case. */
    public function testTwoWorkersOnTheSameQueueNeverSendTheSameMessageTwice(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 4);

        $this->dispatcher()->tick();

        $a = $this->worker();
        $b = $this->worker();

        // Interleaved, one job each, the way two processes actually behave.
        while ($this->queueDepth('email_marketing') > 0) {
            $a->runNext(['email_marketing'], 'worker-a');
            $b->runNext(['email_marketing'], 'worker-b');
        }

        $sent = array_map(
            static fn ($message): string => $message->toEmail,
            $this->logProvider()->sentMessages()
        );

        sort($sent);

        $this->assertSame(4, count($sent));
        $this->assertSame(array_values(array_unique($sent)), $sent, 'no address was emailed twice');
    }

    // ------------------------------------------------------------ internals

    private function worker(): Worker
    {
        return $this->container->make(Worker::class);
    }

    private function queueDepth(string $queue): int
    {
        return (int) $this->connection->scalar('SELECT COUNT(*) FROM jobs WHERE queue = ?', [$queue]);
    }
}
