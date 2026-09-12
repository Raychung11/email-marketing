<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Compliance\ComplianceService;
use App\Core\Signer;
use App\Mail\EmailRenderer;
use App\Repositories\ContactRepository;
use App\Services\ConsentService;
use App\Services\SuppressionService;
use App\Services\UnsubscribeService;
use Tests\Support\TestCase;

/**
 * Unsubscribe: signed tokens, no exposed ids, and a GET that does not change
 * state.
 */
final class UnsubscribeTest extends TestCase
{
    public function testUnsubscribeUrlNeverContainsAContactId(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'recipient@example.com']);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        $contact  = $contacts->findOrFail($contactId);

        /** @var UnsubscribeService $unsubscribe */
        $unsubscribe = $this->container->make(UnsubscribeService::class);
        $url         = $unsubscribe->urlFor($org['organisation_id'], (string) $contact['uuid']);

        $this->assertContainsString('/unsubscribe/', $url);
        $this->assertNotContainsString('recipient@example.com', $url, 'The address is not in the URL');
        $this->assertNotContainsString('id=' . $contactId, $url, 'No raw primary key in the URL');
    }

    public function testATamperedTokenDoesNotResolve(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact();

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var UnsubscribeService $unsubscribe */
        $unsubscribe = $this->container->make(UnsubscribeService::class);

        $token = $unsubscribe->tokenFor(
            $org['organisation_id'],
            (string) $contacts->findOrFail($contactId)['uuid']
        );

        $this->assertNotNull($unsubscribe->resolve($token), 'A genuine token resolves');

        // Flip the payload: the signature no longer matches.
        [$payload, $signature] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode('{"o":1,"c":"attacker","v":1}'), '+/', '-_'), '=') . '.' . $signature;

        $this->assertNull($unsubscribe->resolve($forged), 'A forged payload must not resolve');
        $this->assertNull($unsubscribe->resolve($payload . '.' . strrev($signature)), 'A broken signature must not resolve');
        $this->assertNull($unsubscribe->resolve('nonsense'), 'Garbage must not resolve');
    }

    public function testUnsubscribingWithdrawsConsentAndCreatesSuppression(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'leaving@example.com', 'country' => 'US'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var UnsubscribeService $unsubscribe */
        $unsubscribe = $this->container->make(UnsubscribeService::class);

        $token    = $unsubscribe->tokenFor($org['organisation_id'], (string) $contacts->findOrFail($contactId)['uuid']);
        $resolved = $unsubscribe->resolve($token);

        $unsubscribe->unsubscribeAll($resolved, '198.51.100.7', 'Mozilla/5.0');

        $this->bindTenant($org['organisation_id']);

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);
        $this->assertSame('withdrawn', $consent->status($contactId));

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $this->assertTrue($suppressions->isSuppressed('leaving@example.com'));

        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);
        $this->assertFalse(
            $compliance->canSendMarketingEmail($this->tenant->organisation(), $contacts->findOrFail($contactId))->allowed
        );
    }

    public function testUnsubscribeEvidenceIsRecorded(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'evidence@example.com'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var UnsubscribeService $unsubscribe */
        $unsubscribe = $this->container->make(UnsubscribeService::class);

        $resolved = $unsubscribe->resolve(
            $unsubscribe->tokenFor($org['organisation_id'], (string) $contacts->findOrFail($contactId)['uuid'])
        );

        $unsubscribe->unsubscribeAll($resolved, '203.0.113.55', 'Mozilla/5.0 (iPhone)');

        $event = $this->connection->selectOne('SELECT * FROM unsubscribe_events ORDER BY id DESC');

        $this->assertNotNull($event);
        $this->assertSame('evidence@example.com', (string) $event['email']);
        $this->assertSame('203.0.113.55', (string) $event['ip_address']);
        $this->assertSame('all_marketing', (string) $event['scope']);
        $this->assertContainsString('iPhone', (string) $event['user_agent']);
    }

    public function testGetOnTheUnsubscribePageDoesNotUnsubscribe(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'prefetch@example.com'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var UnsubscribeService $unsubscribe */
        $unsubscribe = $this->container->make(UnsubscribeService::class);

        $token = $unsubscribe->tokenFor($org['organisation_id'], (string) $contacts->findOrFail($contactId)['uuid']);

        // Mail clients and security scanners pre-fetch links; a state-changing GET
        // would unsubscribe people who never clicked.
        $response = $this->get('/unsubscribe/' . $token);

        $this->assertStatus(200, $response);
        $this->assertContainsString('Unsubscribe me', $response->body(), 'The GET shows a confirmation, not a result');

        $this->bindTenant($org['organisation_id']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $this->assertFalse($suppressions->isSuppressed('prefetch@example.com'), 'A prefetch must not unsubscribe anyone');

        // The POST does the work.
        $confirm = $this->post('/unsubscribe/' . $token);

        $this->assertStatus(200, $confirm);
        $this->assertContainsString('has been unsubscribed', $confirm->body());

        $this->bindTenant($org['organisation_id']);
        $this->assertTrue($suppressions->isSuppressed('prefetch@example.com'));
    }

    public function testAnInvalidTokenRendersAHelpfulPageNotAnError(): void
    {
        $this->createOrganisation();

        $response = $this->get('/unsubscribe/completely-invalid-token');

        $this->assertStatus(404, $response);
        $this->assertContainsString('This link is not valid', $response->body());
        // The page must not hint at whether the address exists.
        $this->assertNotContainsString('organisation_id', $response->body());
    }

    public function testUnsubscribePagesAreNotIndexableOrCacheable(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact();

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var UnsubscribeService $unsubscribe */
        $unsubscribe = $this->container->make(UnsubscribeService::class);

        $token    = $unsubscribe->tokenFor($org['organisation_id'], (string) $contacts->findOrFail($contactId)['uuid']);
        $response = $this->get('/unsubscribe/' . $token);

        $headers = $response->headers();

        $this->assertContainsString('no-store', $headers['Cache-Control'] ?? '');
        $this->assertContainsString('noindex', $headers['X-Robots-Tag'] ?? '');
    }

    public function testPreferenceCentreCanKeepSomeTopics(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'picky@example.com', 'country' => 'US'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var UnsubscribeService $unsubscribe */
        $unsubscribe = $this->container->make(UnsubscribeService::class);

        $resolved = $unsubscribe->resolve(
            $unsubscribe->tokenFor($org['organisation_id'], (string) $contacts->findOrFail($contactId)['uuid'])
        );

        $unsubscribe->updatePreferences($resolved, ['newsletter'], '203.0.113.9', 'Mozilla/5.0');

        $this->bindTenant($org['organisation_id']);

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);

        $this->assertSame('granted', $consent->status($contactId), 'Channel consent stands');

        $newsletter = $this->connection->selectOne(
            "SELECT status FROM contact_consents WHERE contact_id = ? AND topic = 'newsletter' ORDER BY id DESC",
            [$contactId]
        );
        $promotions = $this->connection->selectOne(
            "SELECT status FROM contact_consents WHERE contact_id = ? AND topic = 'promotions' ORDER BY id DESC",
            [$contactId]
        );

        $this->assertSame('granted', (string) $newsletter['status']);
        $this->assertSame('withdrawn', (string) $promotions['status'], 'Unpicked topics are withdrawn, not ignored');
    }

    public function testChoosingNoTopicsIsAFullUnsubscribe(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'none@example.com'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var UnsubscribeService $unsubscribe */
        $unsubscribe = $this->container->make(UnsubscribeService::class);

        $resolved = $unsubscribe->resolve(
            $unsubscribe->tokenFor($org['organisation_id'], (string) $contacts->findOrFail($contactId)['uuid'])
        );

        $unsubscribe->updatePreferences($resolved, [], null, null);

        $this->bindTenant($org['organisation_id']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);

        $this->assertTrue(
            $suppressions->isSuppressed('none@example.com'),
            'Keeping nothing is the same as unsubscribing, suppression included'
        );
    }

    /**
     * The renderer must guarantee the footer even when a template author forgot
     * it. A marketing email without an unsubscribe link is not sendable.
     */
    public function testRendererAlwaysAddsUnsubscribeAndBusinessIdentity(): void
    {
        $org = $this->createOrganisation(['name' => 'Perth Plumbing Co']);
        $this->bindTenant($org['organisation_id']);

        $this->connection->execute(
            'UPDATE organisations SET address_line1 = ?, address_city = ?, address_postcode = ?, address_country = ?,
                    contact_phone = ? WHERE id = ?',
            ['12 Example St', 'Perth', '6000', 'AU', '+61 8 5550 1000', $org['organisation_id']]
        );

        $organisation = $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'reader@example.com', 'first_name' => 'Riley']);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var EmailRenderer $renderer */
        $renderer = $this->container->make(EmailRenderer::class);

        // A template with no footer and no unsubscribe link at all.
        $rendered = $renderer->render(
            '<p>Hello {{first_name}}, time for your annual check.</p>',
            'Hello {{first_name}}, time for your annual check.',
            $organisation,
            $contacts->findOrFail($contactId)
        );

        $this->assertContainsString('Riley', $rendered['html'], 'Merge variables are substituted');
        $this->assertContainsString('/unsubscribe/', $rendered['html'], 'An unsubscribe link is always present');
        $this->assertContainsString('Perth Plumbing Co', $rendered['html'], 'Sender identity is always present');
        $this->assertContainsString('12 Example St', $rendered['html'], 'The postal address is always present');
        $this->assertContainsString('/unsubscribe/', $rendered['text'], 'The plain-text part too');
    }

    public function testMergeVariablesAreEscapedAgainstInjection(): void
    {
        $org = $this->createOrganisation();
        $organisation = $this->bindTenant($org['organisation_id']);

        // A contact's own name is attacker-supplied data as far as the rendered
        // email is concerned.
        $contactId = $this->createContact([
            'email'      => 'xss@example.com',
            'first_name' => '<script>alert(1)</script>',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var EmailRenderer $renderer */
        $renderer = $this->container->make(EmailRenderer::class);

        $rendered = $renderer->render(
            '<p>Hi {{first_name}}</p>',
            '',
            $organisation,
            $contacts->findOrFail($contactId)
        );

        $this->assertNotContainsString('<script>alert(1)</script>', $rendered['html']);
        $this->assertContainsString('&lt;script&gt;', $rendered['html']);
    }

    public function testUnknownMergeVariablesRenderAsNothing(): void
    {
        $org = $this->createOrganisation();
        $organisation = $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact();

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var EmailRenderer $renderer */
        $renderer = $this->container->make(EmailRenderer::class);

        $rendered = $renderer->render(
            '<p>Hello {{not_a_real_field}}</p>',
            '',
            $organisation,
            $contacts->findOrFail($contactId)
        );

        $this->assertNotContainsString('{{not_a_real_field}}', $rendered['html'], 'No raw token reaches the inbox');
    }
}
