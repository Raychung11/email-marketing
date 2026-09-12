<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\AuthManager;
use App\Services\SuppressionService;
use App\Support\Str;

/**
 * The suppression list.
 *
 * Adding is easy; removing is deliberate, audited and limited to
 * compliance.manage — the route middleware enforces that, and this controller
 * requires a written reason.
 */
final class SuppressionController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly SuppressionService $suppressions,
        private readonly AuthManager $auth,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search'),
            'reason' => $request->string('reason'),
        ];

        return $this->render('crm.suppressions_index', [
            'result'  => $this->suppressions->paginate($filters, $this->page($request), $this->perPage($request)),
            'filters' => $filters,
            'reasons' => $this->config->get('compliance.suppression_reasons', []),
            'counts'  => $this->suppressions->reasonCounts(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'email'  => 'required|email|max:255',
            'detail' => 'nullable|max:255',
        ]);

        $this->suppressions->suppressManually(
            (string) $data['email'],
            (int) $this->auth->id(),
            $data['detail'] ?? null
        );

        return $this->withSuccess('/suppressions', 'Address suppressed. It will be excluded from all marketing sends.');
    }

    public function destroy(Request $request): Response
    {
        $id = (int) $request->route('id');

        $data = $this->validate($request, [
            'reason' => 'required|max:255',
        ], [
            'reason' => 'A reason is required: removing a suppression is an audited action.',
        ]);

        $removed = $this->suppressions->remove($id, (int) $this->auth->id(), (string) $data['reason']);

        if (!$removed) {
            return $this->withError('/suppressions', 'That suppression could not be found.');
        }

        return $this->withSuccess(
            '/suppressions',
            'Suppression removed and recorded in the audit log. '
            . 'Make sure you have a lawful basis before sending to this address again.'
        );
    }

    /** Export the suppression list. Every organisation should keep a copy. */
    public function export(Request $request): Response
    {
        $result = $this->suppressions->paginate([], 1, 100_000);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return $this->withError('/suppressions', 'Unable to build the export.');
        }

        fputcsv($handle, ['email', 'reason', 'source', 'provider', 'detail', 'created_at']);

        foreach ($result['rows'] as $row) {
            fputcsv($handle, [
                Str::csvSafe((string) $row['email']),
                (string) $row['reason'],
                (string) ($row['source'] ?? ''),
                (string) ($row['provider'] ?? ''),
                Str::csvSafe((string) ($row['detail'] ?? '')),
                (string) $row['created_at'],
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return Response::make($csv)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="suppression-list.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
