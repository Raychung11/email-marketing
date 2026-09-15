<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Compliance\ComplianceService;
use App\Compliance\ReasonCode;
use App\Repositories\ContactRepository;
use App\Services\ConsentService;
use App\Services\SuppressionService;
use Tests\Support\TestCase;

/**
 * The compliance gate. These are the tests that decide whether this platform is
 * safe to point at a real customer list.
 */
final class ComplianceTest extends TestCase
{
    /**
     * §82 — the Australian test case.
     *
     * country = AU, consent = unknown → BLOCK, reason AU_CONSENT_UNKNOWN.
     */
    public function testAustralianContactWithUnknownConsentIsBlocked(): void
    {
        $org = $this->createOrganisation(['country' => 'AU', 'timezone' => 'Australia/Perth', 'currency' => 'AUD']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact([
            'email'   => 'test@example.com',
            'country' => 'AU',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        $decision = $compliance->canSendMarketingEmail(
            $this->tenant->organisation(),
            $contacts->findOrFail($contactId)
        );

        $this->assertFalse($decision->allowed, 'An Australian contact with unknown consent must not be sent marketing');
        $this->assertSame(ReasonCode::AU_CONSENT_UNKNOWN, $decision->reason);
        $this->assertContainsString(
            'Marketing consent has not been established',
            $decision->message,
            'The message must say plainly what is wrong'
        );
    }

    public function testAustralianContactWithExpressConsentIsAllowed(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'opted.in@example.com.au', 'country' => 'AU'], [
            'status'       => 'granted',
            'consent_type' => 'express',
            'source'       => 'website_form',
            'consent_text' => 'Yes, send me plumbing maintenance reminders and offers.',
        ]);

        $decision = $this->decisionFor($contactId);

        $this->assertTrue($decision->allowed, 'Express consent is an acceptable basis in Australia');
        $this->assertSame(ReasonCode::ALLOWED, $decision->reason);
    }

    public function testAustralianInferredConsentIsNotAnAcceptableBasis(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'inferred@example.com.au', 'country' => 'AU'], [
            'status'       => 'granted',
            'consent_type' => 'inferred',
            'source'       => 'crm',
        ]);

        $decision = $this->decisionFor($contactId);

        $this->assertFalse($decision->allowed, 'Inferred consent is not acceptable under the AU rule set');
        $this->assertSame(ReasonCode::CONSENT_TYPE_NOT_ACCEPTABLE, $decision->reason);
    }

    public function testExistingCustomerRelationshipExpires(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        // A purchase three years ago. The AU rule set allows a "legitimate existing
        // relationship" basis, but only for a configured window (730 days).
        $contactId = $this->createContact([
            'email'            => 'long.ago@example.com.au',
            'country'          => 'AU',
            'customer_status'  => 'customer',
        ], [
            'status'       => 'granted',
            'consent_type' => 'legitimate_existing_relationship',
            'source'       => 'checkout',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        $contacts->update($contactId, ['last_purchase_at' => '2020-01-01 00:00:00']);

        // The consent row itself was written "now" by the factory, so age it too.
        $this->connection->execute(
            'UPDATE contact_consents SET created_at = ?, consented_at = ? WHERE contact_id = ?',
            ['2020-01-01 00:00:00', '2020-01-01 00:00:00', $contactId]
        );

        $decision = $this->decisionFor($contactId);

        $this->assertFalse($decision->allowed, 'A relationship older than the window is no longer a basis');
        $this->assertSame(ReasonCode::RELATIONSHIP_TOO_OLD, $decision->reason);
    }

    public function testRecentCustomerRelationshipIsAnAcceptableBasis(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact([
            'email'           => 'recent.customer@example.com.au',
            'country'         => 'AU',
            'customer_status' => 'customer',
        ], [
            'status'       => 'granted',
            'consent_type' => 'legitimate_existing_relationship',
            'source'       => 'checkout',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        $contacts->update($contactId, ['last_purchase_at' => '2026-03-01 00:00:00']);

        $this->assertTrue($this->decisionFor($contactId)->allowed);
    }

    /**
     * The US model is opt-out: an unknown consent state does not block, but an
     * opt-out (which creates a suppression) absolutely does.
     */
    public function testUnitedStatesContactWithUnknownConsentIsAllowed(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'unknown@example.com', 'country' => 'US']);

        $this->assertTrue(
            $this->decisionFor($contactId)->allowed,
            'The US rule set does not require a recorded consent basis'
        );
    }

    public function testWithdrawnConsentBlocksEvenInAnOptOutJurisdiction(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'gone@example.com', 'country' => 'US'], [
            'status'       => 'granted',
            'consent_type' => 'express',
        ]);

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);
        $consent->withdraw($contactId, ['source' => 'unsubscribe']);

        $decision = $this->decisionFor($contactId);

        $this->assertFalse($decision->allowed);
        $this->assertSame(ReasonCode::CONSENT_WITHDRAWN, $decision->reason);
    }

    public function testAContactsOwnCountryOverridesTheOrganisationCountry(): void
    {
        // A US business with an Australian customer is bound by the Australian
        // rules for that customer.
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $australian = $this->createContact(['email' => 'aussie@example.com.au', 'country' => 'AU']);
        $american   = $this->createContact(['email' => 'yank@example.com', 'country' => 'US']);

        $this->assertSame(
            ReasonCode::AU_CONSENT_UNKNOWN,
            $this->decisionFor($australian)->reason,
            'The contact country decides the rule set, not the organisation country'
        );

        $this->assertTrue($this->decisionFor($american)->allowed);
    }

    public function testInvalidAddressesAreBlockedBeforeAnythingElse(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        $decision = $compliance->canSendMarketingEmail(
            $this->tenant->organisation(),
            ['id' => 999, 'email' => 'not-an-address', 'country' => 'US']
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(ReasonCode::INVALID_EMAIL, $decision->reason);
    }

    public function testPausedOrganisationCannotSend(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'someone@example.com', 'country' => 'US']);

        $this->connection->execute(
            'UPDATE organisations SET sending_paused = 1, sending_paused_reason = ? WHERE id = ?',
            ['Complaint rate above threshold', $org['organisation_id']]
        );

        $this->bindTenant($org['organisation_id']);

        $decision = $this->decisionFor($contactId);

        $this->assertFalse($decision->allowed);
        $this->assertSame(ReasonCode::ORG_SENDING_PAUSED, $decision->reason);
        $this->assertContainsString('Complaint rate', $decision->message, 'The pause reason reaches the user');
    }

    /**
     * Transactional mail must still reach someone who never opted into marketing —
     * but must not reach an address that hard bounced or complained.
     */
    public function testTransactionalMailIgnoresMarketingConsentButHonoursHardFailures(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'customer@example.com.au', 'country' => 'AU']);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        $contact = $contacts->findOrFail($contactId);

        $this->assertFalse(
            $compliance->canSendMarketingEmail($this->tenant->organisation(), $contact)->allowed,
            'Marketing is blocked: unknown AU consent'
        );

        $this->assertTrue(
            $compliance->canSendTransactionalEmail($this->tenant->organisation(), $contact)->allowed,
            'A password reset must still be deliverable'
        );

        // Now the address hard bounces.
        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForHardBounce('customer@example.com.au', 'ses');

        $this->assertFalse(
            $compliance->canSendTransactionalEmail($this->tenant->organisation(), $contact)->allowed,
            'A hard-bounced address is not deliverable for any message class'
        );
    }

    public function testAnUnsubscribeDoesNotBlockTransactionalMail(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'unsubbed@example.com', 'country' => 'US']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForUnsubscribe('unsubbed@example.com');

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        $contact = $contacts->findOrFail($contactId);

        $this->assertFalse($compliance->canSendMarketingEmail($this->tenant->organisation(), $contact)->allowed);
        $this->assertTrue(
            $compliance->canSendTransactionalEmail($this->tenant->organisation(), $contact)->allowed,
            'Opting out of marketing does not opt out of receipts and password resets'
        );
    }

    /**
     * Relabelling a promotional campaign as transactional must not be a way past
     * the marketing gate.
     */
    public function testMarketingContentCannotBeSentAsTransactional(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        $dishonest = $compliance->assertMessageClassHonest([
            'campaign_type' => 'promotion',
            'message_class' => 'transactional',
        ]);

        $this->assertFalse($dishonest->allowed);
        $this->assertSame(ReasonCode::MARKETING_AS_TRANSACTIONAL, $dishonest->reason);

        $honest = $compliance->assertMessageClassHonest([
            'campaign_type' => 'promotion',
            'message_class' => 'marketing',
        ]);

        $this->assertTrue($honest->allowed);
    }

    public function testBatchEvaluationAgreesWithIndividualEvaluation(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        $blocked  = $this->createContact(['email' => 'no-consent@example.com.au', 'country' => 'AU']);
        $allowed  = $this->createContact(['email' => 'consented@example.com.au', 'country' => 'AU'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);
        $bounced  = $this->createContact(['email' => 'bounced@example.com.au', 'country' => 'AU'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForHardBounce('bounced@example.com.au', 'ses');

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        $rows = [
            $contacts->findOrFail($blocked),
            $contacts->findOrFail($allowed),
            $contacts->findOrFail($bounced),
        ];

        $batch = $compliance->evaluateBatch($this->tenant->organisation(), $rows);

        // The batch path pre-loads for speed; it must reach exactly the same
        // verdicts as the single path, or a preview would lie about a send.
        foreach ($rows as $contact) {
            $single = $compliance->canSendMarketingEmail($this->tenant->organisation(), $contact);
            $bulk   = $batch[(int) $contact['id']];

            $this->assertSame(
                $single->reason,
                $bulk->reason,
                'Batch and single evaluation disagree for ' . $contact['email']
            );
        }

        $this->assertSame(ReasonCode::AU_CONSENT_UNKNOWN, $batch[$blocked]->reason);
        $this->assertSame(ReasonCode::ALLOWED, $batch[$allowed]->reason);
        $this->assertSame(ReasonCode::SUPPRESSED_HARD_BOUNCE, $batch[$bounced]->reason);
    }

    private function decisionFor(int $contactId): \App\Compliance\ComplianceDecision
    {
        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        return $compliance->canSendMarketingEmail(
            $this->tenant->organisation(),
            $contacts->findOrFail($contactId)
        );
    }

    public function testMalaysianContactWithUnknownConsentIsBlocked(): void
    {
        $org = $this->createOrganisation([
            'country'  => 'MY',
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact([
            'email'   => 'test@example.com.my',
            'country' => 'MY',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        $decision = $compliance->canSendMarketingEmail(
            $this->tenant->organisation(),
            $contacts->findOrFail($contactId)
        );

        // The PDPA makes consent the basis for processing personal data, so a
        // contact with no recorded permission is not sendable — the same posture
        // as Australia rather than the American opt-out model.
        $this->assertFalse($decision->allowed, 'A Malaysian contact with unknown consent must not be sent marketing');
        $this->assertSame(ReasonCode::MY_CONSENT_UNKNOWN, $decision->reason);
        $this->assertContainsString('Malaysian', $decision->message . ReasonCode::describe($decision->reason));
    }

    public function testMalaysianContactWithExpressConsentIsAllowed(): void
    {
        $org = $this->createOrganisation(['country' => 'MY']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'optedin@example.com.my', 'country' => 'MY'], [
            'status'       => 'granted',
            'consent_type' => 'express',
        ]);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        $decision = $compliance->canSendMarketingEmail(
            $this->tenant->organisation(),
            $contacts->findOrFail($contactId)
        );

        $this->assertTrue($decision->allowed, 'Express consent is a sufficient basis in Malaysia');
    }

    public function testMalaysiaIsSelectableAtSetupAndHasItsOwnRules(): void
    {
        /** @var \App\Core\Config $config */
        $config = $this->container->make(\App\Core\Config::class);

        // A country offered in the dropdown with no rule set of its own silently
        // falls back to the defaults, which is a quiet way to apply the wrong
        // law. If it is selectable, it needs rules.
        $countries = (array) $config->get('app.supported_countries', []);
        $rules     = (array) $config->get('compliance.countries', []);

        $this->assertTrue(isset($countries['MY']), 'Malaysia can be chosen during setup');
        $this->assertTrue(isset($rules['MY']), 'And has a rule set rather than falling through to the default');
        $this->assertTrue((bool) $rules['MY']['require_consent_basis'], 'Permission is required before sending');
        $this->assertFalse((bool) $rules['MY']['require_postal_address'], 'No US-style postal address requirement');
        $this->assertTrue((bool) $rules['MY']['require_unsubscribe'], 'Opt-out must always be offered');
    }
}
