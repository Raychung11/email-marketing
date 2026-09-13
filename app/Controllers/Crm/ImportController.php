<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ListRepository;
use App\Repositories\TagRepository;
use App\Services\AuthManager;
use App\Services\ImportService;
use App\Support\TenantContext;

/**
 * The CSV import wizard.
 *
 * Step 5 (the consent declaration) is a hard gate, not a formality: a purchased
 * or scraped declaration ends the import there and no contact row is written.
 */
final class ImportController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly ImportService $imports,
        private readonly TagRepository $tags,
        private readonly ListRepository $lists,
        private readonly AuthManager $auth,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        $trustLevel = (string) ($this->tenant->organisation()['trust_level'] ?? 'new');

        return $this->render('crm.import_index', [
            'batches'    => $this->imports->recentBatches(),
            'trustLevel' => $trustLevel,
            'rowLimit'   => (int) $this->config->get(
                'antiabuse.trust_levels.' . $trustLevel . '.max_import_rows',
                5000
            ),
            'sources'    => $this->config->get('compliance.import_sources', []),
        ]);
    }

    /**
     * A starter CSV, built from the same field registry the importer maps
     * against. Generated rather than stored as a file on purpose: a template
     * kept by hand drifts from the fields the importer actually accepts, and
     * the person who finds out is the one whose import fails.
     *
     * Only `email` is required. Every other column can be deleted.
     */
    public function template(Request $request): Response
    {
        $fields = array_keys($this->imports->mappableFields());

        $examples = [
            [
                'email'           => 'sarah.mitchell@example.com',
                'first_name'      => 'Sarah',
                'last_name'       => 'Mitchell',
                'phone'           => '+61 412 345 678',
                'company'         => 'Mitchell Property Group',
                'job_title'       => 'Owner',
                'country'         => 'AU',
                'state'           => 'VIC',
                'city'            => 'Melbourne',
                'postcode'        => '3000',
                'customer_status' => 'customer',
                'lifecycle_stage' => 'customer',
                'source'          => 'Website enquiry form',
                'notes'           => 'Bathroom renovation, June 2025',
            ],
            [
                'email'           => 'james.oconnor@example.com',
                'first_name'      => 'James',
                'last_name'       => "O'Connor",
                'phone'           => '+1 512 555 0147',
                'company'         => 'Lone Star Dental',
                'job_title'       => 'Practice Manager',
                'country'         => 'US',
                'state'           => 'TX',
                'city'            => 'Austin',
                'postcode'        => '78701',
                'customer_status' => 'prospect',
                'lifecycle_stage' => 'lead',
                'source'          => 'Trade show, March 2025',
                'notes'           => 'Asked about the annual plan',
            ],
            [
                'email'           => 'priya.sharma@example.com',
                'first_name'      => 'Priya',
                'last_name'       => 'Sharma',
                'phone'           => '',
                'company'         => '',
                'job_title'       => '',
                'country'         => 'AU',
                'state'           => 'NSW',
                'city'            => 'Sydney',
                'postcode'        => '2000',
                'customer_status' => 'customer',
                'lifecycle_stage' => 'customer',
                'source'          => 'Shop counter signup sheet',
                'notes'           => '',
            ],
        ];

        $lines = [$this->csvRow($fields)];

        foreach ($examples as $example) {
            $lines[] = $this->csvRow(array_map(
                static fn (string $field): string => (string) ($example[$field] ?? ''),
                $fields
            ));
        }

        // A BOM, so Excel opens accented names and "O'Connor" correctly instead
        // of turning them into mojibake the moment the file is saved again.
        $csv = "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";

        return Response::text($csv)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="contact-import-template.csv"');
    }

    /** @param array<int,string> $values */
    private function csvRow(array $values): string
    {
        return implode(',', array_map(static function (string $value): string {
            // Quote anything that would otherwise break the row apart, and
            // double any quote inside it.
            return preg_match('/[",\r\n]/', $value) === 1
                ? '"' . str_replace('"', '""', $value) . '"'
                : $value;
        }, $values));
    }

    /** Step 1-2: upload, then show the header preview and suggested mapping. */
    public function upload(Request $request): Response
    {
        $file = $request->file('file');

        if ($file === null) {
            return $this->withError('/contacts/import', 'Please choose a CSV file to upload.');
        }

        $result = $this->imports->beginUpload($file, (int) $this->auth->id());

        return $this->redirect('/contacts/import/' . $result['batch_id'] . '/map');
    }

    /** Step 3: column mapping. */
    public function showMapping(Request $request): Response
    {
        $batchId = (int) $request->route('id');
        $batch   = $this->imports->batch($batchId);

        // The uploaded file is deleted once an import finishes or is refused, so
        // there is nothing left to map. Send the user somewhere useful.
        $storedPath = (string) ($batch['stored_path'] ?? '');

        if ($storedPath === '' || !is_file($storedPath)) {
            return (string) $batch['status'] === ImportService::STEP_COMPLETED
                ? $this->redirect('/contacts/import/' . $batchId . '/summary')
                : $this->withError(
                    '/contacts/import',
                    'That import is no longer available — its uploaded file has been removed. Please upload it again.'
                );
        }

        $reader = new \App\Support\CsvReader($storedPath);

        return $this->render('crm.import_map', [
            'batch'   => $batch,
            'headers' => $reader->headers(),
            'preview' => $reader->preview(5),
            'fields'  => $this->imports->mappableFields(),
            'mapping' => is_array($batch['column_map'] ?? null) ? $batch['column_map'] : [],
        ]);
    }

    public function saveMapping(Request $request): Response
    {
        $batchId = (int) $request->route('id');

        /** @var array<string,string> $mapping */
        $mapping = $request->input('mapping', []);

        $this->imports->saveMapping($batchId, is_array($mapping) ? $mapping : []);

        return $this->redirect('/contacts/import/' . $batchId . '/validate');
    }

    /** Step 4: validation report, no writes. */
    public function showValidation(Request $request): Response
    {
        $batchId = (int) $request->route('id');

        return $this->render('crm.import_validate', [
            'batch'   => $this->imports->batch($batchId),
            'report'  => $this->imports->validateBatch($batchId),
            'sources' => $this->config->get('compliance.import_sources', []),
        ]);
    }

    /** Step 5: the consent declaration gate. */
    public function declareConsent(Request $request): Response
    {
        $batchId = (int) $request->route('id');

        $data = $this->validate($request, [
            'consent_source'    => 'required|max:40',
            'consent_reference' => 'nullable|max:255',
            'consent_text'      => 'nullable|max:2000',
        ]);

        $this->imports->declareConsent(
            $batchId,
            (string) $data['consent_source'],
            $data['consent_reference'] ?? null,
            $data['consent_text'] ?? null
        );

        return $this->redirect('/contacts/import/' . $batchId . '/options');
    }

    /** Step 6: duplicate handling and destinations. */
    public function showOptions(Request $request): Response
    {
        $batchId = (int) $request->route('id');

        return $this->render('crm.import_options', [
            'batch' => $this->imports->batch($batchId),
            'tags'  => $this->tags->all(),
            'lists' => $this->lists->all(),
        ]);
    }

    /** Step 7: run it. */
    public function run(Request $request): Response
    {
        $batchId = (int) $request->route('id');

        $this->imports->run($batchId, [
            'duplicate_strategy' => $request->string('duplicate_strategy', 'update_existing'),
            'list_ids'           => array_map('intval', $request->array('list_ids')),
            'tag_ids'            => array_map('intval', $request->array('tag_ids')),
        ], $this->auth->id());

        return $this->redirect('/contacts/import/' . $batchId . '/summary');
    }

    /** Step 8: summary. */
    public function summary(Request $request): Response
    {
        $batchId = (int) $request->route('id');

        return $this->render('crm.import_summary', [
            'batch'        => $this->imports->batch($batchId),
            'outcomes'     => $this->imports->outcomeCounts($batchId),
            'problemRows'  => $this->imports->problemRows($batchId, 100),
        ]);
    }
}
