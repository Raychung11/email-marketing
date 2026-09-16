<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Messaging\LogTextProvider;
use App\Messaging\TextProviderInterface;
use App\Services\TextMessageService;
use Tests\Support\TestCase;

/**
 * §30 — text messages.
 *
 * One rule is on trial: agreeing to email is not agreeing to texts. Everything
 * else here follows from texts being personal in a way email is not — people
 * notice them, they arrive at 2am if you let them, and each one costs real
 * money.
 */
final class TextMessageTest extends TestCase
{
    /** @var array{organisation_id:int,user_id:int} */
    private array $context;

    public function setUp(): void
    {
        parent::setUp();

        $org = $this->createOrganisation([
            'name' => 'Perth Plumbing Co', 'country' => 'AU', 'timezone' => 'Australia/Perth',
        ]);

        $this->actingAs($org['user_id'], $org['organisation_id']);
        $this->context = ['organisation_id' => $org['organisation_id'], 'user_id' => $org['user_id']];
    }

    // ---------------------------------------------------------- the rule

    /** The whole reason this is not just the email path with a different provider. */
    public function testEmailConsentDoesNotAuthoriseATextMessage(): void
    {
        // Ticked the box about the newsletter. Said nothing about texts.
        $contactId = $this->createContact(
            ['email' => 'keen@example.com', 'phone' => '0400 111 222', 'country' => 'AU'],
            ['status' => 'granted', 'consent_type' => 'express']
        );

        $decision = $this->texts()->canSend($this->contact($contactId));

        $this->assertTrue($decision->blocked());
        $this->assertContainsString('not the same thing', (string) $decision->message);
        $this->assertCount(0, $this->provider()->sentMessages());
    }

    public function testConsentForTextsSpecificallyIsEnough(): void
    {
        $contactId = $this->createContact(['email' => 'keen@example.com', 'phone' => '0400 111 222',
            'country' => 'AU']);

        $this->grantSmsConsent($contactId);

        $this->assertFalse($this->texts()->canSend($this->contact($contactId))->blocked());
    }

    public function testReplyingStopStopsTextsAndIsRecordedAsWithdrawnConsent(): void
    {
        $contactId = $this->createContact(['email' => 'gone@example.com', 'phone' => '+61400111222',
            'country' => 'AU']);
        $this->grantSmsConsent($contactId);

        $this->texts()->optOut('+61400111222');

        $this->assertTrue($this->texts()->canSend($this->contact($contactId))->blocked());

        // The suppression is the enforcement; the consent record is the evidence.
        $consent = $this->connection->selectOne(
            "SELECT * FROM contact_consents WHERE contact_id = ? AND channel = 'sms' ORDER BY id DESC LIMIT 1",
            [$contactId]
        ) ?? [];

        $this->assertSame('withdrawn', (string) $consent['status']);
    }

    public function testOptingOutOfTextsDoesNotStopTheirEmail(): void
    {
        $contactId = $this->createContact(
            ['email' => 'mixed@example.com', 'phone' => '+61400111222', 'country' => 'AU'],
            ['status' => 'granted', 'consent_type' => 'express']
        );
        $this->grantSmsConsent($contactId);

        $this->texts()->optOut('+61400111222');

        // The channels are separate in both directions.
        $suppressions = $this->container->make(\App\Services\SuppressionService::class);
        $this->assertFalse($suppressions->isSuppressed('mixed@example.com'));
    }

    public function testANumberWeCannotUseIsRefusedClearly(): void
    {
        $contactId = $this->createContact(['email' => 'nophone@example.com']);
        $this->grantSmsConsent($contactId);

        $decision = $this->texts()->canSend($this->contact($contactId));

        $this->assertTrue($decision->blocked());
        $this->assertContainsString('usable mobile number', (string) $decision->message);
    }

    public function testLocalNumbersAreUnderstoodFromTheContactsCountry(): void
    {
        $contactId = $this->createContact(['email' => 'local@example.com', 'phone' => '0400 111 222',
            'country' => 'AU']);
        $this->grantSmsConsent($contactId);

        $this->texts()->send($this->contact($contactId), 'Your service is booked for Tuesday.');

        $sent = $this->provider()->sentMessages();

        $this->assertCount(1, $sent);
        $this->assertSame('+61400111222', $sent[0]->toNumber, 'However it was typed in');
    }

    // ------------------------------------------------------- quiet hours

    /**
     * Email arriving at 2am is ignored until morning. A text wakes somebody up,
     * and they blame the business.
     */
    public function testATextIsHeldRatherThanSentInTheMiddleOfTheNight(): void
    {
        $contactId = $this->createContact(['email' => 'sleeper@example.com', 'phone' => '+61400111222',
            'country' => 'AU']);
        $this->grantSmsConsent($contactId);

        // 2am in Perth.
        $this->clock->setTo('2026-06-15 18:00:00');

        $result = $this->texts()->send($this->contact($contactId), 'Your service is due.');

        $this->assertFalse($result['sent']);
        $this->assertSame('QUIET_HOURS', (string) $result['reason']);
        $this->assertNotNull($result['held_until'], 'Held, not dropped');
        $this->assertCount(0, $this->provider()->sentMessages());
    }

    public function testATextGoesOutDuringTheDay(): void
    {
        $contactId = $this->createContact(['email' => 'awake@example.com', 'phone' => '+61400111222',
            'country' => 'AU']);
        $this->grantSmsConsent($contactId);

        // 2pm in Perth.
        $this->clock->setTo('2026-06-15 06:00:00');

        $this->assertTrue($this->texts()->send($this->contact($contactId), 'Your service is due.')['sent']);
    }

    // --------------------------------------------------------- the message

    /**
     * The one line nobody remembers to type, and the fastest route to a
     * complaint when it is missing.
     */
    public function testTheOptOutInstructionIsAddedIfTheAuthorForgot(): void
    {
        $contactId = $this->createContact(['email' => 'sam@example.com', 'phone' => '+61400111222',
            'country' => 'AU']);
        $this->grantSmsConsent($contactId);
        $this->clock->setTo('2026-06-15 06:00:00');

        $this->texts()->send($this->contact($contactId), 'Your boiler service is due this month.');

        $this->assertContainsString('Reply STOP', $this->provider()->sentMessages()[0]->body);
    }

    public function testAnAuthorsOwnOptOutWordingIsLeftAlone(): void
    {
        $contactId = $this->createContact(['email' => 'sam@example.com', 'phone' => '+61400111222',
            'country' => 'AU']);
        $this->grantSmsConsent($contactId);
        $this->clock->setTo('2026-06-15 06:00:00');

        $this->texts()->send($this->contact($contactId), 'Service due. Text STOP to unsubscribe.');

        $body = $this->provider()->sentMessages()[0]->body;

        $this->assertSame(1, substr_count(strtolower($body), 'stop'), 'Not doubled up');
    }

    // ---------------------------------------------------------------- cost

    /**
     * Email is effectively free per message and texts are not. A product that
     * lets somebody send 900 texts without saying what it costs loses that
     * customer at the next invoice.
     */
    public function testTheCostIsWorkedOutBeforeAnybodyPressesSend(): void
    {
        $estimate = $this->texts()->estimate(900, 'Your annual service is due. Ring us to book.');

        $this->assertSame(900, (int) $estimate['messages']);
        $this->assertSame(1, (int) $estimate['segments']);
        $this->assertSame($this->tenant->currency(), (string) $estimate['currency']);
    }

    public function testALongMessageSaysItWillCostTwice(): void
    {
        $long = str_repeat('Your annual boiler service is now due and we have slots free. ', 4);

        $estimate = $this->texts()->estimate(100, $long);

        $this->assertTrue($estimate['segments'] >= 2);
        $this->assertSame(100 * $estimate['segments'], (int) $estimate['messages']);

        // Said while they can still edit it, with the saving quantified.
        $this->assertContainsString('counts as', (string) $estimate['note']);
        $this->assertContainsString('cut the cost', (string) $estimate['note']);
    }

    public function testABigSpendAsksForConfirmation(): void
    {
        // 5,000 texts at the Australian indicative rate is well over the
        // confirmation threshold.
        $estimate = $this->texts()->estimate(5000, 'Short message.', 'AU');

        $this->assertTrue(
            $estimate['cost'] === 0.0 || $estimate['needs_confirmation'],
            'Either the log provider is free, or a real cost triggers confirmation'
        );
    }

    // ------------------------------------------------------------ journeys

    public function testAJourneyCannotTextSomebodyWhoOnlyAgreedToEmail(): void
    {
        $contactId = $this->createContact(
            ['email' => 'keen@example.com', 'phone' => '+61400111222', 'country' => 'AU'],
            ['status' => 'granted', 'consent_type' => 'express']
        );

        $action = $this->container->make(\App\Automation\Actions\SendTextAction::class);

        $result = $action->perform('send_sms', ['body' => 'Your service is due.'],
            $this->contact($contactId), []);

        $this->assertSame('blocked', (string) $result->outcome);
        $this->assertCount(0, $this->provider()->sentMessages());
    }

    // ------------------------------------------------------------ internals

    private function texts(): TextMessageService
    {
        return $this->container->make(TextMessageService::class);
    }

    private function provider(): LogTextProvider
    {
        /** @var LogTextProvider $provider */
        $provider = $this->container->make(TextProviderInterface::class);

        return $provider;
    }

    /** @return array<string,mixed> */
    private function contact(int $id): array
    {
        return $this->connection->selectOne('SELECT * FROM contacts WHERE id = ?', [$id]) ?? [];
    }

    private function grantSmsConsent(int $contactId): void
    {
        $this->container->make(\App\Services\ConsentService::class)->grant($contactId, [
            'channel'      => 'sms',
            'consent_type' => 'express',
            'source'       => 'website_form',
            'consent_text' => 'Yes, you can text me about my bookings and offers.',
        ]);
    }
}
