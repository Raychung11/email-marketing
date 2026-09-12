<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Compliance\ComplianceService;
use App\Compliance\ReasonCode;
use App\Repositories\ContactRepository;
use App\Repositories\SuppressionRepository;
use App\Services\ConsentService;
use App\Services\ContactService;
use App\Services\SuppressionService;
use Tests\Support\TestCase;

/**
 * Suppression outranks list membership, segment membership and re-import.
 *
 * Covers §83 (re-import), §84 (hard bounce) and §85 (complaint).
 */
final class SuppressionTest extends TestCase
{
    /**
     * §83 — the re-import test case.
     *
     * Contact subscribes → unsubscribes → is imported again. The contact record may
     * be updated, but the suppression must survive and marketing must stay blocked.
     */
    public function testSuppressionSurvivesAReimportOfTheSameAddress(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $email     = 'returning@example.com';
        $contactId = $this->createContact(['email' => $email, 'first_name' => 'Sam', 'country' => 'US'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);

        // They unsubscribe.
        $consent->withdraw($contactId, ['source' => 'unsubscribe']);
        $suppressions->suppressForUnsubscribe($email);

        $this->assertTrue($suppressions->isSuppressed($email));

        // The same address turns up in a later import, with updated details.
        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);
        $contacts->update($contactId, ['first_name' => 'Samantha', 'phone' => '+1 555 0100']);

        /** @var ContactRepository $repository */
        $repository = $this->container->make(ContactRepository::class);
        $updated    = $repository->findOrFail($contactId);

        $this->assertSame('Samantha', (string) $updated['first_name'], 'The contact record can still be updated');
        $this->assertTrue($suppressions->isSuppressed($email), 'The suppression must survive the update');

        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);
        $decision   = $compliance->canSendMarketingEmail($this->tenant->organisation(), $updated);

        $this->assertFalse($decision->allowed, 'Marketing must remain blocked after a re-import');
        $this->assertSame(ReasonCode::SUPPRESSED_UNSUBSCRIBE, $decision->reason);
    }

    /** §84 — a permanent bounce suppresses and blocks future marketing. */
    public function testHardBounceCreatesSuppressionAndBlocksFutureSends(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $email     = 'gone.away@example.com';
        $contactId = $this->createContact(['email' => $email, 'country' => 'US'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForHardBounce($email, 'ses', null, null, 'smtp; 550 5.1.1 user unknown');

        $record = $suppressions->find($email);

        $this->assertNotNull($record);
        $this->assertSame('hard_bounce', (string) $record['reason']);
        $this->assertSame('provider', (string) $record['source']);

        /** @var ContactRepository $repository */
        $repository = $this->container->make(ContactRepository::class);
        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        $decision = $compliance->canSendMarketingEmail(
            $this->tenant->organisation(),
            $repository->findOrFail($contactId)
        );

        $this->assertSame(ReasonCode::SUPPRESSED_HARD_BOUNCE, $decision->reason);
    }

    /** §85 — a complaint suppresses immediately, with no threshold. */
    public function testComplaintSuppressesImmediately(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        $email     = 'annoyed@example.com';
        $contactId = $this->createContact(['email' => $email, 'country' => 'US'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForComplaint($email, 'ses');

        /** @var ContactRepository $repository */
        $repository = $this->container->make(ContactRepository::class);
        /** @var ComplianceService $compliance */
        $compliance = $this->container->make(ComplianceService::class);

        $decision = $compliance->canSendMarketingEmail(
            $this->tenant->organisation(),
            $repository->findOrFail($contactId)
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame(ReasonCode::SUPPRESSED_COMPLAINT, $decision->reason);

        // And a complaint is not something the contact can undo by clicking a
        // preference link: only an unsubscribe suppression is self-clearable.
        $this->assertFalse(
            $suppressions->clearOnContactReaffirmation($email, 'preference_centre'),
            'A complaint suppression must not be cleared by a preference-centre visit'
        );
        $this->assertTrue($suppressions->isSuppressed($email));
    }

    public function testAStrongerSuppressionIsNotWeakenedByALaterOne(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $email = 'complained@example.com';

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForComplaint($email, 'ses');

        // A later manual entry must not overwrite the complaint on record.
        $suppressions->suppress($email, 'manual', ['source' => 'user']);

        $record = $suppressions->find($email);

        $this->assertSame('complaint', (string) $record['reason'], 'The original, stronger reason stands');
    }

    public function testSuppressionIsKeyedOnTheAddressNotTheContact(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $email = 'Person.Name@Example.COM';

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForUnsubscribe($email);

        // Case differences in the domain and local part must not defeat matching.
        $this->assertTrue($suppressions->isSuppressed('person.name@example.com'));
        $this->assertTrue($suppressions->isSuppressed('PERSON.NAME@EXAMPLE.COM'));

        // A genuinely different mailbox is not suppressed. We deliberately do NOT
        // collapse plus-addressing: person+news@ is a different mailbox, and
        // treating it as the same would let one opt-out silence another address.
        $this->assertFalse($suppressions->isSuppressed('person.name+news@example.com'));
    }

    public function testContactCanClearOnlyTheirOwnUnsubscribeSuppression(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $email = 'changed.mind@example.com';

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForUnsubscribe($email);

        $this->assertTrue($suppressions->clearOnContactReaffirmation($email, 'preference_centre'));
        $this->assertFalse($suppressions->isSuppressed($email), 'A verified re-subscribe clears an unsubscribe');

        // The clearance is audited.
        $audit = $this->connection->selectOne(
            "SELECT * FROM audit_logs WHERE action = 'suppression_removed' ORDER BY id DESC"
        );

        $this->assertNotNull($audit, 'Clearing a suppression must be audited');
        $this->assertContainsString('contact_reaffirmed_consent', (string) $audit['new_values']);
    }

    public function testRemovingASuppressionRequiresAndRecordsAReason(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var SuppressionRepository $repository */
        $repository = $this->container->make(SuppressionRepository::class);
        $id         = $repository->suppress('mistake@example.com', 'manual', ['source' => 'user']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);

        $this->assertTrue($suppressions->remove($id, $org['user_id'], 'Added in error; contact confirmed by phone'));
        $this->assertFalse($suppressions->isSuppressed('mistake@example.com'));

        $audit = $this->connection->selectOne(
            "SELECT * FROM audit_logs WHERE action = 'suppression_removed' ORDER BY id DESC"
        );

        $this->assertNotNull($audit);
        $this->assertContainsString('Added in error', (string) $audit['new_values']);
    }

    public function testSuppressedContactsAreExcludedFromASegmentAudience(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        for ($i = 0; $i < 3; $i++) {
            $this->createContact(['email' => "person{$i}@example.com", 'country' => 'US'], [
                'status' => 'granted', 'consent_type' => 'express',
            ]);
        }

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForUnsubscribe('person1@example.com');

        /** @var \App\Services\SegmentService $segments */
        $segments = $this->container->make(\App\Services\SegmentService::class);

        $preview = $segments->preview([
            'match' => 'all',
            'rules' => [['field' => 'country', 'operator' => 'equals', 'value' => 'US']],
        ]);

        $this->assertSame(3, $preview['total'], 'All three still match the rules');
        $this->assertSame(2, $preview['eligible'], 'Only two can actually be emailed');
        $this->assertSame(1, $preview['suppressed'], 'The gap is reported, not hidden');
    }
}
