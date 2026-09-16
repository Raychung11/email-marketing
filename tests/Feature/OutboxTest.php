<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\EmailMessageRepository;
use App\Services\OutboxService;
use Tests\Support\TestCase;

/**
 * The outbox.
 *
 * The distinction under test throughout is between "we handed it over" and "it
 * arrived": they are different claims with different evidence, and a tool that
 * blurs them tells customers their email was delivered when it bounced.
 */
final class OutboxTest extends TestCase
{
    public function testItSeparatesAcceptedFromConfirmedDelivery(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $this->seedMessages([
            ['email' => 'arrived@example.com',   'status' => 'delivered',  'sent' => true],
            ['email' => 'accepted@example.com',  'status' => 'sent',       'sent' => true],
            ['email' => 'bounced@example.com',   'status' => 'bounced',    'sent' => true],
            ['email' => 'waiting@example.com',   'status' => 'queued',     'sent' => false],
            ['email' => 'broken@example.com',    'status' => 'failed',     'sent' => false],
        ]);

        /** @var OutboxService $outbox */
        $outbox  = $this->container->make(OutboxService::class);
        $summary = $outbox->summary([]);

        // Three were accepted by the provider: delivered, sent and bounced. A
        // bounce is still something the provider took off our hands.
        $this->assertSame(3, $summary['sent'], 'accepted count');
        $this->assertSame(1, $summary['arrived'], 'only a delivery notification counts as arrived');
        $this->assertSame(1, $summary['bounced'], 'bounced');
        $this->assertSame(1, $summary['waiting'], 'still queued');
        $this->assertSame(1, $summary['failed'], 'never left the building');

        // Accepted but unconfirmed: exactly the one still sitting at 'sent'.
        $this->assertSame(1, $summary['awaiting_news'], 'accepted with no word back');
    }

    public function testTheScreenListsEveryMessageAndFiltersByStatus(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $this->seedMessages([
            ['email' => 'arrived@example.com', 'status' => 'delivered', 'sent' => true],
            ['email' => 'bounced@example.com', 'status' => 'bounced',   'sent' => true],
        ]);

        $response = $this->get('/outbox');

        $this->assertStatus(200, $response);
        $this->assertContainsString('arrived@example.com', $response->body());
        $this->assertContainsString('bounced@example.com', $response->body());
        // The provider's vocabulary stays in the database and in the filter's
        // option values, never in text a customer is asked to read.
        $this->assertContainsString('Address does not exist', $response->body());
        $this->assertNotContainsString('>soft_bounced', $response->body());
        $this->assertNotContainsString('soft_bounced<', $response->body());

        $filtered = $this->get('/outbox', ['status' => 'bounced']);

        $this->assertStatus(200, $filtered);
        $this->assertContainsString('bounced@example.com', $filtered->body());
        $this->assertNotContainsString('arrived@example.com', $filtered->body());
    }

    /**
     * A status that is not one of ours must not silently empty the list: that
     * reads as "you have never sent anything", which is a much more alarming
     * thing to tell someone than "here is everything".
     */
    public function testAnUnknownStatusFilterIsIgnoredRatherThanReturningNothing(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $this->seedMessages([['email' => 'arrived@example.com', 'status' => 'delivered', 'sent' => true]]);

        $response = $this->get('/outbox', ['status' => 'wandered_off']);

        $this->assertStatus(200, $response);
        $this->assertContainsString('arrived@example.com', $response->body());
    }

    public function testTheStatusTotalsIgnoreTheStatusFilterSoTheOtherOptionsStayVisible(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $this->seedMessages([
            ['email' => 'a@example.com', 'status' => 'delivered', 'sent' => true],
            ['email' => 'b@example.com', 'status' => 'bounced',   'sent' => true],
        ]);

        /** @var OutboxService $outbox */
        $outbox = $this->container->make(OutboxService::class);

        $counts = $outbox->summary(['status' => 'delivered'])['by_status'];

        $this->assertSame(1, $counts['delivered'] ?? 0);
        $this->assertSame(1, $counts['bounced'] ?? 0, 'filtering to delivered must not zero the other options');
    }

    public function testTheLogIsScopedToTheSignedInOrganisation(): void
    {
        $mine = $this->createOrganisation();
        $this->actingAs($mine['user_id'], $mine['organisation_id']);
        $this->seedMessages([['email' => 'mine@example.com', 'status' => 'delivered', 'sent' => true]]);

        $theirs = $this->createOrganisation(['name' => 'Someone Else', 'email' => 'other@example.com']);
        $this->actingAs($theirs['user_id'], $theirs['organisation_id']);
        $this->seedMessages([['email' => 'theirs@example.com', 'status' => 'delivered', 'sent' => true]]);

        $response = $this->get('/outbox');

        $this->assertContainsString('theirs@example.com', $response->body());
        $this->assertNotContainsString('mine@example.com', $response->body());
    }

    public function testTheExportCarriesTheRawStatusAlongsideThePlainEnglishOne(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $this->seedMessages([['email' => 'bounced@example.com', 'status' => 'bounced', 'sent' => true]]);

        $response = $this->get('/outbox/export');

        $this->assertStatus(200, $response);
        $this->assertContainsString('bounced@example.com', $response->body());
        $this->assertContainsString('bounced', $response->body());
        $this->assertContainsString('Address does not exist', $response->body());
    }

    /**
     * The console reports per organisation, so an operator reading one server's
     * numbers never sees two customers' sends added together.
     */
    public function testTheConsoleTotalsAreGroupedByOrganisation(): void
    {
        $mine = $this->createOrganisation(['name' => 'First Business']);
        $this->actingAs($mine['user_id'], $mine['organisation_id']);
        $this->seedMessages([
            ['email' => 'a@example.com', 'status' => 'delivered', 'sent' => true],
            ['email' => 'b@example.com', 'status' => 'delivered', 'sent' => true],
        ]);

        $theirs = $this->createOrganisation(['name' => 'Someone Else', 'email' => 'other@example.com']);
        $this->actingAs($theirs['user_id'], $theirs['organisation_id']);
        $this->seedMessages([['email' => 'c@example.com', 'status' => 'bounced', 'sent' => true]]);

        /** @var OutboxService $outbox */
        $outbox = $this->container->make(OutboxService::class);
        $totals = $outbox->totalsByOrganisation(30);

        $this->assertCount(2, $totals, 'one entry per organisation');
        $this->assertSame(2, $totals['First Business']['delivered'] ?? 0);
        $this->assertSame(1, $totals['Someone Else']['bounced'] ?? 0);
        $this->assertSame(0, $totals['Someone Else']['delivered'] ?? 0, 'no pooling across organisations');
    }

    /** @param array<int,array<string,mixed>> $messages */
    private function seedMessages(array $messages): void
    {
        /** @var EmailMessageRepository $repository */
        $repository = $this->container->make(EmailMessageRepository::class);

        foreach ($messages as $message) {
            $repository->create([
                'email'         => (string) $message['email'],
                'subject'       => 'Spring offer',
                'status'        => (string) $message['status'],
                'message_class' => 'marketing',
                'sent_at'       => $message['sent'] ? gmdate('Y-m-d H:i:s') : null,
                'delivered_at'  => $message['status'] === 'delivered' ? gmdate('Y-m-d H:i:s') : null,
            ]);
        }
    }
}
