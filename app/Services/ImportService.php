<?php

declare(strict_types=1);

namespace App\Services;

use App\Compliance\ReasonCode;
use App\Core\Clock;
use App\Core\Config;
use App\Core\ValidationException;
use App\Database\Connection;
use App\Repositories\ContactRepository;
use App\Repositories\ImportRepository;
use App\Repositories\ListRepository;
use App\Repositories\SuppressionRepository;
use App\Repositories\TagRepository;
use App\Support\CsvReader;
use App\Support\TenantContext;

/**
 * CSV import.
 *
 * The consent declaration is not a checkbox at the end — it is a gate. Purchased
 * and scraped lists are refused outright (the import never runs), and everything
 * that does import carries a recorded provenance so the compliance gate can make
 * a real decision later.
 *
 * Steps: upload → preview → map → validate → declare consent → duplicates →
 *        import → summary.
 */
final class ImportService
{
    public const STEP_UPLOAD    = 'uploaded';
    public const STEP_MAPPED    = 'mapped';
    public const STEP_VALIDATED = 'validated';
    public const STEP_CONSENT   = 'awaiting_consent';
    public const STEP_IMPORTING = 'importing';
    public const STEP_COMPLETED = 'completed';
    public const STEP_BLOCKED   = 'blocked';

    public function __construct(
        private readonly ImportRepository $imports,
        private readonly ContactRepository $contacts,
        private readonly SuppressionRepository $suppressions,
        private readonly TagRepository $tags,
        private readonly ListRepository $lists,
        private readonly ConsentService $consent,
        private readonly ContactService $contactService,
        private readonly AuditService $audit,
        private readonly Connection $connection,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Step 1-2: store the upload, detect headers, guess a mapping.
     *
     * @param array<string,mixed> $uploadedFile a $_FILES entry
     * @return array{batch_id:int,headers:array<int,string>,preview:array<int,array<string,string>>,mapping:array<string,string>,total_rows:int}
     */
    public function beginUpload(array $uploadedFile, int $userId): array
    {
        $error = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => [$this->uploadErrorMessage($error)]]);
        }

        $originalName = (string) ($uploadedFile['name'] ?? 'import.csv');
        $tmpPath      = (string) ($uploadedFile['tmp_name'] ?? '');

        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new ValidationException(['file' => ['The uploaded file could not be read.']]);
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'txt'], true)) {
            throw new ValidationException([
                'file' => ['Please upload a CSV file. Excel (.xlsx) support is on the roadmap — export as CSV for now.'],
            ]);
        }

        $storedPath = $this->storeUpload($tmpPath, $originalName);
        $reader     = new CsvReader($storedPath);
        $headers    = $reader->headers();

        if ($headers === []) {
            @unlink($storedPath);

            throw new ValidationException(['file' => ['That file does not appear to contain a header row.']]);
        }

        $totalRows = $reader->countRows();
        $rowLimit  = $this->rowLimit();

        if ($totalRows > $rowLimit) {
            @unlink($storedPath);

            throw new ValidationException([
                'file' => [
                    'This file contains ' . number_format($totalRows) . ' rows, which exceeds the '
                    . number_format($rowLimit) . '-row limit for your account. '
                    . 'Sending limits and import limits increase as an account establishes a sending history.',
                ],
            ]);
        }

        $mapping = $reader->guessMapping();

        $batchId = $this->imports->createBatch([
            'user_id'           => $userId,
            'original_filename' => $originalName,
            'stored_path'       => $storedPath,
            'total_rows'        => $totalRows,
            'status'            => self::STEP_UPLOAD,
            'column_map'        => $mapping,
        ]);

        $this->audit->log('contact_import_started', 'import_batch', $batchId, null, [
            'filename' => $originalName,
            'rows'     => $totalRows,
        ]);

        return [
            'batch_id'   => $batchId,
            'headers'    => $headers,
            'preview'    => $reader->preview(5),
            'mapping'    => $mapping,
            'total_rows' => $totalRows,
        ];
    }

    /**
     * Step 3: save the column mapping.
     *
     * @param array<string,string> $mapping contact field => csv header
     */
    public function saveMapping(int $batchId, array $mapping): void
    {
        $batch = $this->requireBatch($batchId);

        $allowed = $this->mappableFields();
        $clean   = [];

        foreach ($mapping as $field => $header) {
            if (isset($allowed[$field]) && is_string($header) && $header !== '') {
                $clean[$field] = $header;
            }
        }

        if (!isset($clean['email'])) {
            throw new ValidationException([
                'mapping' => ['An email column must be mapped: it is how contacts are identified and deduplicated.'],
            ]);
        }

        $this->imports->updateBatch($batchId, [
            'column_map' => $clean,
            'status'     => self::STEP_MAPPED,
        ]);
    }

    /**
     * Step 4: validate without writing anything.
     *
     * Reports invalid addresses, duplicates within the file, contacts that already
     * exist, and addresses that are already suppressed — so the user knows what
     * will happen before it happens.
     *
     * @return array{total:int,valid:int,invalid:int,duplicates_in_file:int,existing:int,suppressed:int,countries:array<string,int>,requires_au_declaration:bool,samples:array<string,array<int,string>>}
     */
    public function validateBatch(int $batchId): array
    {
        $batch   = $this->requireBatch($batchId);
        $mapping = $batch['column_map'] ?? [];

        if (!is_array($mapping) || !isset($mapping['email'])) {
            throw new ValidationException(['mapping' => ['Map the email column before validating.']]);
        }

        $reader = new CsvReader((string) $batch['stored_path']);

        $stats = [
            'total'              => 0,
            'valid'              => 0,
            'invalid'            => 0,
            'duplicates_in_file' => 0,
            'existing'           => 0,
            'suppressed'         => 0,
        ];

        $countries = [];
        $samples   = ['invalid' => [], 'existing' => [], 'suppressed' => []];
        $seen      = [];
        $emails    = [];

        foreach ($reader->rows() as $row) {
            $stats['total']++;

            $email = trim((string) ($row[$mapping['email']] ?? ''));

            if (!is_valid_email($email)) {
                $stats['invalid']++;

                if (count($samples['invalid']) < 5) {
                    $samples['invalid'][] = $email === '' ? '(blank)' : $email;
                }

                continue;
            }

            $normalized = normalize_email($email);

            if (isset($seen[$normalized])) {
                $stats['duplicates_in_file']++;
                continue;
            }

            $seen[$normalized] = true;
            $emails[]          = $normalized;

            $country = strtoupper(substr(trim((string) ($row[$mapping['country'] ?? ''] ?? '')), 0, 2));

            if ($country !== '') {
                $countries[$country] = ($countries[$country] ?? 0) + 1;
            }

            $stats['valid']++;
        }

        // Existing / suppressed checks in chunks rather than per row.
        foreach (array_chunk($emails, 500) as $chunk) {
            $existing = $this->connection->table('contacts')
                ->select('email_normalized')
                ->where('organisation_id', '=', $this->tenant->organisationId())
                ->whereIn('email_normalized', $chunk)
                ->whereNull('deleted_at')
                ->get();

            foreach ($existing as $row) {
                $stats['existing']++;

                if (count($samples['existing']) < 5) {
                    $samples['existing'][] = (string) $row['email_normalized'];
                }
            }

            $suppressed = $this->suppressions->suppressedAmong($chunk);
            $stats['suppressed'] += count($suppressed);

            foreach (array_keys($suppressed) as $email) {
                if (count($samples['suppressed']) < 5) {
                    $samples['suppressed'][] = (string) $email;
                }
            }
        }

        // If the file has no country column, fall back to the organisation's own
        // country: an Australian business importing a list is almost certainly
        // importing Australian contacts, and defaulting to "no declaration
        // needed" would be the wrong way to be wrong.
        $orgCountry = strtoupper((string) ($this->tenant->organisation()['country'] ?? 'US'));
        $requiresAu = ($countries['AU'] ?? 0) > 0 || ($countries === [] && $orgCountry === 'AU');

        $this->imports->updateBatch($batchId, [
            'status'        => self::STEP_CONSENT,
            'invalid_count' => $stats['invalid'],
            'duplicate_count' => $stats['duplicates_in_file'],
            'suppressed_count' => $stats['suppressed'],
        ]);

        return array_merge($stats, [
            'countries'               => $countries,
            'requires_au_declaration' => $requiresAu,
            'samples'                 => $samples,
        ]);
    }

    /**
     * Step 5: the consent declaration. THE GATE.
     *
     * Purchased and scraped declarations are refused here: the batch is marked
     * blocked, the uploaded file is deleted, and no contact row is ever written.
     */
    public function declareConsent(int $batchId, string $source, ?string $reference = null, ?string $consentText = null): void
    {
        $this->requireBatch($batchId);

        /** @var array<string,array<string,mixed>> $sources */
        $sources = $this->config->get('compliance.import_sources', []);

        if (!isset($sources[$source])) {
            throw new ValidationException([
                'consent_source' => ['Select how these contacts were obtained.'],
            ]);
        }

        $definition = $sources[$source];

        if (($definition['blocked'] ?? false) === true) {
            $reason = (string) ($definition['reason'] ?? ReasonCode::IMPORT_PURCHASED_LIST_BLOCKED);

            $this->imports->updateBatch($batchId, [
                'consent_source' => $source,
                'status'         => self::STEP_BLOCKED,
                'blocked_reason' => $reason,
                'error_message'  => (string) ($definition['message'] ?? ReasonCode::describe($reason)),
                'completed_at'   => $this->clock->nowString(),
            ]);

            $this->discardFile($batchId);

            $this->audit->log('contact_import_blocked', 'import_batch', $batchId, null, [
                'consent_source' => $source,
                'reason'         => $reason,
            ]);

            throw new ValidationException([
                'consent_source' => [(string) ($definition['message'] ?? ReasonCode::describe($reason))],
            ]);
        }

        if (($definition['require_reference'] ?? false) === true && trim((string) $reference) === '') {
            throw new ValidationException([
                'consent_reference' => [
                    'A reference to the consent evidence is required for this source '
                    . '(for example a call log reference or the location of the signed forms).',
                ],
            ]);
        }

        $this->imports->updateBatch($batchId, [
            'consent_source'      => $source,
            'consent_reference'   => $reference,
            'consent_text'        => $consentText,
            'consent_declared'    => 1,
            'consent_declared_at' => $this->clock->nowString(),
            'status'              => self::STEP_VALIDATED,
        ]);

        $this->audit->log('contact_import_consent_declared', 'import_batch', $batchId, null, [
            'consent_source'    => $source,
            'consent_reference' => $reference,
        ]);
    }

    /**
     * Steps 6-7: run the import.
     *
     * @param array{duplicate_strategy?:string,list_ids?:array<int,int>,tag_ids?:array<int,int>} $options
     * @return array<string,int> outcome counters
     */
    public function run(int $batchId, array $options = [], ?int $userId = null): array
    {
        $batch = $this->requireBatch($batchId);

        if ((int) ($batch['consent_declared'] ?? 0) !== 1) {
            throw new ValidationException([
                'consent_source' => [ReasonCode::describe(ReasonCode::IMPORT_CONSENT_NOT_DECLARED)],
            ]);
        }

        if ((string) $batch['status'] === self::STEP_BLOCKED) {
            throw new ValidationException([
                'file' => ['This import was blocked and cannot be run.'],
            ]);
        }

        $strategy = (string) ($options['duplicate_strategy'] ?? $batch['duplicate_strategy'] ?? 'update_existing');

        if (!in_array($strategy, ['update_existing', 'skip_existing', 'merge_fill_blanks'], true)) {
            $strategy = 'update_existing';
        }

        $listIds = array_map('intval', $options['list_ids'] ?? []);
        $tagIds  = array_map('intval', $options['tag_ids'] ?? []);

        $this->imports->updateBatch($batchId, [
            'status'             => self::STEP_IMPORTING,
            'duplicate_strategy' => $strategy,
            'target_list_ids'    => $listIds,
            'target_tag_ids'     => $tagIds,
            'started_at'         => $this->clock->nowString(),
        ]);

        /** @var array<string,array<string,mixed>> $sources */
        $sources          = $this->config->get('compliance.import_sources', []);
        $consentSource    = (string) $batch['consent_source'];
        $consentDefinition = $sources[$consentSource] ?? ['status' => 'unknown', 'consent_type' => 'other'];

        $mapping = is_array($batch['column_map'] ?? null) ? $batch['column_map'] : [];
        $reader  = new CsvReader((string) $batch['stored_path']);

        $counters = [
            'imported' => 0, 'updated' => 0, 'skipped' => 0,
            'invalid' => 0, 'duplicate' => 0, 'suppressed' => 0, 'no_consent' => 0,
        ];

        $seen      = [];
        $rowBuffer = [];

        foreach ($reader->rows() as $rowNumber => $row) {
            $outcome = $this->importRow(
                $row,
                $rowNumber,
                $mapping,
                $strategy,
                $consentSource,
                $consentDefinition,
                (string) ($batch['consent_reference'] ?? ''),
                (string) ($batch['consent_text'] ?? ''),
                $listIds,
                $tagIds,
                $userId,
                $seen,
                $counters
            );

            $rowBuffer[] = array_merge($outcome, [
                'organisation_id' => $this->tenant->organisationId(),
                'import_batch_id' => $batchId,
                'row_number'      => $rowNumber,
            ]);

            // Flush periodically so the audit of the import itself is also
            // streamed rather than accumulated.
            if (count($rowBuffer) >= 500) {
                $this->imports->recordRows($rowBuffer);
                $rowBuffer = [];
            }
        }

        if ($rowBuffer !== []) {
            $this->imports->recordRows($rowBuffer);
        }

        foreach ($listIds as $listId) {
            $this->lists->refreshCount($listId);
        }

        foreach ($tagIds as $tagId) {
            $this->tags->refreshCount($tagId);
        }

        $this->imports->updateBatch($batchId, [
            'status'           => self::STEP_COMPLETED,
            'imported_count'   => $counters['imported'],
            'updated_count'    => $counters['updated'],
            'skipped_count'    => $counters['skipped'],
            'invalid_count'    => $counters['invalid'],
            'duplicate_count'  => $counters['duplicate'],
            'suppressed_count' => $counters['suppressed'],
            'no_consent_count' => $counters['no_consent'],
            'completed_at'     => $this->clock->nowString(),
        ]);

        // The uploaded file has served its purpose; keeping raw customer data on
        // disk after the import adds risk and no value.
        $this->discardFile($batchId);

        $this->audit->log('contact_import_completed', 'import_batch', $batchId, null, $counters);

        return $counters;
    }

    /**
     * @param array<string,string>     $row
     * @param array<string,string>     $mapping
     * @param array<string,mixed>      $consentDefinition
     * @param array<int,int>           $listIds
     * @param array<int,int>           $tagIds
     * @param array<string,bool>       $seen
     * @param array<string,int>        $counters
     * @return array{email:?string,outcome:string,message:?string,contact_id:?int}
     */
    private function importRow(
        array $row,
        int $rowNumber,
        array $mapping,
        string $strategy,
        string $consentSource,
        array $consentDefinition,
        string $consentReference,
        string $consentText,
        array $listIds,
        array $tagIds,
        ?int $userId,
        array &$seen,
        array &$counters,
    ): array {
        $email = trim((string) ($row[$mapping['email'] ?? 'email'] ?? ''));

        if (!is_valid_email($email)) {
            $counters['invalid']++;

            return [
                'email'      => $email === '' ? null : $email,
                'outcome'    => 'invalid_email',
                'message'    => 'Not a valid email address',
                'contact_id' => null,
            ];
        }

        $normalized = normalize_email($email);

        if (isset($seen[$normalized])) {
            $counters['duplicate']++;

            return [
                'email'      => $email,
                'outcome'    => 'skipped_duplicate',
                'message'    => 'Duplicate row within the uploaded file',
                'contact_id' => null,
            ];
        }

        $seen[$normalized] = true;

        $attributes = $this->mapRow($row, $mapping);
        $existing   = $this->contacts->findByEmail($email);

        // Suppression is checked for reporting, but it never changes: an imported
        // row cannot clear it, and the contact is still created/updated so the CRM
        // record is complete — it simply will not be sent marketing email.
        $suppressed = $this->suppressions->isSuppressed($email);

        if ($suppressed) {
            $counters['suppressed']++;
        }

        try {
            if ($existing !== null) {
                if ($strategy === 'skip_existing') {
                    $counters['skipped']++;

                    return [
                        'email'      => $email,
                        'outcome'    => 'skipped_duplicate',
                        'message'    => 'Contact already exists',
                        'contact_id' => (int) $existing['id'],
                    ];
                }

                $contactId = (int) $existing['id'];
                $payload   = $strategy === 'merge_fill_blanks'
                    ? $this->onlyBlanks($existing, $attributes)
                    : $attributes;

                if ($payload !== []) {
                    $this->contactService->update($contactId, $payload);
                }

                $counters['updated']++;
                $outcome = 'updated';
            } else {
                $contactId = $this->contactService->create(array_merge($attributes, [
                    'email'         => $email,
                    'source'        => $attributes['source'] ?? 'import',
                    'source_detail' => 'CSV import (' . $consentSource . ')',
                ]), $this->consentEvidenceFor($consentSource, $consentDefinition, $consentReference, $consentText, $userId));

                $counters['imported']++;
                $outcome = 'imported';
            }

            // For an existing contact, the import's consent declaration is still
            // recorded — it is new evidence, appended, never overwriting.
            if ($existing !== null) {
                $this->applyImportConsent(
                    $contactId,
                    $consentSource,
                    $consentDefinition,
                    $consentReference,
                    $consentText,
                    $userId
                );
            }

            foreach ($tagIds as $tagId) {
                $this->tags->attach($contactId, $tagId, $userId);
            }

            foreach ($listIds as $listId) {
                $this->lists->addContact($listId, $contactId, 'import');
            }

            if ((string) ($consentDefinition['status'] ?? 'unknown') === 'unknown') {
                $counters['no_consent']++;
            }

            return [
                'email'      => $email,
                'outcome'    => $outcome,
                'message'    => $suppressed ? 'Imported; address remains suppressed' : null,
                'contact_id' => $contactId,
            ];
        } catch (ValidationException $e) {
            $counters['invalid']++;

            return [
                'email'      => $email,
                'outcome'    => 'error',
                'message'    => implode('; ', $e->firstErrors()),
                'contact_id' => null,
            ];
        }
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function consentEvidenceFor(
        string $source,
        array $definition,
        string $reference,
        string $consentText,
        ?int $userId,
    ): array {
        return [
            'status'              => (string) ($definition['status'] ?? 'unknown'),
            'consent_type'        => (string) ($definition['consent_type'] ?? 'other'),
            'source'              => 'import',
            'source_reference'    => $reference !== '' ? $reference : 'import_source:' . $source,
            'consent_text'        => $consentText !== '' ? $consentText : null,
            'recorded_by_user_id' => $userId,
            'channel'             => 'email',
        ];
    }

    /** @param array<string,mixed> $definition */
    private function applyImportConsent(
        int $contactId,
        string $source,
        array $definition,
        string $reference,
        string $consentText,
        ?int $userId,
    ): void {
        $evidence = $this->consentEvidenceFor($source, $definition, $reference, $consentText, $userId);

        match ((string) $evidence['status']) {
            'granted' => $this->consent->grant($contactId, $evidence),
            default   => $this->consent->recordUnknown($contactId, $evidence),
        };
    }

    /**
     * @param array<string,string> $row
     * @param array<string,string> $mapping
     * @return array<string,mixed>
     */
    private function mapRow(array $row, array $mapping): array
    {
        $attributes = [];

        foreach ($this->mappableFields() as $field => $label) {
            if (!isset($mapping[$field])) {
                continue;
            }

            $value = trim((string) ($row[$mapping[$field]] ?? ''));

            if ($value === '') {
                continue;
            }

            $attributes[$field] = $field === 'country' ? strtoupper(substr($value, 0, 2)) : $value;
        }

        unset($attributes['email']);

        return $attributes;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    private function onlyBlanks(array $existing, array $incoming): array
    {
        $payload = [];

        foreach ($incoming as $key => $value) {
            $current = $existing[$key] ?? null;

            if ($current === null || $current === '' || $current === 0 || $current === '0') {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /** @return array<string,string> */
    public function mappableFields(): array
    {
        return [
            'email'           => 'Email address',
            'first_name'      => 'First name',
            'last_name'       => 'Last name',
            'phone'           => 'Phone',
            'company'         => 'Company',
            'job_title'       => 'Job title',
            'country'         => 'Country',
            'state'           => 'State / region',
            'city'            => 'City',
            'postcode'        => 'Postcode',
            'customer_status' => 'Customer status',
            'lifecycle_stage' => 'Lifecycle stage',
            'source'          => 'Source',
            'notes'           => 'Notes',
        ];
    }

    /** @return array<string,mixed> */
    public function batch(int $batchId): array
    {
        return $this->requireBatch($batchId);
    }

    /** @return array<int,array<string,mixed>> */
    public function recentBatches(int $limit = 20): array
    {
        return $this->imports->recentBatches($limit);
    }

    /** @return array<string,int> */
    public function outcomeCounts(int $batchId): array
    {
        return $this->imports->outcomeCounts($batchId);
    }

    /** @return array<int,array<string,mixed>> */
    public function problemRows(int $batchId, int $limit = 100): array
    {
        $rows = [];

        foreach (['invalid_email', 'error', 'skipped_duplicate'] as $outcome) {
            foreach ($this->imports->rowsForBatch($batchId, $outcome, $limit) as $row) {
                $rows[] = $row;
            }
        }

        return array_slice($rows, 0, $limit);
    }

    /** @return array<string,mixed> */
    private function requireBatch(int $batchId): array
    {
        $batch = $this->imports->findBatch($batchId);

        if ($batch === null) {
            throw \App\Core\HttpException::notFound();
        }

        return $batch;
    }

    private function rowLimit(): int
    {
        $trustLevel = (string) ($this->tenant->organisation()['trust_level'] ?? 'new');

        return (int) $this->config->get('antiabuse.trust_levels.' . $trustLevel . '.max_import_rows', 5_000);
    }

    private function storeUpload(string $tmpPath, string $originalName): string
    {
        $directory = base_path('storage/uploads/imports/' . $this->tenant->organisationId());

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the import directory.');
        }

        // The stored name never contains user-controlled path characters.
        $target = $directory . '/' . $this->clock->now()->format('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.csv';

        // move_uploaded_file() in production; copy() keeps this testable.
        $moved = is_uploaded_file($tmpPath)
            ? move_uploaded_file($tmpPath, $target)
            : copy($tmpPath, $target);

        if (!$moved) {
            throw new \RuntimeException('Unable to store the uploaded file.');
        }

        @chmod($target, 0640);

        return $target;
    }

    private function discardFile(int $batchId): void
    {
        $batch = $this->imports->findBatch($batchId);
        $path  = (string) ($batch['stored_path'] ?? '');

        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }

        $this->imports->updateBatch($batchId, ['stored_path' => null]);
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL   => 'The upload did not complete. Please try again.',
            UPLOAD_ERR_NO_FILE   => 'Please choose a CSV file to upload.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload.',
            default              => 'The file could not be uploaded.',
        };
    }
}
