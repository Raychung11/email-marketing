<?php

declare(strict_types=1);

namespace App\Controllers\Marketing;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\OutboxService;
use App\Support\MessageStatus;
use App\Support\Str;

/**
 * The outbox.
 *
 * Read-only on purpose. Nothing here can resend, cancel or edit a message: once
 * an email has been handed to the provider it is gone, and a button suggesting
 * otherwise would be a lie. Resending is a campaign-level decision made on the
 * campaign page, where the audience rules and the compliance checks still apply.
 */
final class OutboxController extends Controller
{
    private const WINDOWS = [0 => 'All time', 1 => 'Last 24 hours', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];

    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly OutboxService $outbox,
    ) {
        parent::__construct($view, $session, $config);
    }

    /** GET /outbox */
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return $this->render('marketing.outbox', [
            'result'    => $this->outbox->paginate($filters, $this->page($request), $this->perPage($request)),
            'summary'   => $this->outbox->summary($filters),
            'filters'   => $filters,
            'campaigns' => $this->outbox->campaignOptions(),
            'statuses'  => MessageStatus::ALL,
            'windows'   => self::WINDOWS,
        ]);
    }

    /**
     * GET /outbox/export
     *
     * Capped at 10,000 rows. A shared-hosting PHP process building a CSV of
     * every message ever sent is a memory limit waiting to happen, and the
     * filters are there precisely so nobody needs the whole table at once.
     */
    public function export(Request $request): Response
    {
        $filters = $this->filters($request);
        $result  = $this->outbox->paginate($filters, 1, 10_000);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return $this->withError('/outbox', 'Unable to build the export.');
        }

        fputcsv($handle, ['email', 'subject', 'campaign', 'status', 'status_plain', 'failure_reason', 'created_at_utc', 'sent_at_utc', 'delivered_at_utc', 'opened', 'clicked']);

        foreach ($result['rows'] as $row) {
            fputcsv($handle, [
                Str::csvSafe((string) $row['email']),
                Str::csvSafe((string) ($row['subject'] ?? '')),
                Str::csvSafe((string) ($row['campaign_name'] ?? '')),
                (string) $row['status'],
                (string) $row['status_label'],
                Str::csvSafe((string) ($row['failure_reason'] ?? '')),
                (string) $row['created_at'],
                (string) ($row['sent_at'] ?? ''),
                (string) ($row['delivered_at'] ?? ''),
                ($row['opened_at'] ?? null) !== null ? 'yes' : 'no',
                ($row['clicked_at'] ?? null) !== null ? 'yes' : 'no',
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return Response::make($csv)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="outbox.csv"');
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        $status = $request->string('status');
        $days   = $request->int('days', 30);

        return [
            'search'   => $request->string('search'),
            // An unknown status in the query string is dropped rather than passed
            // through: it would otherwise silently return an empty list and look
            // like "you have never sent anything".
            'status'   => in_array($status, MessageStatus::ALL, true) ? $status : '',
            'campaign' => $request->int('campaign'),
            'class'    => in_array($request->string('class'), ['marketing', 'transactional'], true)
                ? $request->string('class')
                : '',
            'days'     => array_key_exists($days, self::WINDOWS) ? $days : 30,
        ];
    }
}
