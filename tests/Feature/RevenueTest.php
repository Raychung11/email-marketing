<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AttributionService;
use App\Services\EventTrackingService;
use App\Services\LeadService;
use Tests\Support\TestCase;

/**
 * §28 — enquiries, website events, and what email is worth.
 *
 * Attribution is a decision, not a fact. The tests here are mostly about that:
 * the decision is recorded when it is made, it does not silently rewrite itself
 * when the settings change, and a click always beats an open.
 */
final class RevenueTest extends TestCase
{
    /** @var array{organisation_id:int,user_id:int} */
    private array $context;

    public function setUp(): void
    {
        parent::setUp();

        $org = $this->createOrganisation(['name' => 'Perth Plumbing Co', 'currency' => 'AUD']);
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $this->context = ['organisation_id' => $org['organisation_id'], 'user_id' => $org['user_id']];
    }

    // ------------------------------------------------------------ attribution

    public function testASaleAfterAClickIsCreditedToThatCampaign(): void
    {
        $contactId  = $this->createContact(['email' => 'buyer@example.com']);
        $campaignId = $this->campaign('Autumn service reminder');

        $this->clicked($contactId, $campaignId, $this->clock->agoString(3));

        $result = $this->attribution()->record([
            'contact_id' => $contactId,
            'value'      => 480.00,
            'external_id' => 'INV-1001',
        ]);

        $this->assertSame($campaignId, (int) $result['attribution']['campaign_id']);
        $this->assertSame('last_click', (string) $result['attribution']['model']);
        $this->assertSame(30, (int) $result['attribution']['window_days']);
    }

    /**
     * An open may mean a mail server fetched an image. A click means a person
     * did something.
     */
    public function testAnOpenOnItsOwnEarnsNoCredit(): void
    {
        $contactId  = $this->createContact(['email' => 'opener@example.com']);
        $campaignId = $this->campaign('Autumn service reminder');

        $this->connection->table('email_messages')->insert($this->message($contactId, $campaignId, [
            'opened_at' => $this->clock->agoString(2),
        ]));

        $result = $this->attribution()->record(['contact_id' => $contactId, 'value' => 100.00]);

        $this->assertNull($result['attribution']['campaign_id']);
        $this->assertSame('none', (string) $result['attribution']['model']);
    }

    public function testAClickOlderThanTheWindowEarnsNoCredit(): void
    {
        $contactId  = $this->createContact(['email' => 'old@example.com']);
        $campaignId = $this->campaign('Ancient history');

        // Ninety days ago, against a thirty-day window.
        $this->clicked($contactId, $campaignId, $this->clock->agoString(90));

        $result = $this->attribution()->record(['contact_id' => $contactId, 'value' => 100.00]);

        $this->assertNull($result['attribution']['campaign_id']);
    }

    public function testAClickAfterTheSaleEarnsNoCredit(): void
    {
        $contactId  = $this->createContact(['email' => 'after@example.com']);
        $campaignId = $this->campaign('Too late');

        $this->clicked($contactId, $campaignId, $this->clock->nowString());

        // The sale happened a week before the click. It did not cause anything.
        $result = $this->attribution()->record([
            'contact_id'  => $contactId,
            'value'       => 100.00,
            'occurred_at' => $this->clock->agoString(7),
        ]);

        $this->assertNull($result['attribution']['campaign_id']);
    }

    public function testTheMostRecentClickWinsUnderLastClick(): void
    {
        $contactId = $this->createContact(['email' => 'multi@example.com']);
        $first     = $this->campaign('First touch');
        $latest    = $this->campaign('Last touch');

        $this->clicked($contactId, $first, $this->clock->agoString(10));
        $this->clicked($contactId, $latest, $this->clock->agoString(1));

        $result = $this->attribution()->record(['contact_id' => $contactId, 'value' => 100.00]);

        $this->assertSame($latest, (int) $result['attribution']['campaign_id']);
    }

    public function testFirstClickCanBeChosenInstead(): void
    {
        $this->connection->execute(
            "UPDATE organisations SET attribution_model = 'first_click' WHERE id = ?",
            [$this->context['organisation_id']]
        );
        $this->bindTenant($this->context['organisation_id']);

        $contactId = $this->createContact(['email' => 'multi@example.com']);
        $first     = $this->campaign('First touch');
        $latest    = $this->campaign('Last touch');

        $this->clicked($contactId, $first, $this->clock->agoString(10));
        $this->clicked($contactId, $latest, $this->clock->agoString(1));

        $result = $this->attribution()->record(['contact_id' => $contactId, 'value' => 100.00]);

        $this->assertSame($first, (int) $result['attribution']['campaign_id']);
    }

    /**
     * The decision is recorded at the moment it is made and stands. Otherwise
     * changing a setting would silently rewrite last quarter, and a business
     * could not reconcile two printouts of the same period.
     */
    public function testChangingTheWindowDoesNotRewriteHistory(): void
    {
        $contactId  = $this->createContact(['email' => 'buyer@example.com']);
        $campaignId = $this->campaign('Autumn service reminder');

        $this->clicked($contactId, $campaignId, $this->clock->agoString(3));
        $this->attribution()->record(['contact_id' => $contactId, 'value' => 480.00]);

        $before = $this->attribution()->summary(90)['attributed_value'];

        $this->connection->execute(
            'UPDATE organisations SET attribution_window_days = 1 WHERE id = ?',
            [$this->context['organisation_id']]
        );
        $this->bindTenant($this->context['organisation_id']);

        $this->assertSame($before, $this->attribution()->summary(90)['attributed_value']);
    }

    /** Payment gateways retry. The day's takings must not double. */
    public function testARetriedConversionIsNotCountedTwice(): void
    {
        $contactId = $this->createContact(['email' => 'buyer@example.com']);

        $payload = ['contact_id' => $contactId, 'value' => 480.00, 'external_id' => 'INV-1001'];

        $first  = $this->attribution()->record($payload);
        $second = $this->attribution()->record($payload);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(480.0, $this->attribution()->summary(90)['total_value']);
    }

    public function testASaleUpdatesWhatWeKnowAboutTheCustomer(): void
    {
        $contactId = $this->createContact(['email' => 'buyer@example.com']);

        $this->attribution()->record(['contact_id' => $contactId, 'value' => 480.00]);
        $this->attribution()->record(['contact_id' => $contactId, 'value' => 120.00]);

        $contact = $this->connection->selectOne('SELECT * FROM contacts WHERE id = ?', [$contactId]) ?? [];

        $this->assertSame(600.0, (float) $contact['total_revenue']);
        $this->assertSame(2, (int) $contact['purchase_count']);
        $this->assertNotNull($contact['first_purchase_at']);
    }

    /** A sale dated next year would sit outside every report and never be counted. */
    public function testASaleDatedInTheFutureIsClampedToNow(): void
    {
        $contactId = $this->createContact(['email' => 'buyer@example.com']);

        $this->attribution()->record([
            'contact_id'  => $contactId,
            'value'       => 100.00,
            'occurred_at' => '2099-01-01 00:00:00',
        ]);

        $this->assertSame(1, (int) $this->attribution()->summary(1)['conversions']);
    }

    // -------------------------------------------------------- website events

    public function testAnAnonymousVisitorStaysAnonymousUntilTheyIdentifyThemselves(): void
    {
        $events = $this->events();

        $events->record(['event_name' => 'page_view', 'anonymous_id' => 'abc123',
            'page_url' => 'https://perthplumbing.test/boilers']);

        $row = $this->connection->selectOne('SELECT * FROM tracking_events LIMIT 1') ?? [];

        $this->assertNull($row['contact_id'], 'We do not guess who they are');
        $this->assertSame('abc123', (string) $row['anonymous_id']);
    }

    public function testIdentifyingSomebodyBackfillsWhatTheyDidBefore(): void
    {
        $events    = $this->events();
        $contactId = $this->createContact(['email' => 'visitor@example.com']);

        $events->record(['event_name' => 'page_view', 'anonymous_id' => 'abc123']);
        $events->record(['event_name' => 'pricing_view', 'anonymous_id' => 'abc123']);

        $updated = $events->identify('abc123', $contactId);

        $this->assertSame(2, $updated, 'Their earlier browsing is not lost');
        $this->assertCount(2, $events->timelineFor($contactId));
    }

    public function testLaterEventsFromAKnownBrowserAttachAutomatically(): void
    {
        $events    = $this->events();
        $contactId = $this->createContact(['email' => 'visitor@example.com']);

        $events->record(['event_name' => 'page_view', 'anonymous_id' => 'abc123']);
        $events->identify('abc123', $contactId);
        $events->record(['event_name' => 'pricing_view', 'anonymous_id' => 'abc123']);

        // The backfilled page view plus the new one, both attached without the
        // site having to say who they are a second time.
        $this->assertCount(2, $events->timelineFor($contactId));
    }

    /**
     * Real websites put session tokens, password reset links and email addresses
     * in query strings. None of that belongs in an analytics table.
     */
    public function testTheQueryStringIsStrippedBeforeAnythingIsStored(): void
    {
        $this->events()->record([
            'event_name' => 'page_view',
            'page_url'   => 'https://perthplumbing.test/account?reset_token=secret&email=bob@example.com',
        ]);

        $stored = (string) $this->connection->scalar('SELECT page_url FROM tracking_events LIMIT 1');

        $this->assertSame('https://perthplumbing.test/account', $stored);
        $this->assertNotContainsString('secret', $stored);
        $this->assertNotContainsString('bob@example.com', $stored);
    }

    // ---------------------------------------------------------------- leads

    public function testAnEnquiryAttachesToSomebodyWeAlreadyKnow(): void
    {
        $contactId = $this->createContact(['email' => 'known@example.com']);

        $leadId = $this->leads()->create(['email' => 'known@example.com', 'title' => 'Boiler quote']);

        $lead = $this->leads()->findOrFail($leadId);

        $this->assertSame($contactId, (int) $lead['contact_id'], 'Not a second copy of the same person');
        $this->assertSame(1, (int) $this->connection->scalar('SELECT COUNT(*) FROM contacts'));
    }

    /**
     * Filling in a contact form is a request to be answered, not permission to
     * be marketed to.
     */
    public function testAnEnquiryDoesNotGrantMarketingPermission(): void
    {
        $leadId    = $this->leads()->create(['email' => 'brandnew@example.com', 'title' => 'Quote please']);
        $contactId = (int) $this->leads()->findOrFail($leadId)['contact_id'];

        $consent = $this->connection->selectOne(
            'SELECT * FROM contact_consents WHERE contact_id = ? ORDER BY id DESC LIMIT 1',
            [$contactId]
        );

        $this->assertTrue(
            $consent === null || (string) $consent['status'] !== 'granted',
            'An enquiry is not an opt-in'
        );
    }

    public function testTheScoreIsExplainedPointByPoint(): void
    {
        $contactId = $this->createContact(['email' => 'hot@example.com', 'phone' => '+61 8 5550 1000']);

        $this->connection->execute(
            'UPDATE contacts SET purchase_count = 3, last_email_click_at = ? WHERE id = ?',
            [$this->clock->agoString(2), $contactId]
        );

        $leadId = $this->leads()->create([
            'email'   => 'hot@example.com',
            'title'   => 'Bathroom refit',
            'enquiry' => str_repeat('We need the whole bathroom doing before Christmas. ', 3),
        ]);

        $scoring = $this->leads()->score($leadId);

        $this->assertTrue($scoring['score'] >= 45);
        $this->assertSame('hot', (string) $scoring['temperature']);

        // A number nobody can account for is a number people ignore.
        $keys = array_map(static fn (array $r): string => (string) $r['key'], $scoring['reasons']);
        $this->assertTrue(in_array('has_phone', $keys, true));
        $this->assertTrue(in_array('existing_customer', $keys, true));
        $this->assertTrue(in_array('clicked_recent_email', $keys, true));

        foreach ($scoring['reasons'] as $reason) {
            $this->assertNotSame('', (string) $reason['why'], 'Every point says why');
        }
    }

    public function testUnansweredEnquiriesAreTheHeadline(): void
    {
        $this->leads()->create(['email' => 'waiting@example.com', 'title' => 'Quote']);

        // Yesterday's, still unanswered.
        $this->connection->execute(
            "UPDATE leads SET created_at = ?",
            [$this->clock->agoString(2)]
        );

        $this->assertCount(1, $this->leads()->unanswered());

        $leadId = (int) $this->connection->scalar('SELECT id FROM leads LIMIT 1');
        $this->leads()->markResponded($leadId);

        $this->assertCount(0, $this->leads()->unanswered());
    }

    public function testTheFirstReplyIsTheOneThatCounts(): void
    {
        $leadId = $this->leads()->create(['email' => 'waiting@example.com']);

        $this->leads()->markResponded($leadId);
        $first = (string) $this->leads()->findOrFail($leadId)['first_response_at'];

        $this->leads()->markResponded($leadId);

        $this->assertSame($first, (string) $this->leads()->findOrFail($leadId)['first_response_at']);
    }

    // -------------------------------------------------------------- screens

    public function testTheRevenueAndEnquiryScreensRender(): void
    {
        $contactId  = $this->createContact(['email' => 'buyer@example.com']);
        $campaignId = $this->campaign('Autumn service reminder');
        $this->clicked($contactId, $campaignId, $this->clock->agoString(3));
        $this->attribution()->record(['contact_id' => $contactId, 'value' => 480.00]);
        $this->leads()->create(['email' => 'enquirer@example.com', 'title' => 'Quote']);

        foreach ([
            '/analytics/revenue' => 'Sales we can trace to an email',
            '/analytics/engagement' => 'Who reads your email',
            '/leads' => 'Enquiries',
            '/leads/pipeline' => 'Pipeline',
        ] as $path => $expected) {
            $response = $this->get($path);

            $this->assertStatus(200, $response);
            $this->assertContainsString($expected, $response->body(), 'on ' . $path);
        }
    }

    // ------------------------------------------------------------ internals

    private function attribution(): AttributionService
    {
        return $this->container->make(AttributionService::class);
    }

    private function events(): EventTrackingService
    {
        return $this->container->make(EventTrackingService::class);
    }

    private function leads(): LeadService
    {
        return $this->container->make(LeadService::class);
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
            'send_started_at' => $this->clock->agoString(5),
            'created_at'      => $this->clock->agoString(5),
            'updated_at'      => $this->clock->agoString(5),
        ]);
    }

    private function clicked(int $contactId, int $campaignId, string $when): void
    {
        $this->connection->table('email_messages')->insert(
            $this->message($contactId, $campaignId, ['clicked_at' => $when])
        );
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function message(int $contactId, int $campaignId, array $extra): array
    {
        return array_merge([
            'uuid'             => uuid4(),
            'organisation_id'  => $this->tenant->organisationId(),
            'campaign_id'      => $campaignId,
            'contact_id'       => $contactId,
            'message_class'    => 'marketing',
            'provider'         => 'log',
            'email'            => 'buyer@example.com',
            'email_normalized' => 'buyer@example.com',
            'subject'          => 'Subject',
            'status'           => 'delivered',
            'sent_at'          => $this->clock->agoString(5),
            'created_at'       => $this->clock->agoString(5),
        ], $extra);
    }
}
