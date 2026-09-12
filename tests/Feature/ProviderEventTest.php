<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProviderEventProcessor;
use App\Services\SuppressionService;
use Tests\Support\TestCase;

/**
 * §29 / §61 — inbound provider events.
 *
 * Providers redeliver; the pipeline must be idempotent, and bounce handling must
 * distinguish "this mailbox is gone" from "this mailbox is full today".
 */
final class ProviderEventTest extends TestCase
{
    public function testADuplicateProviderEventIsIgnored(): void
    {
        $context = $this->messageFixture();

        /** @var ProviderEventProcessor $processor */
        $processor = $this->container->make(ProviderEventProcessor::class);

        $event = [
            'provider'            => 'ses',
            'provider_event_id'   => 'sns-message-0001',
            'event_type'          => 'delivery',
            'email'               => $context['email'],
            'provider_message_id' => $context['provider_message_id'],
            'event_at'            => '2026-06-15 12:00:05',
        ];

        $first  = $processor->process($event);
        $second = $processor->process($event);
        $third  = $processor->process($event);

        $this->assertTrue($first['recorded'], 'The first delivery is recorded');
        $this->assertFalse($second['recorded'], 'A redelivery is not recorded again');
        $this->assertSame('duplicate', $second['reason']);
        $this->assertFalse($third['recorded']);

        $count = (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM email_events WHERE event_type = 'delivery'"
        );

        $this->assertSame(1, $count, 'Exactly one event row, however many times the provider sends it');
    }

    public function testOpenAndClickCountsAreNotInflatedByRedelivery(): void
    {
        $context = $this->messageFixture();

        /** @var ProviderEventProcessor $processor */
        $processor = $this->container->make(ProviderEventProcessor::class);

        for ($i = 0; $i < 3; $i++) {
            $processor->process([
                'provider_event_id'   => 'open-event-1',
                'event_type'          => 'open',
                'email'               => $context['email'],
                'provider_message_id' => $context['provider_message_id'],
            ]);
        }

        // A genuinely distinct second open.
        $processor->process([
            'provider_event_id'   => 'open-event-2',
            'event_type'          => 'open',
            'email'               => $context['email'],
            'provider_message_id' => $context['provider_message_id'],
        ]);

        $message = $this->connection->selectOne('SELECT * FROM email_messages WHERE id = ?', [$context['message_id']]);

        $this->assertSame(2, (int) $message['open_count'], 'Two real opens, not four');
        $this->assertNotNull($message['opened_at']);
    }

    /** §84 — a permanent bounce suppresses. */
    public function testAPermanentBounceSuppressesTheAddress(): void
    {
        $context = $this->messageFixture();

        /** @var ProviderEventProcessor $processor */
        $processor = $this->container->make(ProviderEventProcessor::class);

        $processor->process([
            'provider_event_id'   => 'bounce-perm-1',
            'event_type'          => 'bounce',
            'bounce_type'         => 'Permanent',
            'bounce_subtype'      => 'General',
            'email'               => $context['email'],
            'provider_message_id' => $context['provider_message_id'],
        ]);

        $this->bindTenant($context['organisation_id']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $record       = $suppressions->find($context['email']);

        $this->assertNotNull($record, 'A permanent bounce creates a suppression');
        $this->assertSame('hard_bounce', (string) $record['reason']);

        $message = $this->connection->selectOne('SELECT status FROM email_messages WHERE id = ?', [$context['message_id']]);
        $this->assertSame('bounced', (string) $message['status']);
    }

    /**
     * A transient bounce must NOT suppress: a full mailbox today is not a reason
     * to stop mailing somebody for ever.
     */
    public function testATransientBounceDoesNotSuppress(): void
    {
        $context = $this->messageFixture();

        /** @var ProviderEventProcessor $processor */
        $processor = $this->container->make(ProviderEventProcessor::class);

        $processor->process([
            'provider_event_id'   => 'bounce-soft-1',
            'event_type'          => 'bounce',
            'bounce_type'         => 'Transient',
            'bounce_subtype'      => 'MailboxFull',
            'email'               => $context['email'],
            'provider_message_id' => $context['provider_message_id'],
        ]);

        $this->bindTenant($context['organisation_id']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);

        $this->assertFalse($suppressions->isSuppressed($context['email']), 'One soft bounce must not suppress');

        $message = $this->connection->selectOne('SELECT status FROM email_messages WHERE id = ?', [$context['message_id']]);
        $this->assertSame('soft_bounced', (string) $message['status'], 'But it is recorded as a soft bounce');
    }

    public function testRepeatedSoftBouncesEventuallyEscalate(): void
    {
        $context = $this->messageFixture();

        /** @var ProviderEventProcessor $processor */
        $processor = $this->container->make(ProviderEventProcessor::class);

        // The configured threshold is 5.
        for ($i = 1; $i <= 5; $i++) {
            $processor->process([
                'provider_event_id'   => 'bounce-soft-' . $i,
                'event_type'          => 'bounce',
                'bounce_type'         => 'Transient',
                'bounce_subtype'      => 'MailboxFull',
                'email'               => $context['email'],
                'provider_message_id' => $context['provider_message_id'],
            ]);
        }

        $this->bindTenant($context['organisation_id']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $record       = $suppressions->find($context['email']);

        $this->assertNotNull($record, 'Five consecutive soft bounces means the address is dead');
        $this->assertSame('invalid', (string) $record['reason']);
    }

    /** §85 — a complaint suppresses immediately. */
    public function testAComplaintSuppressesImmediatelyAndIsRecordedOnTheTimeline(): void
    {
        $context = $this->messageFixture();

        /** @var ProviderEventProcessor $processor */
        $processor = $this->container->make(ProviderEventProcessor::class);

        $processor->process([
            'provider_event_id'   => 'complaint-1',
            'event_type'          => 'complaint',
            'complaint_type'      => 'abuse',
            'email'               => $context['email'],
            'provider_message_id' => $context['provider_message_id'],
        ]);

        $this->bindTenant($context['organisation_id']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $record       = $suppressions->find($context['email']);

        $this->assertNotNull($record);
        $this->assertSame('complaint', (string) $record['reason']);

        $activity = $this->connection->selectOne(
            "SELECT * FROM activity_logs WHERE activity_type = 'email_complaint' AND contact_id = ?",
            [$context['contact_id']]
        );

        $this->assertNotNull($activity, 'The complaint appears on the customer timeline');
    }

    public function testAnEventForAnUnknownMessageIsIgnoredNotGuessed(): void
    {
        $this->messageFixture();

        /** @var ProviderEventProcessor $processor */
        $processor = $this->container->make(ProviderEventProcessor::class);

        // Inbound payloads are untrusted: without a message we own, there is no way
        // to know which organisation this belongs to, so nothing is written.
        $result = $processor->process([
            'provider_event_id'   => 'orphan-1',
            'event_type'          => 'bounce',
            'bounce_type'         => 'Permanent',
            'email'               => 'stranger@example.com',
            'provider_message_id' => 'a-message-we-never-sent',
        ]);

        $this->assertFalse($result['recorded']);
        $this->assertSame('unknown_message', $result['reason']);

        $events = (int) $this->connection->scalar('SELECT COUNT(*) FROM email_events');
        $this->assertSame(0, $events, 'An orphaned event never becomes a row');
    }

    public function testTheOrganisationComesFromOurRecordNotThePayload(): void
    {
        $victim  = $this->messageFixture();
        $attacker = $this->createOrganisation();

        /** @var ProviderEventProcessor $processor */
        $processor = $this->container->make(ProviderEventProcessor::class);

        // A crafted payload claiming to belong to a different organisation.
        $processor->process([
            'provider_event_id'   => 'forged-1',
            'event_type'          => 'delivery',
            'email'               => $victim['email'],
            'provider_message_id' => $victim['provider_message_id'],
            'organisation_id'     => $attacker['organisation_id'],
        ]);

        $event = $this->connection->selectOne("SELECT organisation_id FROM email_events WHERE provider_event_id = 'forged-1'");

        $this->assertSame(
            $victim['organisation_id'],
            (int) $event['organisation_id'],
            'Tenancy is resolved from the message we sent, never from the inbound payload'
        );
    }

    /**
     * Create an organisation, a contact and a sent message, and return what an
     * inbound provider event would reference.
     *
     * @return array{organisation_id:int,contact_id:int,message_id:int,email:string,provider_message_id:string}
     */
    private function messageFixture(): array
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $email     = 'recipient-' . bin2hex(random_bytes(3)) . '@example.com';
        $contactId = $this->createContact(['email' => $email, 'country' => 'US'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        $providerMessageId = 'ses-' . bin2hex(random_bytes(8));

        $messageId = $this->connection->table('email_messages')->insert([
            'uuid'                => uuid4(),
            'organisation_id'     => $org['organisation_id'],
            'contact_id'          => $contactId,
            'message_class'       => 'marketing',
            'provider'            => 'ses',
            'provider_message_id' => $providerMessageId,
            'email'               => $email,
            'email_normalized'    => normalize_email($email),
            'subject'             => 'Test campaign',
            'from_email'          => 'hello@example.test',
            'status'              => 'sent',
            'sent_at'             => $this->clock->nowString(),
            'created_at'          => $this->clock->nowString(),
        ]);

        return [
            'organisation_id'     => $org['organisation_id'],
            'contact_id'          => $contactId,
            'message_id'          => $messageId,
            'email'               => $email,
            'provider_message_id' => $providerMessageId,
        ];
    }
}
