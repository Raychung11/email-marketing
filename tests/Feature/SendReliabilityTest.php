<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendCampaignEmail;
use App\Mail\EmailProviderInterface;
use App\Mail\SendResult;
use App\Queue\PermanentFailure;
use App\Repositories\EmailMessageRepository;
use Tests\Support\ExplodingEmailProvider;
use Tests\Support\FailingEmailMessages;
use Tests\Support\SendsCampaigns;
use Tests\Support\TestCase;

/**
 * What the send pipeline claims, and whether the data supports it.
 *
 * One question runs through all of it: at the moment something goes wrong, do
 * we know whether the provider was asked to send this email? The old code did
 * not ask — it marked the recipient `sent` while taking the claim out, before
 * rendering, before the message row, before the provider — so any failure after
 * that point produced a recipient who had been told nothing and a campaign
 * reporting that they had been emailed.
 */
final class SendReliabilityTest extends TestCase
{
    use SendsCampaigns;

    public function setUp(): void
    {
        parent::setUp();
        $this->useFakeDns();
    }

    // ------------------------------------------------- claiming is not sending

    /**
     * The regression itself. A claim says "I am working on this", and until the
     * provider has accepted the message, that is all it may say.
     */
    public function testClaimingARecipientDoesNotMarkItSent(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $recipientId = (int) $this->snapshotRows($id)[0]['id'];
        $claimed     = $this->recipientRepository()->claim($recipientId, 'token-a', 300);

        $this->assertNotNull($claimed, 'the claim was taken');

        $row = $this->recipientRow($recipientId);

        $this->assertSame('sending', (string) $row['send_status'], 'in flight, not sent');
        $this->assertNull($row['sent_at'], 'nothing has been sent, so there is no sent time');
        $this->assertNull($row['provider_attempted_at'], 'the provider has not been reached yet');
    }

    /** attempt_count answers "how many times have we tried", not "at least once". */
    public function testEachAttemptIncrementsTheAttemptCount(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $recipientId = (int) $this->snapshotRows($id)[0]['id'];
        $repository  = $this->recipientRepository();

        $repository->claim($recipientId, 'token-a', 300);
        $this->assertSame(1, (int) $this->recipientRow($recipientId)['attempt_count']);

        $repository->release($recipientId, 'token-a');
        $repository->claim($recipientId, 'token-b', 300);
        $this->assertSame(2, (int) $this->recipientRow($recipientId)['attempt_count']);

        $repository->release($recipientId, 'token-b');
        $repository->claim($recipientId, 'token-c', 300);
        $this->assertSame(3, (int) $this->recipientRow($recipientId)['attempt_count']);
    }

    // ----------------------------------------------------------- ownership

    public function testOnlyOneHolderCanClaimARecipient(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $recipientId = (int) $this->snapshotRows($id)[0]['id'];
        $repository  = $this->recipientRepository();

        $this->assertNotNull($repository->claim($recipientId, 'first', 300));
        $this->assertNull($repository->claim($recipientId, 'second', 300), 'a second worker claims nothing');
    }

    /**
     * A holder whose lease lapsed must not be able to write over the worker that
     * took the row on afterwards. Without the token check the late writer wins,
     * and a recipient the new holder is mid-way through sending to is quietly
     * marked sent by the old one.
     */
    public function testAStaleHolderCannotOverwriteTheCurrentOne(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $recipientId = (int) $this->snapshotRows($id)[0]['id'];
        $repository  = $this->recipientRepository();

        $repository->claim($recipientId, 'stale', 1);

        // The lease lapses and the scheduler hands the row back.
        $this->clock->travel('+5 minutes');
        $repository->reclaimExpiredClaims();

        $this->assertNotNull($repository->claim($recipientId, 'current', 300));

        $this->assertFalse(
            $repository->markSent($recipientId, 'stale', 123),
            'the old holder cannot mark it sent'
        );
        $this->assertFalse(
            $repository->release($recipientId, 'stale'),
            'nor release it out from under the current holder'
        );

        $row = $this->recipientRow($recipientId);
        $this->assertSame('sending', (string) $row['send_status']);
        $this->assertSame('current', (string) $row['claim_token']);
    }

    // ------------------------------------------------- failure before sending

    /**
     * Rendering blew up. Nothing reached the provider, so the recipient goes
     * straight back in the pool and the retry can have it.
     */
    public function testAFailureBeforeTheProviderCallIsSafeToRetry(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $recipientId = (int) $this->snapshotRows($id)[0]['id'];
        $provider    = $this->scriptProvider(SendResult::accepted('never-reached'));

        // The database falls over while writing the message row, which happens
        // before the provider is touched.
        $this->container->instance(
            EmailMessageRepository::class,
            new FailingEmailMessages($this->connection, $this->tenant, $this->clock, 'create')
        );

        $this->assertThrows(
            \RuntimeException::class,
            fn () => $this->runJobFor($context['organisation_id'], $id, $recipientId),
            'retryable, because nothing was sent'
        );

        $this->bindTenant($context['organisation_id']);
        $row = $this->recipientRow($recipientId);

        $this->assertCount(0, $provider->sentMessages(), 'the provider was never reached');
        $this->assertSame('pending', (string) $row['send_status'], 'straight back in the pool');
        $this->assertNull($row['sent_at']);
        $this->assertNull($row['claim_token'], 'and the claim was given up');

        // And the retry, with a working database, sends it.
        $this->container->instance(
            EmailMessageRepository::class,
            new EmailMessageRepository($this->connection, $this->tenant, $this->clock)
        );

        $this->runJobFor($context['organisation_id'], $id, $recipientId);
        $this->bindTenant($context['organisation_id']);

        $this->assertSame('sent', (string) $this->recipientRow($recipientId)['send_status']);
        $this->assertCount(1, $provider->sentMessages(), 'sent exactly once, on the retry');
    }

    /**
     * The provider threw rather than answering — a timeout, a reset. The request
     * may well have arrived. Retrying would risk a second copy, so the row is
     * parked for a person instead.
     */
    public function testAProviderTimeoutParksTheRecipientInsteadOfRetrying(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $this->container->instance(
            EmailProviderInterface::class,
            new ExplodingEmailProvider('Connection timed out after 30000 ms')
        );

        $recipientId = (int) $this->snapshotRows($id)[0]['id'];

        $this->assertThrows(
            PermanentFailure::class,
            fn () => $this->runJobFor($context['organisation_id'], $id, $recipientId),
            'the job must not be retried'
        );

        $this->bindTenant($context['organisation_id']);
        $row = $this->recipientRow($recipientId);

        $this->assertSame('outcome_unknown', (string) $row['send_status']);
        $this->assertNull($row['sent_at'], 'we never learned that it was sent');
        $this->assertNotNull($row['provider_attempted_at'], 'we do know a request went out');
        $this->assertContainsString('timed out', (string) $row['last_error']);
    }

    /**
     * The provider accepted it and the database write that records that failed.
     * The email is gone. Sending it again would mail a real customer twice, so
     * this is parked rather than retried.
     */
    public function testAnAcceptanceWeFailedToRecordIsNotResent(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $recipientId = (int) $this->snapshotRows($id)[0]['id'];
        $provider    = $this->scriptProvider(SendResult::accepted('provider-message-1'));

        // Break the write that happens immediately after acceptance.
        $this->container->instance(
            EmailMessageRepository::class,
            new FailingEmailMessages(
                $this->connection,
                $this->tenant,
                $this->clock
            )
        );

        $this->assertThrows(
            PermanentFailure::class,
            fn () => $this->runJobFor($context['organisation_id'], $id, $recipientId)
        );

        $this->bindTenant($context['organisation_id']);

        $this->assertCount(1, $provider->sentMessages(), 'the provider was asked exactly once');
        $this->assertSame('outcome_unknown', (string) $this->recipientRow($recipientId)['send_status']);
    }

    // ------------------------------------------------------------- backoff

    /**
     * A transient refusal is a definite answer — nothing was accepted — so the
     * recipient goes back. What it must NOT do is become immediately available:
     * the queue is waiting a minute before retrying, and the scheduler running
     * every minute would otherwise enqueue a fresh job straight past that.
     */
    public function testATransientFailureHoldsTheRecipientUntilItsBackoffHasPassed(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->scriptProvider(SendResult::failed('Throttling: Maximum sending rate exceeded'));
        $this->dispatcher()->tick();

        $recipientId = (int) $this->snapshotRows($id)[0]['id'];

        $this->assertThrows(
            \RuntimeException::class,
            fn () => $this->runJobFor($context['organisation_id'], $id, $recipientId)
        );

        $this->bindTenant($context['organisation_id']);
        $row = $this->recipientRow($recipientId);

        $this->assertSame('pending', (string) $row['send_status'], 'back in the pool');
        $this->assertNotNull($row['available_at'], 'but not yet');

        // The scheduler respects it.
        $this->dispatcher()->tick();
        $this->assertSame(
            'pending',
            (string) $this->recipientRow($recipientId)['send_status'],
            'the scheduler did not route around the backoff'
        );

        $this->clock->travel('+2 minutes');
        $this->dispatcher()->tick();

        $this->assertSame(
            'queued',
            (string) $this->recipientRow($recipientId)['send_status'],
            'once the backoff has passed it is dispatched again'
        );
    }

    /**
     * Two retry mechanisms, one limit.
     *
     * The queue stops after four attempts. The scheduler is a separate path and
     * knows nothing about that, so a recipient left `pending` after the queue
     * has given up would be enqueued again on the next tick, and the next, for
     * as long as the provider kept refusing. The attempt count on the row is
     * what makes both paths stop in the same place.
     */
    public function testOnceRetriesAreExhaustedTheSchedulerStopsCreatingJobs(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->scriptProvider(
            SendResult::failed('Throttled'),
            SendResult::failed('Throttled'),
            SendResult::failed('Throttled'),
            SendResult::failed('Throttled'),
            SendResult::failed('Throttled'),
            SendResult::failed('Throttled')
        );

        $this->dispatcher()->tick();
        $recipientId = (int) $this->snapshotRows($id)[0]['id'];

        // Six rounds of "tick, run, wait out the backoff" — more than the four
        // attempts a send is allowed.
        for ($round = 0; $round < 6; $round++) {
            try {
                $this->runJobFor($context['organisation_id'], $id, $recipientId);
            } catch (\Throwable) {
                // Both the retryable and the final failure land here.
            }

            $this->bindTenant($context['organisation_id']);
            $this->clock->travel('+4 hours');
            $this->dispatcher()->tick();
        }

        $row = $this->recipientRow($recipientId);

        $this->assertSame('failed', (string) $row['send_status'], 'it gave up rather than looping');
        $this->assertSame(4, (int) $row['attempt_count'], 'and it stopped at the configured limit');
        $this->assertContainsString('Gave up after 4 attempts', (string) $row['last_error']);
    }

    // ---------------------------------------------------------- completion

    /**
     * A campaign with somebody mid-flight, or somebody nobody can account for,
     * has not finished. A completion badge is a claim about what happened.
     */
    public function testACampaignDoesNotCompleteWhileRecipientsAreInFlightOrUnaccountedFor(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->dispatcher()->tick();

        $rows = $this->snapshotRows($id);
        $this->recipientRepository()->claim((int) $rows[0]['id'], 'token-a', 300);
        $this->recipientRepository()->markSent((int) $rows[1]['id'], 'none', 1);

        $this->assertFalse($this->dispatcher()->finalise($id), 'one is still sending');

        // Park the in-flight one, as a lost worker would.
        $this->connection->execute(
            "UPDATE campaign_recipients SET send_status = 'outcome_unknown' WHERE id = ?",
            [(int) $rows[0]['id']]
        );

        $this->assertFalse($this->dispatcher()->finalise($id), 'and now nobody can account for it');
        $this->assertSame(1, $this->recipientRepository()->unreconciled($id));
        $this->assertNotSame('completed', (string) $this->campaignRow($id)['status']);
    }

    // ------------------------------------------------------------ reclaiming

    /**
     * The two populations of abandoned claim, and why they are not the same.
     *
     * One never reached the provider and is safe to send. The other had a
     * request in flight and is not. Treating them alike in either direction is
     * a bug: one loses mail, the other duplicates it.
     */
    public function testReclaimingSeparatesWhatWasNeverSentFromWhatMightHaveBeen(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 2);

        $this->dispatcher()->tick();

        $rows       = $this->snapshotRows($id);
        $repository = $this->recipientRepository();

        $repository->claim((int) $rows[0]['id'], 'never-sent', 1);

        $repository->claim((int) $rows[1]['id'], 'in-flight', 1);
        $repository->markProviderAttempt((int) $rows[1]['id'], 'in-flight');

        $this->clock->travel('+5 minutes');

        $result = $repository->reclaimExpiredClaims();

        $this->assertSame(1, $result['released']);
        $this->assertSame(1, $result['parked']);
        $this->assertSame('pending', (string) $this->recipientRow((int) $rows[0]['id'])['send_status']);
        $this->assertSame('outcome_unknown', (string) $this->recipientRow((int) $rows[1]['id'])['send_status']);
    }

    /** A parked recipient stays parked. Nothing retries it on its own, ever. */
    public function testAParkedRecipientIsNeverPickedUpAgainAutomatically(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->dispatcher()->tick();

        $recipientId = (int) $this->snapshotRows($id)[0]['id'];
        $repository  = $this->recipientRepository();

        $repository->claim($recipientId, 'gone', 1);
        $repository->markProviderAttempt($recipientId, 'gone');

        $this->clock->travel('+1 hour');
        $repository->reclaimExpiredClaims();

        for ($tick = 0; $tick < 3; $tick++) {
            $this->clock->travel('+1 hour');
            $this->dispatcher()->tick();
        }

        $this->assertSame('outcome_unknown', (string) $this->recipientRow($recipientId)['send_status']);
        $this->assertCount(0, $this->logProvider()->sentMessages(), 'nothing was sent on its behalf');
    }

    // ------------------------------------------------------------ internals

    private function runJobFor(int $organisationId, int $campaignId, int $recipientId): void
    {
        /** @var SendCampaignEmail $handler */
        $handler = $this->container->make(SendCampaignEmail::class);

        $handler->handle([
            'organisation_id' => $organisationId,
            'campaign_id'     => $campaignId,
            'recipient_id'    => $recipientId,
        ]);
    }
}
