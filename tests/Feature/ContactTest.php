<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\ValidationException;
use App\Repositories\ContactRepository;
use App\Repositories\ListRepository;
use App\Repositories\TagRepository;
use App\Services\ConsentService;
use App\Services\ContactService;
use App\Services\SuppressionService;
use Tests\Support\TestCase;

final class ContactTest extends TestCase
{
    public function testDuplicateEmailsAreRejectedWithinAnOrganisation(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $this->createContact(['email' => 'one@example.com']);

        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);

        $exception = $this->assertThrows(
            ValidationException::class,
            static fn () => $contacts->create(['email' => 'one@example.com'])
        );

        $this->assertContainsString('already exists', implode(' ', $exception->firstErrors()));
    }

    public function testEmailComparisonForDeduplicationIsCaseInsensitive(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $this->createContact(['email' => 'Mixed.Case@Example.COM']);

        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);

        $this->assertThrows(
            ValidationException::class,
            static fn () => $contacts->create(['email' => 'mixed.case@example.com']),
            'Case differences must not create a duplicate'
        );

        /** @var ContactRepository $repository */
        $repository = $this->container->make(ContactRepository::class);
        $found      = $repository->findByEmail('MIXED.CASE@EXAMPLE.COM');

        $this->assertNotNull($found);
        $this->assertSame('Mixed.Case@Example.COM', (string) $found['email'], 'The address is stored as entered');
    }

    public function testPlusAddressingIsTreatedAsADistinctMailbox(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        // These are genuinely different mailboxes. Collapsing them would let one
        // person's unsubscribe silently suppress another address.
        $a = $this->createContact(['email' => 'person@example.com']);
        $b = $this->createContact(['email' => 'person+news@example.com']);

        $this->assertTrue($a !== $b);
    }

    public function testInvalidEmailsAreRejected(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);

        foreach (['', 'not-an-email', 'missing@', '@missing.test', 'spaces in@example.com'] as $email) {
            $this->assertThrows(
                ValidationException::class,
                static fn () => $contacts->create(['email' => $email]),
                'Rejects: ' . ($email === '' ? '(empty)' : $email)
            );
        }
    }

    public function testCreatingAContactWithoutConsentRecordsUnknownExplicitly(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact();

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);

        $this->assertSame('unknown', $consent->status($contactId));
        $this->assertCount(1, $consent->history($contactId), 'Absence of consent is recorded, not left implicit');
    }

    public function testANewContactInheritsAnExistingSuppression(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForComplaint('complained@example.com', 'ses');

        // Someone re-adds the address by hand, perhaps not knowing the history.
        $contactId = $this->createContact(['email' => 'complained@example.com']);

        /** @var ContactRepository $repository */
        $repository = $this->container->make(ContactRepository::class);

        $this->assertSame(
            1,
            (int) $repository->findOrFail($contactId)['is_suppressed_cache'],
            'Creating a contact never clears a suppression'
        );
    }

    public function testMassAssignmentCannotSetProtectedColumns(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);

        // A crafted payload trying to grant itself consent and clear suppression.
        $contactId = $contacts->create([
            'email'                   => 'crafted@example.com',
            'marketing_consent_cache' => 1,
            'is_suppressed_cache'     => 0,
            'total_revenue'           => 999999,
            'lead_score'              => 9999,
            'organisation_id'         => 424242,
        ]);

        /** @var ContactRepository $repository */
        $repository = $this->container->make(ContactRepository::class);
        $contact    = $repository->findOrFail($contactId);

        $this->assertSame(0, (int) $contact['marketing_consent_cache'], 'Consent cannot be granted by payload');
        $this->assertSame(0.0, (float) $contact['total_revenue'], 'Revenue is not settable from a form');
        $this->assertSame(0, (int) $contact['lead_score'], 'Lead score is not settable from a form');
        $this->assertSame(
            $org['organisation_id'],
            (int) $contact['organisation_id'],
            'The tenant key comes from the session, never from the payload'
        );
    }

    public function testMergePreservesConsentHistoryRevenueAndRelations(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var TagRepository $tags */
        $tags = $this->container->make(TagRepository::class);
        /** @var ListRepository $lists */
        $lists = $this->container->make(ListRepository::class);

        $vip    = $tags->firstOrCreate('VIP');
        $hot    = $tags->firstOrCreate('Hot Lead');
        $listId = $lists->create('Newsletter');

        // 'keep' deliberately has no last name, so the merge has a gap to fill.
        $keep = $this->createContact(['email' => 'keep@example.com', 'first_name' => 'Keep', 'last_name' => null], [
            'status' => 'granted', 'consent_type' => 'express', 'source' => 'website_form',
        ]);

        $merge = $this->createContact(['email' => 'merge@example.com', 'last_name' => 'Merged'], [
            'status' => 'granted', 'consent_type' => 'express', 'source' => 'checkout',
        ]);

        $tags->attach($keep, $vip);
        $tags->attach($merge, $hot);
        $lists->addContact($listId, $merge);

        $this->connection->execute(
            'UPDATE contacts SET total_revenue = 400, customer_value = 400, purchase_count = 2 WHERE id = ?',
            [$keep]
        );
        $this->connection->execute(
            'UPDATE contacts SET total_revenue = 250, customer_value = 250, purchase_count = 1 WHERE id = ?',
            [$merge]
        );

        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);
        $contacts->merge($keep, $merge);

        /** @var ContactRepository $repository */
        $repository = $this->container->make(ContactRepository::class);
        $survivor   = $repository->findOrFail($keep);

        $this->assertSame(650.0, (float) $survivor['total_revenue'], 'Revenue is summed');
        $this->assertSame(3, (int) $survivor['purchase_count'], 'Purchases are summed');
        $this->assertSame('Merged', (string) $survivor['last_name'], 'Gaps are filled from the merged record');

        $tagNames = array_column($tags->forContact($keep), 'name');
        sort($tagNames);
        $this->assertSame(['Hot Lead', 'VIP'], $tagNames, 'Tags move across');

        $this->assertCount(1, $lists->forContact($keep), 'List membership moves across');

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);
        $history = $consent->history($keep);

        // Both original consent records are now on the survivor, plus a merge note:
        // the evidence follows the person, not the row.
        $sources = array_column($history, 'source');
        $this->assertTrue(in_array('website_form', $sources, true));
        $this->assertTrue(in_array('checkout', $sources, true));

        $this->assertNull($repository->find($merge), 'The merged record is gone from normal views');
    }

    public function testAnonymisingKeepsTheAddressSuppressed(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact([
            'email'      => 'erase.me@example.com',
            'first_name' => 'Erase',
            'last_name'  => 'Me',
            'phone'      => '+61400111222',
        ]);

        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);
        $contacts->anonymise($contactId);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);

        // The erasure itself has to be honoured: if the address were not suppressed,
        // a later import could resurrect and mail it.
        $this->assertTrue($suppressions->isSuppressed('erase.me@example.com'));

        $row = $this->connection->selectOne('SELECT * FROM contacts WHERE id = ?', [$contactId]);

        $this->assertNotContainsString('erase.me@example.com', (string) $row['email']);
        $this->assertNull($row['first_name']);
        $this->assertNull($row['phone']);
        $this->assertNotNull($row['deleted_at']);

        // The consent history and audit trail survive, because they are the record
        // of what was done and why.
        $consents = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM contact_consents WHERE contact_id = ?',
            [$contactId]
        );
        $this->assertTrue($consents > 0, 'Consent history is retained as compliance evidence');

        $audit = $this->connection->selectOne(
            "SELECT * FROM audit_logs WHERE action = 'contact_anonymised' ORDER BY id DESC"
        );
        $this->assertNotNull($audit);
    }

    public function testDeletingAContactKeepsTheSuppressionRecord(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'deleted@example.com']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForUnsubscribe('deleted@example.com');

        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);
        $contacts->delete($contactId);

        $this->assertTrue(
            $suppressions->isSuppressed('deleted@example.com'),
            'Suppression is keyed on the address, so deleting the contact cannot clear it'
        );
    }

    public function testTheProfileReportsLiveEligibilityRatherThanACachedFlag(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'profile@example.com.au', 'country' => 'AU'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);

        $this->assertTrue($contacts->profile($contactId)['eligibility']['allowed']);

        // Now the address bounces. The cached column on contacts is a convenience;
        // the profile must reflect the authoritative decision immediately.
        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForHardBounce('profile@example.com.au', 'ses');

        $eligibility = $contacts->profile($contactId)['eligibility'];

        $this->assertFalse($eligibility['allowed']);
        $this->assertSame('SUPPRESSED_HARD_BOUNCE', $eligibility['reason']);
    }

    public function testContactChangesAreAudited(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'audited@example.com', 'first_name' => 'Before']);

        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);
        $contacts->update($contactId, ['first_name' => 'After']);

        $actions = array_column(
            $this->connection->select(
                "SELECT action FROM audit_logs WHERE entity_type = 'contact' AND entity_id = ? ORDER BY id",
                [$contactId]
            ),
            'action'
        );

        $this->assertTrue(in_array('contact_created', $actions, true));
        $this->assertTrue(in_array('contact_updated', $actions, true));

        // The audit log records the previous value, not just the new one.
        $update = $this->connection->selectOne(
            "SELECT old_values, new_values FROM audit_logs
             WHERE action = 'contact_updated' AND entity_id = ? ORDER BY id DESC",
            [$contactId]
        );

        $this->assertContainsString('Before', (string) $update['old_values']);
        $this->assertContainsString('After', (string) $update['new_values']);
    }

    public function testAuditLogsNeverContainCredentials(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var \App\Services\AuditService $audit */
        $audit = $this->container->make(\App\Services\AuditService::class);

        $audit->log('test_action', 'thing', 1, null, [
            'password'      => 'super secret',
            'api_key'       => 'aigh_live_abc',
            'nested'        => ['token_hash' => 'deadbeef', 'safe' => 'visible'],
        ]);

        $row = $this->connection->selectOne("SELECT new_values FROM audit_logs WHERE action = 'test_action'");

        $this->assertNotContainsString('super secret', (string) $row['new_values']);
        $this->assertNotContainsString('aigh_live_abc', (string) $row['new_values']);
        $this->assertNotContainsString('deadbeef', (string) $row['new_values']);
        $this->assertContainsString('visible', (string) $row['new_values'], 'Non-sensitive context survives');
    }

    public function testLeadScoringUsesConfiguredWeights(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact();

        /** @var ContactService $contacts */
        $contacts = $this->container->make(ContactService::class);

        $contacts->applyScoringEvent($contactId, 'email_open');      // +1
        $contacts->applyScoringEvent($contactId, 'email_click');     // +3
        $contacts->applyScoringEvent($contactId, 'website_enquiry'); // +10

        /** @var ContactRepository $repository */
        $repository = $this->container->make(ContactRepository::class);

        $this->assertSame(14, (int) $repository->findOrFail($contactId)['lead_score']);
    }
}
