<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendCampaignEmail;
use App\Mail\LogEmailProvider;
use App\Mail\EmailProviderInterface;
use App\Queue\QueueDriver;
use App\Queue\SyncQueueDriver;
use App\Services\CampaignDispatcher;
use App\Services\CampaignService;
use App\Services\LinkTracker;
use App\Support\DnsResolver;
use App\Support\FakeDnsResolver;
use Tests\Support\TestCase;

/**
 * §21 — open and click tracking.
 *
 * The security property that matters here is that the click endpoint is not an
 * open redirect: the token carries a link id, never a URL, so a valid signature
 * still cannot point the redirect anywhere the campaign author did not.
 */
final class TrackingTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->container->instance(DnsResolver::class, new FakeDnsResolver());
    }

    // ------------------------------------------------------------- rewriting

    public function testLinksAreWrappedAndTheOpenPixelIsAppended(): void
    {
        $this->sendAOneRecipientCampaign();

        $html = $this->provider()->sentMessages()[0]->htmlBody;

        $this->assertContainsString('/track/click/', $html);
        $this->assertContainsString('/track/open/', $html);
        $this->assertNotContainsString(
            'href="https://perthplumbing.test/book"',
            $html,
            'The original destination is not left in the markup'
        );
    }

    /**
     * The one link a recipient must be able to trust. Routing it through a
     * tracker adds a failure mode to the mechanism that protects deliverability,
     * for no benefit at all.
     */
    public function testTheUnsubscribeLinkIsNeverWrapped(): void
    {
        $this->sendAOneRecipientCampaign();

        $message = $this->provider()->sentMessages()[0];

        $this->assertContainsString('/unsubscribe/', $message->htmlBody);
        $this->assertNotContainsString(
            '/track/click/',
            (string) $message->allHeaders()['List-Unsubscribe'],
            'The RFC 8058 header points straight at the unsubscribe endpoint'
        );

        // Every tracked href must be a tracking URL; every unsubscribe href must not.
        preg_match_all('/href="([^"]+)"/', $message->htmlBody, $matches);

        foreach ($matches[1] as $href) {
            if (str_contains($href, '/unsubscribe/') || str_contains($href, '/preferences/')) {
                $this->assertNotContainsString('/track/click/', $href);
            }
        }
    }

    public function testUtmParametersAreAppendedWithoutOverwritingTheAuthors(): void
    {
        /** @var LinkTracker $tracker */
        $tracker = $this->container->make(LinkTracker::class);

        $campaign = [
            'id' => 1, 'organisation_id' => 1,
            'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn',
        ];

        $plain = $tracker->appendUtm('https://example.test/offer', $campaign);

        $this->assertContainsString('utm_source=newsletter', $plain);
        $this->assertContainsString('utm_campaign=autumn', $plain);

        // The author may be running their own attribution scheme. Silently
        // overwriting it would corrupt their reporting.
        $authored = $tracker->appendUtm('https://example.test/offer?utm_source=partner', $campaign);

        $this->assertContainsString('utm_source=partner', $authored);
        $this->assertNotContainsString('utm_source=newsletter', $authored);
    }

    // ----------------------------------------------------------- the endpoints

    public function testClickingATrackedLinkRedirectsToTheAuthorsDestination(): void
    {
        $this->sendAOneRecipientCampaign();

        $response = $this->get($this->clickPathFrom($this->provider()->sentMessages()[0]->htmlBody));

        $this->assertStatus(302, $response);
        $this->assertContainsString(
            'https://perthplumbing.test/book',
            (string) $response->headers()['Location']
        );
    }

    /**
     * The token identifies a row, not a destination. Even holding a valid token
     * there is nothing to substitute — and a forged one fails the signature.
     */
    public function testTheClickEndpointCannotBeTurnedIntoAnOpenRedirect(): void
    {
        $this->sendAOneRecipientCampaign();

        $path = $this->clickPathFrom($this->provider()->sentMessages()[0]->htmlBody);

        // Appending, replacing or re-signing the destination: none of it works,
        // because no destination is carried in the first place.
        foreach ([
            $path . '?url=https://evil.test',
            $path . '&redirect=https://evil.test',
            '/track/click/' . base64_encode('{"o":1,"l":1,"url":"https://evil.test"}'),
            '/track/click/tampered',
        ] as $attempt) {
            $response = $this->get(parse_url($attempt, PHP_URL_PATH) ?? $attempt);
            $location = (string) ($response->headers()['Location'] ?? '');

            $this->assertNotContainsString('evil.test', $location, 'Attempt: ' . $attempt);
        }
    }

    public function testAnInvalidClickTokenSendsThePersonSomewhereRealRatherThanAnError(): void
    {
        $response = $this->get('/track/click/not-a-real-token');

        // The recipient did nothing wrong and cannot fix our token.
        $this->assertStatus(302, $response);
        $this->assertNotSame('', (string) ($response->headers()['Location'] ?? ''));
    }

    public function testAClickIsRecordedAgainstTheMessageTheLinkAndTheContact(): void
    {
        $context = $this->sendAOneRecipientCampaign();

        $this->get($this->clickPathFrom($this->provider()->sentMessages()[0]->htmlBody));

        $message = $this->connection->selectOne('SELECT * FROM email_messages LIMIT 1') ?? [];

        $this->assertSame(1, (int) $message['click_count']);
        $this->assertNotNull($message['clicked_at']);

        // `status` tracks what the provider did with the message. An open or a
        // click is engagement, not a delivery state, and overwriting the status
        // with it would lose the fact that the provider never confirmed delivery.
        $this->assertSame('sent', (string) $message['status']);

        $link = $this->connection->selectOne('SELECT * FROM tracked_links LIMIT 1') ?? [];
        $this->assertSame(1, (int) $link['click_count']);
        $this->assertSame(1, (int) $link['unique_click_count']);

        $event = $this->connection->selectOne("SELECT * FROM email_events WHERE event_type = 'click'") ?? [];
        $this->assertSame('internal', (string) $event['provider']);
        $this->assertSame((int) $message['id'], (int) $event['email_message_id']);
        $this->assertSame($context['organisation_id'], (int) $event['organisation_id']);

        $contact = $this->connection->selectOne('SELECT * FROM contacts LIMIT 1') ?? [];
        $this->assertNotNull($contact['last_email_click_at'], 'The CRM engagement timeline is updated');
    }

    public function testTheOpenPixelReturnsAnImageAndIsNeverCached(): void
    {
        $this->sendAOneRecipientCampaign();

        $response = $this->get($this->openPathFrom($this->provider()->sentMessages()[0]->htmlBody));

        $this->assertStatus(200, $response);
        $this->assertSame('image/gif', (string) $response->headers()['Content-Type']);
        $this->assertContainsString('no-store', (string) $response->headers()['Cache-Control']);
        $this->assertTrue(strlen($response->body()) > 0, 'A real pixel, not an empty body');
    }

    /**
     * Opens are weak evidence — privacy proxies pre-fetch images — so repeated
     * fetches within the same minute count once, and the first open is recorded
     * once however many times the pixel is hit.
     */
    public function testRepeatedPixelFetchesDoNotInflateTheOpenCount(): void
    {
        $this->sendAOneRecipientCampaign();

        $path = $this->openPathFrom($this->provider()->sentMessages()[0]->htmlBody);

        $this->get($path);
        $this->get($path);
        $this->get($path);

        $message = $this->connection->selectOne('SELECT * FROM email_messages LIMIT 1') ?? [];

        $this->assertSame(1, (int) $message['open_count']);
        $this->assertNotNull($message['opened_at']);
        $this->assertSame(
            1,
            (int) $this->connection->scalar("SELECT COUNT(*) FROM email_events WHERE event_type = 'open'")
        );
    }

    public function testAnInvalidOpenTokenStillReturnsAPixel(): void
    {
        $response = $this->get('/track/open/nonsense');

        // Anything else would put a broken-image icon in a real customer's email.
        $this->assertStatus(200, $response);
        $this->assertSame('image/gif', (string) $response->headers()['Content-Type']);
        $this->assertSame(
            0,
            (int) $this->connection->scalar("SELECT COUNT(*) FROM email_events WHERE event_type = 'open'")
        );
    }

    public function testCampaignCountersPickUpTrackedEngagement(): void
    {
        $context = $this->sendAOneRecipientCampaign();

        $html = $this->provider()->sentMessages()[0]->htmlBody;
        $this->get($this->openPathFrom($html));
        $this->get($this->clickPathFrom($html));

        $this->bindTenant($context['organisation_id']);

        /** @var \App\Repositories\CampaignRepository $campaigns */
        $campaigns  = $this->container->make(\App\Repositories\CampaignRepository::class);
        $campaignId = (int) ($this->connection->selectOne('SELECT id FROM campaigns LIMIT 1')['id'] ?? 0);
        $campaigns->refreshCounters($campaignId);

        $campaign = $this->connection->selectOne('SELECT * FROM campaigns WHERE id = ?', [$campaignId]) ?? [];

        $this->assertSame(1, (int) $campaign['unique_open_count']);
        $this->assertSame(1, (int) $campaign['unique_click_count']);
    }

    // ------------------------------------------------------------- internals

    private function clickPathFrom(string $html): string
    {
        return $this->pathFrom($html, 'click');
    }

    private function openPathFrom(string $html): string
    {
        return $this->pathFrom($html, 'open');
    }

    private function pathFrom(string $html, string $kind): string
    {
        if (preg_match('#/track/' . $kind . '/([A-Za-z0-9._\-]+)#', $html, $matches) !== 1) {
            $this->fail('No ' . $kind . ' tracking URL found in the rendered email.');
        }

        return '/track/' . $kind . '/' . $matches[1];
    }

    private function provider(): LogEmailProvider
    {
        /** @var LogEmailProvider $provider */
        $provider = $this->container->make(EmailProviderInterface::class);

        return $provider;
    }

    private function driver(): SyncQueueDriver
    {
        /** @var SyncQueueDriver $driver */
        $driver = $this->container->make(QueueDriver::class);

        return $driver;
    }

    /**
     * The whole pipeline, once, so the tests above work on real rendered output
     * rather than a hand-built string.
     *
     * @return array{organisation_id:int,user_id:int}
     */
    private function sendAOneRecipientCampaign(): array
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

        $this->createContact(
            ['email' => 'recipient@example.com', 'first_name' => 'Sam', 'country' => 'US'],
            ['status' => 'granted', 'consent_type' => 'express']
        );

        /** @var \App\Services\SegmentService $segments */
        $segments  = $this->container->make(\App\Services\SegmentService::class);
        $segmentId = $segments->create('Everyone', [
            'match' => 'all',
            'rules' => [['field' => 'email', 'operator' => 'contains', 'value' => '@']],
        ]);

        /** @var CampaignService $service */
        $service = $this->container->make(CampaignService::class);

        $id = $service->create([
            'name'          => 'Autumn service reminder',
            'subject'       => 'Your annual plumbing check is due',
            'campaign_type' => 'service',
            'segment_id'    => $segmentId,
            'utm_campaign'  => 'autumn-service',
        ]);

        $service->update($id, [
            'html_content' => '<p>Hello {{first_name}}, book your check at '
                . '<a href="https://perthplumbing.test/book">our site</a>.</p>'
                . '<p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
        ]);

        $service->submitForReview($id);

        $approver = $this->createUserWithRole($org['organisation_id'], 'ADMIN');
        $this->actingAs($approver, $org['organisation_id']);
        $service->approve($id);
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $service->sendNow($id);

        /** @var CampaignDispatcher $dispatcher */
        $dispatcher = $this->container->make(CampaignDispatcher::class);
        $dispatcher->tick();

        while (($job = $this->driver()->pop('email_marketing', 'test-worker')) !== null) {
            /** @var SendCampaignEmail $handler */
            $handler = $this->container->make(SendCampaignEmail::class);
            $handler->handle($job->payload);
        }

        return ['organisation_id' => $org['organisation_id'], 'user_id' => $org['user_id']];
    }
}
