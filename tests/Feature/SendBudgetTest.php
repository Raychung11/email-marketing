<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SendBudget;
use Tests\Support\SendsCampaigns;
use Tests\Support\TestCase;

/**
 * The daily send allowance.
 *
 * The original bug was a category error: the budget was computed by counting
 * email_messages rows that already existed, which answers "how much have we
 * sent" when the scheduler needs "how much have we already committed to
 * sending". Work sitting on the queue is committed and not yet counted, so on a
 * system where the workers lag the scheduler — which is every system, briefly,
 * and shared hosting permanently — consecutive ticks handed out the same
 * allowance over and over and the tenant's limit meant nothing.
 */
final class SendBudgetTest extends TestCase
{
    use SendsCampaigns;

    public function setUp(): void
    {
        parent::setUp();
        $this->useFakeDns();
    }

    /**
     * The regression, stated as plainly as it can be: run the scheduler
     * repeatedly with no workers at all, and the limit must still hold.
     */
    public function testRepeatedTicksWithNoWorkerRunningCannotExceedTheDailyLimit(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 10);

        $this->setDailyLimit($context['organisation_id'], 4);

        for ($tick = 0; $tick < 5; $tick++) {
            $this->dispatcher()->tick();
        }

        $this->assertSame(
            4,
            $this->queuedCount($id),
            'five ticks, nothing processed, and still only the day\'s allowance was handed out'
        );
    }

    /** Two campaigns for one tenant share one allowance; they do not each get it. */
    public function testTwoCampaignsForOneTenantShareTheSameAllowance(): void
    {
        $context = $this->sendableOrganisation();
        $first   = $this->readyToSendCampaign($context, 5, 'First');
        $second  = $this->campaignOverEveryone($context, 'Second');

        $this->setDailyLimit($context['organisation_id'], 6);

        $this->dispatcher()->tick();
        $this->dispatcher()->tick();

        $total = $this->queuedCount($first) + $this->queuedCount($second);

        $this->assertSame(6, $total, 'six between them, not six each');
    }

    /**
     * A retry is another attempt at the same send, not a second send. Charging
     * it again would mean a tenant with a flaky provider silently losing
     * allowance to its own retries.
     */
    public function testARetryDoesNotSpendTheAllowanceTwice(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 1);

        $this->setDailyLimit($context['organisation_id'], 1);
        $this->dispatcher()->tick();

        $budget = $this->container->make(SendBudget::class);
        $dayKey = $budget->dayKey('UTC');

        $this->assertSame(1, $budget->reserved($context['organisation_id'], $dayKey));

        // The send fails transiently and goes back in the pool.
        $recipientId = (int) $this->snapshotRows($id)[0]['id'];
        $this->connection->execute(
            "UPDATE campaign_recipients SET send_status = 'pending', available_at = NULL WHERE id = ?",
            [$recipientId]
        );

        $this->dispatcher()->tick();

        $this->assertSame('queued', (string) $this->recipientRow($recipientId)['send_status'], 'it was retried');
        $this->assertSame(
            1,
            $budget->reserved($context['organisation_id'], $dayKey),
            'and it still holds exactly one unit of allowance'
        );
    }

    /**
     * Allowance held by somebody who turned out not to need it comes back —
     * otherwise a tenant loses a little of their limit to every skip, every day,
     * with nothing to show for it.
     */
    public function testAllowanceHeldByASkippedRecipientIsReturned(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 3);

        $this->setDailyLimit($context['organisation_id'], 3);
        $this->dispatcher()->tick();

        $budget = $this->container->make(SendBudget::class);
        $dayKey = $budget->dayKey('UTC');

        $this->assertSame(3, $budget->reserved($context['organisation_id'], $dayKey));

        // One of them unsubscribes and is skipped at send time.
        $this->connection->execute(
            "UPDATE campaign_recipients SET send_status = 'skipped', eligibility_status = 'suppressed',
                    eligibility_reason = 'SUPPRESSED_UNSUBSCRIBE'
             WHERE id = ?",
            [(int) $this->snapshotRows($id)[0]['id']]
        );

        $this->dispatcher()->tick();

        $this->assertSame(
            2,
            $budget->reserved($context['organisation_id'], $dayKey),
            'the skipped one gave its allowance back'
        );
    }

    /** Concurrent reservations are compare-and-set, so they cannot both win. */
    public function testConcurrentReservationsNeverExceedTheLimit(): void
    {
        $context = $this->sendableOrganisation();
        $budget  = $this->container->make(SendBudget::class);
        $dayKey  = $budget->dayKey('UTC');

        $granted = 0;

        for ($i = 0; $i < 20; $i++) {
            $granted += $budget->reserve($context['organisation_id'], $dayKey, 10, 3);
        }

        $this->assertSame(10, $granted, 'never more than the limit, however many callers ask');
        $this->assertSame(10, $budget->reserved($context['organisation_id'], $dayKey));
        $this->assertSame(0, $budget->reserve($context['organisation_id'], $dayKey, 10, 1), 'and then nothing');
    }

    // ----------------------------------------------------------- the day

    /**
     * The day is the tenant's, not UTC. At 09:00 in Kuala Lumpur it is still
     * yesterday in UTC, and a limit that reset at the wrong hour would be
     * indefensible to a customer looking at their own dashboard.
     */
    public function testTheDayBoundaryFollowsTheOrganisationTimezone(): void
    {
        $budget = $this->container->make(SendBudget::class);

        // The suite's frozen clock is 2026-06-15 12:00 UTC.
        $this->clock->setTo('2026-06-15 18:30:00');

        $this->assertSame('2026-06-15', $budget->dayKey('UTC'));
        $this->assertSame('2026-06-16', $budget->dayKey('Asia/Kuala_Lumpur'), 'already tomorrow there');
        $this->assertSame('2026-06-15', $budget->dayKey('America/Los_Angeles'), 'still today there');
    }

    /**
     * Work enqueued at 23:58 and still waiting at 00:02 is going out today, so
     * today's limit is what applies to it. Leaving it holding yesterday's key
     * would let it slip past the new day's allowance entirely.
     */
    public function testWorkCarriedOverMidnightIsChargedAgainstTheNewDay(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->readyToSendCampaign($context, 4);

        $this->setDailyLimit($context['organisation_id'], 2);
        $this->dispatcher()->tick();

        $budget = $this->container->make(SendBudget::class);

        $this->assertSame(2, $budget->reserved($context['organisation_id'], $budget->dayKey('UTC')));

        // Nothing was processed, and the day rolls over.
        $this->clock->travel('+1 day');
        $today = $budget->dayKey('UTC');

        $this->dispatcher()->tick();

        $this->assertSame(
            2,
            $budget->reserved($context['organisation_id'], $today),
            'yesterday\'s queued work is charged against today, because today is when it sends'
        );
        $this->assertSame(2, $this->queuedCount($id), 'and it did not get a second helping');
    }

    // ------------------------------------------------------------ internals

    private function setDailyLimit(int $organisationId, int $limit): void
    {
        $this->connection->execute(
            'UPDATE organisations SET daily_send_limit = ? WHERE id = ?',
            [$limit, $organisationId]
        );

        $this->bindTenant($organisationId);
    }

    private function queuedCount(int $campaignId): int
    {
        return (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM campaign_recipients
             WHERE campaign_id = ? AND send_status IN ('queued', 'sending', 'sent')",
            [$campaignId]
        );
    }
}
