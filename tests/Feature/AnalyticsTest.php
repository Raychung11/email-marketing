<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AnalyticsService;
use Tests\Support\TestCase;

/**
 * §23 — reporting.
 *
 * Two opinions are being defended here rather than merely implemented: that a
 * click is worth more than an open, and that a rate without its denominator
 * misleads. Both have tests, because both are the kind of thing that quietly
 * regresses into a vanity dashboard.
 */
final class AnalyticsTest extends TestCase
{
    private int $organisationId;

    public function setUp(): void
    {
        parent::setUp();

        $context              = $this->createOrganisation(['name' => 'Perth Plumbing Co']);
        $this->organisationId = $context['organisation_id'];

        $this->actingAs($context['user_id'], $this->organisationId);
    }

    // ------------------------------------------------------ campaign report

    public function testTheFunnelCountsWhatActuallyHappened(): void
    {
        $campaignId = $this->campaign('Autumn service reminder');

        // 10 sent: 8 arrived, of which 3 opened and 2 clicked; 1 bounced, 1 spam.
        $this->messages($campaignId, 6, ['status' => 'delivered']);
        $this->messages($campaignId, 1, ['status' => 'delivered', 'opened_at' => $this->now()]);
        $this->messages($campaignId, 2, [
            'status' => 'delivered', 'opened_at' => $this->now(), 'clicked_at' => $this->now(),
        ]);
        $this->messages($campaignId, 1, ['status' => 'bounced']);
        $this->messages($campaignId, 1, ['status' => 'complained']);

        $funnel = $this->analytics()->campaignFunnel($campaignId);

        $this->assertSame(11, (int) $funnel['sent']);
        $this->assertSame(9, (int) $funnel['delivered']);
        $this->assertSame(3, (int) $funnel['opened']);
        $this->assertSame(2, (int) $funnel['clicked']);
        $this->assertSame(1, (int) $funnel['bounced']);
        $this->assertSame(1, (int) $funnel['complained']);

        // Engagement is measured against what arrived: an address that bounced
        // never had the chance to click.
        $this->assertSame(22.22, $funnel['click_rate']);
    }

    /**
     * A message we never heard back about still counts as delivered once
     * somebody opens or clicks it — the proof is in the behaviour, not in a
     * webhook that may have gone missing.
     */
    public function testEngagementCountsAsProofOfDelivery(): void
    {
        $campaignId = $this->campaign('Test');

        $this->messages($campaignId, 1, ['status' => 'sent', 'clicked_at' => $this->now()]);

        $funnel = $this->analytics()->campaignFunnel($campaignId);

        $this->assertSame(1, (int) $funnel['delivered']);
        $this->assertSame(100.0, $funnel['delivery_rate']);
    }

    public function testUnsubscribesFromTheCampaignAreCounted(): void
    {
        $campaignId = $this->campaign('Test');
        $this->messages($campaignId, 5, ['status' => 'delivered']);

        $this->connection->table('suppressions')->insert([
            'organisation_id'  => $this->organisationId,
            'email'            => 'leaver@example.com',
            'email_normalized' => 'leaver@example.com',
            'email_hash'       => hash('sha256', 'leaver@example.com'),
            'reason'           => 'unsubscribe',
            'source'           => 'link',
            'campaign_id'      => $campaignId,
            'created_at'       => $this->clock->nowString(),
        ]);

        $this->assertSame(1, (int) $this->analytics()->campaignFunnel($campaignId)['unsubscribed']);
    }

    /**
     * The single most useful report in the product: it tells a plumber that
     * nobody pressed "book a service" and forty people pressed the phone number.
     */
    public function testTopLinksRanksWhatPeopleActuallyPressed(): void
    {
        $campaignId = $this->campaign('Autumn service reminder');

        $this->link($campaignId, 'https://perthplumbing.test/book-a-service', 40, 35);
        $this->link($campaignId, 'https://perthplumbing.test/about-us', 4, 4);

        $links = $this->analytics()->topLinks($campaignId);

        $this->assertCount(2, $links);
        $this->assertContainsString('Book a service', (string) $links[0]['label']);
        $this->assertSame(35, (int) $links[0]['people']);
        $this->assertSame(90.9, $links[0]['share']);
        $this->assertSame(9.1, $links[1]['share']);
    }

    public function testSkipReasonsAreExplainedInWordsNotCodes(): void
    {
        $campaignId = $this->campaign('Test');

        foreach ([
            ['suppressed', 'SUPPRESSED_HARD_BOUNCE'],
            ['suppressed', 'SUPPRESSED_HARD_BOUNCE'],
            ['no_consent', 'AU_CONSENT_UNKNOWN'],
        ] as $i => [$eligibility, $reason]) {
            $contactId = $this->createContact(['email' => 'skip-' . $i . '@example.com']);

            $this->connection->table('campaign_recipients')->insert([
                'organisation_id'    => $this->organisationId,
                'campaign_id'        => $campaignId,
                'contact_id'         => $contactId,
                'email'              => 'skip-' . $i . '@example.com',
                'email_normalized'   => 'skip-' . $i . '@example.com',
                'eligibility_status' => $eligibility,
                'eligibility_reason' => $reason,
                'send_status'        => 'skipped',
                'created_at'         => $this->clock->nowString(),
            ]);
        }

        $reasons = $this->analytics()->skipReasons($campaignId);

        $this->assertSame(2, (int) $reasons[0]['count']);
        $this->assertContainsString('does not exist', (string) $reasons[0]['reason']);
        $this->assertNotContainsString('SUPPRESSED_', (string) $reasons[0]['reason']);
    }

    // -------------------------------------------------------- comparison

    /**
     * Without a floor, "your best campaign had a 100% click rate" is a
     * three-recipient test send sitting above a real campaign's 3%.
     */
    public function testATinyTestSendIsNotCrownedTheBestCampaign(): void
    {
        $tiny = $this->campaign('Test to myself');
        $this->messages($tiny, 3, ['status' => 'delivered', 'clicked_at' => $this->now()]);

        $real = $this->campaign('Autumn service reminder');
        $this->messages($real, 100, ['status' => 'delivered']);
        $this->messages($real, 5, ['status' => 'delivered', 'clicked_at' => $this->now()]);

        $highlights = $this->analytics()->highlights();

        $this->assertNotNull($highlights['best']);
        $this->assertSame('Autumn service reminder', (string) $highlights['best']['name']);
    }

    public function testHighlightsSayNothingRatherThanGuessWhenThereIsNoData(): void
    {
        $highlights = $this->analytics()->highlights();

        $this->assertNull($highlights['best']);
        $this->assertNull($highlights['worst']);
    }

    public function testEveryRateTravelsWithTheNumberUnderneathIt(): void
    {
        $campaignId = $this->campaign('Autumn service reminder');
        $this->messages($campaignId, 4, ['status' => 'delivered', 'clicked_at' => $this->now()]);

        $row = $this->analytics()->campaignPerformance()[0];

        // A 100% click rate is only interpretable next to "4".
        $this->assertSame(100.0, $row['click_rate']);
        $this->assertSame(4, (int) $row['clicked']);
        $this->assertSame(4, (int) $row['delivered']);
        $this->assertSame(4, (int) $row['sent']);
    }

    // ------------------------------------------------------- inbox health

    public function testTheVerdictRefusesToJudgeASmallSample(): void
    {
        $campaignId = $this->campaign('Test');
        $this->messages($campaignId, 10, ['status' => 'bounced']);

        $report = $this->analytics()->inboxDelivery();

        // 100% bounce rate from ten emails is not evidence of anything.
        $this->assertSame('unknown', (string) $report['verdict']['level']);
        $this->assertContainsString('Not enough sent', (string) $report['verdict']['headline']);
    }

    public function testAHighComplaintRateIsCalledOutAboveEverythingElse(): void
    {
        $campaignId = $this->campaign('Big send');
        $this->messages($campaignId, 195, ['status' => 'delivered']);
        $this->messages($campaignId, 5, ['status' => 'complained']);

        $verdict = $this->analytics()->inboxDelivery()['verdict'];

        $this->assertSame('bad', (string) $verdict['level']);
        $this->assertContainsString('marking your email as spam', (string) $verdict['headline']);
        // Plain words, and something to actually do about it.
        $this->assertContainsString('do not remember signing up', (string) $verdict['detail']);
    }

    public function testACleanSenderIsToldSoPlainly(): void
    {
        $campaignId = $this->campaign('Big send');
        $this->messages($campaignId, 500, ['status' => 'delivered']);

        $verdict = $this->analytics()->inboxDelivery()['verdict'];

        $this->assertSame('good', (string) $verdict['level']);
        $this->assertContainsString('getting through', (string) $verdict['headline']);
    }

    /**
     * The most common shape of a deliverability problem: Gmail junking your mail
     * while Outlook delivers it fine. One overall number hides it completely.
     */
    public function testDeliveryIsBrokenDownByMailboxProvider(): void
    {
        $campaignId = $this->campaign('Big send');

        $this->messages($campaignId, 50, ['status' => 'bounced'], 'gmail.com');
        $this->messages($campaignId, 50, ['status' => 'delivered'], 'gmail.com');
        $this->messages($campaignId, 60, ['status' => 'delivered'], 'outlook.com');

        $providers = [];

        foreach ($this->analytics()->inboxDelivery()['providers'] as $row) {
            $providers[(string) $row['provider']] = $row;
        }

        $this->assertSame('Gmail', (string) $providers['Gmail']['provider'], 'Domains get friendly names');
        $this->assertSame(50.0, $providers['Gmail']['delivery_rate']);
        $this->assertSame(100.0, $providers['Outlook / Hotmail']['delivery_rate']);
    }

    public function testReportingNeverReachesIntoAnotherTenant(): void
    {
        $mine = $this->campaign('Mine');
        $this->messages($mine, 5, ['status' => 'delivered']);

        $other = $this->createOrganisation(['name' => 'Someone Else']);
        $this->actingAs($other['user_id'], $other['organisation_id']);

        $theirs = $this->campaign('Theirs');
        $this->messages($theirs, 900, ['status' => 'delivered']);

        // Back to the first tenant: their numbers must be untouched by the second.
        $this->bindTenant($this->organisationId);

        $this->assertSame(5, (int) $this->analytics()->inboxDelivery()['totals']['sent']);
        $this->assertCount(1, $this->analytics()->campaignPerformance());
    }

    // ------------------------------------------------------------- screens

    public function testTheReportingScreensRender(): void
    {
        $campaignId = $this->campaign('Autumn service reminder');
        $this->messages($campaignId, 200, ['status' => 'delivered']);
        $this->messages($campaignId, 12, ['status' => 'delivered', 'clicked_at' => $this->now()]);
        $this->link($campaignId, 'https://perthplumbing.test/book-a-service', 12, 12);

        $overview = $this->get('/analytics');
        $this->assertStatus(200, $overview);
        $this->assertContainsString('How your email is doing', $overview->body());

        $campaigns = $this->get('/analytics/campaigns');
        $this->assertStatus(200, $campaigns);
        $this->assertContainsString('Autumn service reminder', $campaigns->body());
        $this->assertContainsString('rough guide', $campaigns->body(), 'The open-rate caveat is on the page');

        $inbox = $this->get('/analytics/deliverability');
        $this->assertStatus(200, $inbox);
        $this->assertContainsString('Is your email getting through?', $inbox->body());
        $this->assertContainsString('getting through', $inbox->body());
    }

    public function testTheWindowCannotBeSetToSomethingAbsurd(): void
    {
        // An unchecked ?days= would let anybody ask for a million-day report.
        $response = $this->get('/analytics/campaigns', ['days' => '99999999']);

        $this->assertStatus(200, $response);
        $this->assertContainsString('last 90 days', $response->body());
    }

    // ------------------------------------------------------------ internals

    private function analytics(): AnalyticsService
    {
        return $this->container->make(AnalyticsService::class);
    }

    private function now(): string
    {
        return $this->clock->nowString();
    }

    private function campaign(string $name): int
    {
        return $this->connection->table('campaigns')->insert([
            'organisation_id' => $this->tenant->organisationId(),
            'uuid'            => uuid4(),
            'name'            => $name,
            'subject'         => $name,
            'campaign_type'   => 'service',
            'message_class'   => 'marketing',
            'status'          => 'completed',
            'send_started_at' => $this->clock->nowString(),
            'created_at'      => $this->clock->nowString(),
            'updated_at'      => $this->clock->nowString(),
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function messages(int $campaignId, int $count, array $attributes, string $domain = 'example.com'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $email = 'person-' . bin2hex(random_bytes(5)) . '@' . $domain;

            $this->connection->table('email_messages')->insert(array_merge([
                'uuid'             => uuid4(),
                'organisation_id'  => $this->tenant->organisationId(),
                'campaign_id'      => $campaignId,
                'message_class'    => 'marketing',
                'provider'         => 'log',
                'email'            => $email,
                'email_normalized' => $email,
                'subject'          => 'Subject',
                'sent_at'          => $this->clock->nowString(),
                'created_at'       => $this->clock->nowString(),
            ], $attributes));
        }
    }

    private function link(int $campaignId, string $url, int $clicks, int $people): void
    {
        $this->connection->table('tracked_links')->insert([
            'organisation_id'    => $this->tenant->organisationId(),
            'owner_type'         => 'campaign',
            'owner_id'           => $campaignId,
            'link_hash'          => substr(hash('sha256', $url), 0, 40),
            'original_url'       => $url,
            'click_count'        => $clicks,
            'unique_click_count' => $people,
            'created_at'         => $this->clock->nowString(),
        ]);
    }
}
