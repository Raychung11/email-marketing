<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\ConsentRepository;
use App\Services\ConsentService;
use Tests\Support\TestCase;

/**
 * Consent is a history, not a flag. These tests exist to make that structural
 * rather than aspirational: nothing may overwrite or delete a consent record.
 */
final class ConsentHistoryTest extends TestCase
{
    public function testEveryChangeAppendsRatherThanOverwrites(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact([], [
            'status'       => 'granted',
            'consent_type' => 'express',
            'source'       => 'website_form',
            'consent_text' => 'Yes, email me offers.',
        ]);

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);

        $consent->grant($contactId, ['consent_type' => 'express', 'source' => 'checkout']);
        $consent->withdraw($contactId, ['source' => 'unsubscribe']);
        $consent->grant($contactId, ['consent_type' => 'express', 'source' => 'website_form']);

        $history = $consent->history($contactId);

        $this->assertCount(4, $history, 'Four decisions means four rows');
        $this->assertSame('granted', (string) $history[0]['status'], 'The newest row is the current state');

        // The original evidence is still readable, unmodified, at the end.
        $oldest = $history[count($history) - 1];
        $this->assertSame('Yes, email me offers.', (string) $oldest['consent_text']);
        $this->assertSame('website_form', (string) $oldest['source']);
    }

    public function testCurrentStateIsTheMostRecentRow(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact([], ['status' => 'granted', 'consent_type' => 'express']);

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);

        $this->assertSame('granted', $consent->status($contactId));

        $consent->withdraw($contactId, ['source' => 'unsubscribe']);
        $this->assertSame('withdrawn', $consent->status($contactId));

        $consent->grant($contactId, ['source' => 'preference_centre']);
        $this->assertSame('granted', $consent->status($contactId));
    }

    public function testEvidenceIsCapturedWithTheConsent(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact([], [
            'status'                 => 'granted',
            'consent_type'           => 'express',
            'source'                 => 'website_form',
            'source_reference'       => 'form:contact-us',
            'consent_text'           => 'Tick to receive maintenance reminders',
            'ip_address'             => '198.51.100.24',
            'user_agent'             => 'Mozilla/5.0 (Macintosh)',
            'privacy_policy_version' => '2.1',
        ]);

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);
        $current = $consent->current($contactId);

        $this->assertSame('198.51.100.24', (string) $current['ip_address']);
        $this->assertSame('form:contact-us', (string) $current['source_reference']);
        $this->assertSame('2.1', (string) $current['privacy_policy_version']);
        $this->assertContainsString('maintenance reminders', (string) $current['consent_text']);
        $this->assertNotNull($current['consented_at']);
    }

    public function testAContactWithNoConsentRecordReadsAsUnknown(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        // Created with no consent payload: the service writes an explicit
        // "unknown" rather than leaving the history empty, so the compliance gate
        // has something definite to act on.
        $contactId = $this->createContact();

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);

        $this->assertSame('unknown', $consent->status($contactId));
        $this->assertCount(1, $consent->history($contactId), 'Ignorance is recorded, not implied');
    }

    public function testConsentIsTrackedPerChannel(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact([], ['status' => 'granted', 'consent_type' => 'express']);

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);

        // SMS consent is a separate decision from email consent — which is why the
        // channel column exists before SMS itself does.
        $consent->withdraw($contactId, ['channel' => 'sms', 'source' => 'manual']);

        $this->assertSame('granted', $consent->status($contactId, 'email'));
        $this->assertSame('withdrawn', $consent->status($contactId, 'sms'));
    }

    public function testBulkConsentLookupMatchesIndividualLookup(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $granted   = $this->createContact([], ['status' => 'granted', 'consent_type' => 'express']);
        $withdrawn = $this->createContact([], ['status' => 'granted', 'consent_type' => 'express']);
        $unknown   = $this->createContact();

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);
        $consent->withdraw($withdrawn, ['source' => 'unsubscribe']);

        /** @var ConsentRepository $repository */
        $repository = $this->container->make(ConsentRepository::class);
        $bulk       = $repository->currentForMany([$granted, $withdrawn, $unknown]);

        $this->assertSame('granted', (string) $bulk[$granted]['status']);
        $this->assertSame('withdrawn', (string) $bulk[$withdrawn]['status']);
        $this->assertSame('unknown', (string) $bulk[$unknown]['status']);
    }

    public function testConsentChangesAreAudited(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact([], ['status' => 'granted', 'consent_type' => 'express']);

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);
        $consent->withdraw($contactId, ['source' => 'unsubscribe']);

        $entries = $this->connection->select(
            "SELECT action FROM audit_logs WHERE entity_type = 'contact' AND entity_id = ? ORDER BY id",
            [$contactId]
        );

        $actions = array_column($entries, 'action');

        $this->assertTrue(in_array('consent_granted', $actions, true), 'The grant is audited');
        $this->assertTrue(in_array('consent_withdrawn', $actions, true), 'The withdrawal is audited');
    }

    public function testTheDenormalisedConsentMirrorFollowsTheAuthoritativeHistory(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact([], ['status' => 'granted', 'consent_type' => 'express']);

        /** @var \App\Repositories\ContactRepository $contacts */
        $contacts = $this->container->make(\App\Repositories\ContactRepository::class);

        $this->assertSame(1, (int) $contacts->findOrFail($contactId)['marketing_consent_cache']);

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);
        $consent->withdraw($contactId, ['source' => 'unsubscribe']);

        $this->assertSame(
            0,
            (int) $contacts->findOrFail($contactId)['marketing_consent_cache'],
            'The list-view mirror must not disagree with the consent history'
        );
    }
}
