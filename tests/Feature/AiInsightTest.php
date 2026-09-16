<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\AiProviderInterface;
use App\Services\AiInsightService;
use Tests\Support\ScriptedAiProvider;
use Tests\Support\TestCase;

/**
 * §26 — explaining a campaign.
 *
 * One rule is on trial here: the model may explain, it may not count. Every
 * figure is measured and handed to it as fact, and anything it writes containing
 * a number we cannot account for is dropped. The failure this prevents is not a
 * clumsy sentence — it is a business owner repeating "that campaign brought in
 * $4,200" to their accountant when nothing of the sort happened.
 */
final class AiInsightTest extends TestCase
{
    private ScriptedAiProvider $ai;

    private int $campaignId;

    public function setUp(): void
    {
        parent::setUp();

        $this->ai = new ScriptedAiProvider();
        $this->container->instance(AiProviderInterface::class, $this->ai);

        $context = $this->createOrganisation(['name' => 'Perth Plumbing Co']);
        $this->actingAs($context['user_id'], $context['organisation_id']);

        $this->campaignId = $this->campaignWithResults();
    }

    // ------------------------------------------------------- the figure check

    /** A number we never supplied costs the model the whole sentence. */
    public function testASentenceQuotingAnInventedFigureIsDropped(): void
    {
        $this->ai->willReturn([
            'headline'    => 'A solid result for a reminder campaign.',
            'summary'     => 'Twelve people pressed the booking link. '
                . 'That is worth about $4,200 in bookings based on your average job value. '
                . 'The subject line did its job.',
            'suggestions' => ['Send this again in six months to anyone who did not open it.'],
        ]);

        $review = $this->service()->reviewCampaign($this->campaignId);

        $this->assertNotContainsString('4,200', (string) $review['summary']);
        $this->assertNotContainsString('$4,200', (string) $review['summary']);
        $this->assertContainsString('The subject line did its job', (string) $review['summary']);
        $this->assertCount(1, $review['dropped'], 'And we record that something was removed');
    }

    public function testFiguresWeSuppliedSurviveIntact(): void
    {
        $this->ai->willReturn([
            'headline'    => '12 of 200 people pressed something.',
            'summary'     => 'A click rate of 6% from 200 people is a good showing.',
            'suggestions' => [],
        ]);

        $review = $this->service()->reviewCampaign($this->campaignId);

        $this->assertContainsString('12 of 200', (string) $review['headline']);
        $this->assertContainsString('6%', (string) $review['summary']);
        $this->assertCount(0, $review['dropped']);
    }

    /**
     * If the headline itself is thrown away, the user still gets a straight
     * answer — a measured one.
     */
    public function testAnUnverifiableHeadlineFallsBackToAMeasuredOne(): void
    {
        $this->ai->willReturn([
            'headline'    => 'Your best campaign yet: 47 new customers.',
            'summary'     => 'Good work.',
            'suggestions' => [],
        ]);

        $review = $this->service()->reviewCampaign($this->campaignId);

        $this->assertNotContainsString('47', (string) $review['headline']);
        $this->assertContainsString('12 of 200', (string) $review['headline']);
        $this->assertContainsString('pressed something', (string) $review['headline']);
    }

    public function testSmallStructuralNumbersAreNotTreatedAsClaims(): void
    {
        $this->ai->willReturn([
            'headline'    => 'A decent result.',
            'summary'     => 'There are 3 things worth changing here, and the first is the send time.',
            'suggestions' => [],
        ]);

        $review = $this->service()->reviewCampaign($this->campaignId);

        // Flagging "3 things" would make the check useless through noise.
        $this->assertContainsString('3 things worth changing', (string) $review['summary']);
        $this->assertCount(0, $review['dropped']);
    }

    public function testAnInventedFigureInASuggestionIsDroppedToo(): void
    {
        $this->ai->willReturn([
            'headline'    => 'A decent result.',
            'summary'     => 'Nothing alarming here.',
            'suggestions' => [
                'Try sending on a Tuesday morning.',
                'Your 850 lapsed customers are the obvious next audience.',
            ],
        ]);

        $review = $this->service()->reviewCampaign($this->campaignId);

        $this->assertCount(1, $review['suggestions']);
        $this->assertContainsString('Tuesday morning', (string) $review['suggestions'][0]);
    }

    // --------------------------------------------------------- what it is given

    public function testTheModelIsGivenTheRealFiguresSoItHasNoReasonToGuess(): void
    {
        $this->ai->willReturn(['headline' => 'Fine.', 'summary' => '', 'suggestions' => []]);

        $this->service()->reviewCampaign($this->campaignId);

        $prompt = $this->ai->lastPrompt();

        $this->assertContainsString('sent: 200', $prompt);
        $this->assertContainsString('clicked something: 12', $prompt);
        $this->assertContainsString('these are measured, and the only numbers you may use', $prompt);
        // It is told the open rate is not to be trusted, in the same breath as
        // being given it.
        $this->assertContainsString('unreliable', $prompt);
    }

    public function testACampaignThatNeverWentOutIsNotReviewed(): void
    {
        $empty = $this->container->make(\App\Services\CampaignService::class)->create([
            'name'          => 'Never sent',
            'subject'       => 'Hello',
            'campaign_type' => 'newsletter',
        ]);

        $review = $this->service()->reviewCampaign($empty);

        $this->assertFalse((bool) $review['ok']);
        $this->assertContainsString('has not been sent yet', (string) $review['headline']);
        $this->assertSame(0, (int) $this->connection->scalar('SELECT COUNT(*) FROM ai_requests'));
    }

    // ------------------------------------------------------------ how it is kept

    public function testTheReviewIsStoredAndNeverLabelledAsMeasured(): void
    {
        $this->ai->willReturn([
            'headline'    => 'A decent result.',
            'summary'     => 'Nothing alarming.',
            'suggestions' => ['Try a Tuesday.'],
        ]);

        $this->service()->reviewCampaign($this->campaignId);

        $stored = $this->service()->storedReview($this->campaignId);

        $this->assertNotNull($stored);
        $this->assertSame('campaign_review', (string) $stored['recommendation_type']);
        // The figures underneath are measured; this sentence about them is not,
        // and the label has to say so.
        $this->assertSame('ai_recommendation', (string) $stored['data_basis']);
        $this->assertSame($this->campaignId, (int) $stored['metrics']['campaign_id']);
    }

    public function testASpamComplaintMakesTheReviewHighImpact(): void
    {
        $this->connection->execute(
            "UPDATE email_messages SET status = 'complained' WHERE campaign_id = ? AND id IN
             (SELECT id FROM email_messages WHERE campaign_id = ? LIMIT 1)",
            [$this->campaignId, $this->campaignId]
        );

        $this->ai->willReturn(['headline' => 'Worth a look.', 'summary' => '', 'suggestions' => []]);

        $this->service()->reviewCampaign($this->campaignId);

        $this->assertSame('high', (string) $this->service()->storedReview($this->campaignId)['impact']);
    }

    public function testAProviderFailureLeavesTheMeasuredNumbersStanding(): void
    {
        $this->ai->willFail('upstream exploded');

        $review = $this->service()->reviewCampaign($this->campaignId);

        $this->assertFalse((bool) $review['ok']);
        $this->assertContainsString('numbers above are still correct', (string) $review['headline']);
    }

    public function testTheReviewIsAuditedAsAnAiAction(): void
    {
        $this->ai->willReturn(['headline' => 'Fine.', 'summary' => '', 'suggestions' => []]);

        $this->service()->reviewCampaign($this->campaignId);

        $audit = $this->connection->selectOne(
            "SELECT * FROM audit_logs WHERE action = 'ai_campaign_reviewed'"
        ) ?? [];

        $this->assertSame('ai', (string) $audit['actor_type']);
        $this->assertSame($this->campaignId, (int) $audit['entity_id']);
    }

    // ------------------------------------------------------------- internals

    private function service(): AiInsightService
    {
        return $this->container->make(AiInsightService::class);
    }

    /** 200 delivered, 12 of them clicked. */
    private function campaignWithResults(): int
    {
        $campaignId = $this->connection->table('campaigns')->insert([
            'organisation_id' => $this->tenant->organisationId(),
            'uuid'            => uuid4(),
            'name'            => 'Autumn service reminder',
            'subject'         => 'Your annual check is due',
            'campaign_type'   => 'service',
            'message_class'   => 'marketing',
            'status'          => 'completed',
            'send_started_at' => $this->clock->nowString(),
            'created_at'      => $this->clock->nowString(),
            'updated_at'      => $this->clock->nowString(),
        ]);

        for ($i = 0; $i < 200; $i++) {
            $email = 'person-' . $i . '@example.com';

            $this->connection->table('email_messages')->insert([
                'uuid'             => uuid4(),
                'organisation_id'  => $this->tenant->organisationId(),
                'campaign_id'      => $campaignId,
                'message_class'    => 'marketing',
                'provider'         => 'log',
                'email'            => $email,
                'email_normalized' => $email,
                'subject'          => 'Your annual check is due',
                'status'           => 'delivered',
                'sent_at'          => $this->clock->nowString(),
                'clicked_at'       => $i < 12 ? $this->clock->nowString() : null,
                'created_at'       => $this->clock->nowString(),
            ]);
        }

        return $campaignId;
    }
}
