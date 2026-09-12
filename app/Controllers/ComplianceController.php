<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Database\Connection;
use App\Repositories\AuditLogRepository;
use App\Repositories\ComplianceRuleRepository;
use App\Services\ConsentService;
use App\Services\OrganisationService;
use App\Services\SuppressionService;
use App\Support\TenantContext;

/**
 * The compliance centre: the rules currently in force, consent posture,
 * suppression totals and the audit trail.
 */
final class ComplianceController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly ComplianceRuleRepository $rules,
        private readonly ConsentService $consent,
        private readonly SuppressionService $suppressions,
        private readonly AuditLogRepository $auditLogs,
        private readonly OrganisationService $organisations,
        private readonly Connection $connection,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        $organisationId = $this->tenant->organisationId();
        $organisation   = $this->tenant->organisation();

        $country    = strtoupper((string) ($organisation['country'] ?? 'US'));
        $activeRule = $this->rules->forCountry($country, $organisationId);

        // Where do contacts actually live? The rule set that applies is the
        // contact's, not the organisation's, so this matters.
        $byCountry = $this->connection->select(
            'SELECT country, COUNT(*) AS total FROM contacts
             WHERE organisation_id = ? AND deleted_at IS NULL
             GROUP BY country ORDER BY total DESC',
            [$organisationId]
        );

        $unsubscribes = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM unsubscribe_events WHERE organisation_id = ?',
            [$organisationId]
        );

        return $this->render('settings.compliance', [
            'organisation'   => $organisation,
            'activeRule'     => $activeRule,
            'ruleConfig'     => $this->decodeRule($activeRule),
            'allRules'       => $this->rules->all(),
            'consent'        => $this->consent->statusBreakdown('email'),
            'suppression'    => $this->suppressions->reasonCounts(),
            'suppressionTotal' => $this->suppressions->activeCount(),
            'byCountry'      => $byCountry,
            'unsubscribes'   => $unsubscribes,
            'auditLogs'      => $this->auditLogs
                ->forOrganisation($organisationId, ['entity_type' => $request->string('entity_type')])
                ->limit(50)
                ->get(),
            'countries'      => $this->config->get('app.supported_countries', []),
            'importSources'  => $this->config->get('compliance.import_sources', []),
        ]);
    }

    /**
     * Organisation-level compliance settings.
     *
     * Note what is NOT settable here: whether consent is required. That comes
     * from the jurisdiction's rule set, and no tenant preference overrides it.
     */
    public function update(Request $request): Response
    {
        $data = $this->validate($request, [
            'require_campaign_approval' => 'nullable|boolean',
        ]);

        $this->organisations->update($this->tenant->organisationId(), [
            'require_campaign_approval' => $request->bool('require_campaign_approval') ? 1 : 0,
        ]);

        return $this->withSuccess('/compliance', 'Compliance settings updated.');
    }

    public function auditLog(Request $request): Response
    {
        $filters = [
            'action'      => $request->string('action'),
            'entity_type' => $request->string('entity_type'),
            'user_id'     => $request->int('user_id'),
            'from'        => $request->string('from'),
            'to'          => $request->string('to'),
        ];

        $query   = $this->auditLogs->forOrganisation($this->tenant->organisationId(), $filters);
        $total   = (clone $query)->count();
        $perPage = $this->perPage($request);

        return $this->render('settings.audit_log', [
            'logs'    => $query->forPage($this->page($request), $perPage)->get(),
            'filters' => $filters,
            'total'   => $total,
            'page'    => $this->page($request),
            'perPage' => $perPage,
            'pages'   => (int) ceil($total / max(1, $perPage)),
        ]);
    }

    /** @param array<string,mixed>|null $rule */
    private function decodeRule(?array $rule): array
    {
        if ($rule === null) {
            return [];
        }

        $configuration = $rule['configuration_json'] ?? [];

        if (is_string($configuration)) {
            $decoded       = json_decode($configuration, true);
            $configuration = is_array($decoded) ? $decoded : [];
        }

        return is_array($configuration) ? $configuration : [];
    }
}
