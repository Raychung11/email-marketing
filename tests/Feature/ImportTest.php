<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Compliance\ReasonCode;
use App\Core\ValidationException;
use App\Repositories\ContactRepository;
use App\Services\ConsentService;
use App\Services\ImportService;
use App\Services\SuppressionService;
use Tests\Support\TestCase;

/**
 * CSV import, with the consent declaration as a real gate rather than a checkbox.
 */
final class ImportTest extends TestCase
{
    public function testAPurchasedListIsRefusedOutright(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        $batchId = $this->uploadCsv("email,first_name\nbought1@example.com,Alex\nbought2@example.com,Jo\n");

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, ['email' => 'email', 'first_name' => 'first_name']);
        $imports->validateBatch($batchId);

        $exception = $this->assertThrows(
            ValidationException::class,
            static fn () => $imports->declareConsent($batchId, 'purchased_list')
        );

        $this->assertContainsString('Purchased marketing lists cannot be imported', implode(' ', $exception->firstErrors()));

        // The batch is blocked, the file is gone, and not one contact was written.
        $batch = $imports->batch($batchId);
        $this->assertSame('blocked', (string) $batch['status']);
        $this->assertSame(ReasonCode::IMPORT_PURCHASED_LIST_BLOCKED, (string) $batch['blocked_reason']);
        $this->assertNull($batch['stored_path'], 'The uploaded file is discarded');

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        $this->assertNull($contacts->findByEmail('bought1@example.com'), 'Nothing is imported from a refused list');
    }

    public function testAScrapedListIsRefusedOutright(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $batchId = $this->uploadCsv("email\nharvested@example.com\n");

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, ['email' => 'email']);
        $imports->validateBatch($batchId);

        $this->assertThrows(
            ValidationException::class,
            static fn () => $imports->declareConsent($batchId, 'scraped_list')
        );

        $this->assertSame('blocked', (string) $imports->batch($batchId)['status']);
    }

    public function testImportCannotRunWithoutAConsentDeclaration(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $batchId = $this->uploadCsv("email\nsomeone@example.com\n");

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, ['email' => 'email']);
        $imports->validateBatch($batchId);

        $exception = $this->assertThrows(
            ValidationException::class,
            static fn () => $imports->run($batchId)
        );

        $this->assertContainsString('declare how these contacts were obtained', implode(' ', $exception->firstErrors()));
    }

    public function testPhoneConsentRequiresAnEvidenceReference(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $batchId = $this->uploadCsv("email\ncalled@example.com\n");

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, ['email' => 'email']);
        $imports->validateBatch($batchId);

        $this->assertThrows(
            ValidationException::class,
            static fn () => $imports->declareConsent($batchId, 'phone_consent'),
            'Claiming phone consent without saying where the evidence is must be refused'
        );

        // With a reference it proceeds.
        $imports->declareConsent($batchId, 'phone_consent', 'Call log reference CS-4821');

        $this->assertSame(1, (int) $imports->batch($batchId)['consent_declared']);
    }

    public function testWebsiteOptInImportRecordsExpressConsent(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        $batchId = $this->uploadCsv(
            "email,first_name,last_name,country\n"
            . "opted@example.com.au,Jamie,Smith,AU\n"
        );

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, [
            'email' => 'email', 'first_name' => 'first_name',
            'last_name' => 'last_name', 'country' => 'country',
        ]);
        $imports->validateBatch($batchId);
        $imports->declareConsent($batchId, 'website_optin', 'Signup form since 2024');

        $counters = $imports->run($batchId, [], $org['user_id']);

        $this->assertSame(1, $counters['imported']);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        $contact  = $contacts->findByEmail('opted@example.com.au');

        $this->assertNotNull($contact);
        $this->assertSame('Jamie', (string) $contact['first_name']);

        /** @var ConsentService $consent */
        $consent = $this->container->make(ConsentService::class);
        $current = $consent->current((int) $contact['id']);

        $this->assertSame('granted', (string) $current['status']);
        $this->assertSame('express', (string) $current['consent_type']);
        $this->assertSame('import', (string) $current['source']);
        $this->assertContainsString('Signup form since 2024', (string) $current['source_reference']);
    }

    public function testCrmMigrationImportsWithUnknownConsentAndStaysBlocked(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        $batchId = $this->uploadCsv("email,country\nmigrated@example.com.au,AU\n");

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, ['email' => 'email', 'country' => 'country']);
        $imports->validateBatch($batchId);
        $imports->declareConsent($batchId, 'crm_migration', 'Migrated from previous CRM');
        $counters = $imports->run($batchId, [], $org['user_id']);

        $this->assertSame(1, $counters['imported']);
        $this->assertSame(1, $counters['no_consent'], 'The import reports how many cannot be marketed to');

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        $contact  = $contacts->findByEmail('migrated@example.com.au');

        /** @var \App\Compliance\ComplianceService $compliance */
        $compliance = $this->container->make(\App\Compliance\ComplianceService::class);
        $decision   = $compliance->canSendMarketingEmail($this->tenant->organisation(), $contact);

        $this->assertFalse($decision->allowed, 'A migrated contact is in the CRM but not marketable');
        $this->assertSame(ReasonCode::AU_CONSENT_UNKNOWN, $decision->reason);
    }

    /** §83 again, this time through the real import path. */
    public function testImportingASuppressedAddressDoesNotClearTheSuppression(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForUnsubscribe('unsubscribed@example.com');

        $batchId = $this->uploadCsv("email,first_name\nunsubscribed@example.com,Chris\n");

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, ['email' => 'email', 'first_name' => 'first_name']);

        $report = $imports->validateBatch($batchId);
        $this->assertSame(1, $report['suppressed'], 'The user is warned before importing');

        $imports->declareConsent($batchId, 'website_optin', 'Signup form');
        $counters = $imports->run($batchId, [], $org['user_id']);

        $this->assertSame(1, $counters['suppressed'], 'The outcome reports it too');
        $this->assertTrue(
            $suppressions->isSuppressed('unsubscribed@example.com'),
            'Importing must never resurrect a suppressed address'
        );

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        $contact  = $contacts->findByEmail('unsubscribed@example.com');

        $this->assertNotNull($contact, 'The CRM record is still created so the business has the data');
        $this->assertSame(1, (int) $contact['is_suppressed_cache']);
    }

    public function testInvalidAndDuplicateRowsAreReportedNotSilentlyDropped(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $batchId = $this->uploadCsv(
            "email,first_name\n"
            . "good@example.com,Good\n"
            . "not-an-email,Bad\n"
            . "good@example.com,Duplicate\n"
            . ",Blank\n"
        );

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, ['email' => 'email', 'first_name' => 'first_name']);

        $report = $imports->validateBatch($batchId);

        $this->assertSame(4, $report['total']);
        $this->assertSame(2, $report['invalid'], 'A malformed address and a blank both count as invalid');
        $this->assertSame(1, $report['duplicates_in_file']);

        $imports->declareConsent($batchId, 'website_optin', 'Form');
        $counters = $imports->run($batchId, [], $org['user_id']);

        $this->assertSame(1, $counters['imported']);
        $this->assertSame(2, $counters['invalid']);
        $this->assertSame(1, $counters['duplicate']);

        $problems = $imports->problemRows($batchId);
        $this->assertTrue(count($problems) >= 3, 'Every problem row is retained for the summary screen');
    }

    public function testSkipExistingStrategyLeavesCurrentDataAlone(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $this->createContact(['email' => 'existing@example.com', 'first_name' => 'Original']);

        $batchId = $this->uploadCsv("email,first_name\nexisting@example.com,Replacement\n");

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, ['email' => 'email', 'first_name' => 'first_name']);
        $imports->validateBatch($batchId);
        $imports->declareConsent($batchId, 'existing_customers');

        $counters = $imports->run($batchId, ['duplicate_strategy' => 'skip_existing'], $org['user_id']);

        $this->assertSame(1, $counters['skipped']);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        $this->assertSame('Original', (string) $contacts->findByEmail('existing@example.com')['first_name']);
    }

    public function testMergeFillBlanksOnlyFillsEmptyFields(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $this->createContact([
            'email'      => 'partial@example.com',
            'first_name' => 'Known',
            'phone'      => null,
        ]);

        $batchId = $this->uploadCsv("email,first_name,phone\npartial@example.com,Replacement,+61400000000\n");

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, ['email' => 'email', 'first_name' => 'first_name', 'phone' => 'phone']);
        $imports->validateBatch($batchId);
        $imports->declareConsent($batchId, 'existing_customers');
        $imports->run($batchId, ['duplicate_strategy' => 'merge_fill_blanks'], $org['user_id']);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        $contact  = $contacts->findByEmail('partial@example.com');

        $this->assertSame('Known', (string) $contact['first_name'], 'An existing value is not overwritten');
        $this->assertSame('+61400000000', (string) $contact['phone'], 'A blank field is filled');
    }

    public function testImportIsAudited(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $batchId = $this->uploadCsv("email\naudited@example.com\n");

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);
        $imports->saveMapping($batchId, ['email' => 'email']);
        $imports->validateBatch($batchId);
        $imports->declareConsent($batchId, 'website_optin', 'Newsletter form');
        $imports->run($batchId, [], $org['user_id']);

        $actions = array_column(
            $this->connection->select("SELECT action FROM audit_logs WHERE entity_type = 'import_batch' ORDER BY id"),
            'action'
        );

        $this->assertTrue(in_array('contact_import_started', $actions, true));
        $this->assertTrue(in_array('contact_import_consent_declared', $actions, true));
        $this->assertTrue(in_array('contact_import_completed', $actions, true));
    }

    /** Write a CSV to a temp file and push it through the upload step. */
    private function uploadCsv(string $contents): int
    {
        $path = tempnam(sys_get_temp_dir(), 'import') . '.csv';
        file_put_contents($path, $contents);

        /** @var ImportService $imports */
        $imports = $this->container->make(ImportService::class);

        $result = $imports->beginUpload([
            'name'     => 'contacts.csv',
            'tmp_name' => $path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($contents),
        ], 1);

        @unlink($path);

        return $result['batch_id'];
    }
}
