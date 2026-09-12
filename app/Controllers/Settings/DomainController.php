<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\SendingDomainRepository;
use App\Services\SendingDomainService;
use App\Services\TransactionalMailer;
use App\Support\TenantContext;

final class DomainController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        private readonly SendingDomainService $domains,
        private readonly SendingDomainRepository $repository,
        private readonly TransactionalMailer $mailer,
        private readonly TenantContext $tenant,
    ) {
        parent::__construct($view, $session, $config);
    }

    public function index(Request $request): Response
    {
        return $this->render('settings.domains', [
            'domains'      => $this->domains->all(),
            'organisation' => $this->tenant->organisation(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, ['domain' => 'required|max:190']);

        $id = $this->domains->add((string) $data['domain']);

        return $this->withSuccess(
            '/settings/domains/' . $id,
            'Domain added. Publish the DNS records below, then verify.'
        );
    }

    public function show(Request $request): Response
    {
        $id     = (int) $request->route('id');
        $domain = $this->repository->findOrFailWithRecords($id);

        // A domain with no records yet has never been through the provider; fetch
        // them now rather than showing an empty setup page.
        if ($domain['dns_records'] === []) {
            $this->domains->refreshRecords($id);
            $domain = $this->repository->findOrFailWithRecords($id);
        }

        return $this->render('settings.domain_show', [
            'domain'       => $domain,
            'findings'     => $this->session->getFlash('findings') ?? [],
            'organisation' => $this->tenant->organisation(),
        ]);
    }

    public function verify(Request $request): Response
    {
        $id     = (int) $request->route('id');
        $result = $this->domains->verify($id);

        $this->session->flash('findings', $result['findings']);

        if ($result['status'] === 'verified') {
            return $this->withSuccess(
                '/settings/domains/' . $id,
                'Verified. You can now send campaigns from this domain.'
            );
        }

        return $this->withError(
            '/settings/domains/' . $id,
            'Not verified yet. DNS changes can take up to 72 hours to propagate — the details below say what is missing.'
        );
    }

    public function refresh(Request $request): Response
    {
        $id = (int) $request->route('id');
        $this->domains->refreshRecords($id);

        return $this->withSuccess('/settings/domains/' . $id, 'DNS records refreshed from your email provider.');
    }

    public function sendTest(Request $request): Response
    {
        $id   = (int) $request->route('id');
        $data = $this->validate($request, ['recipient' => 'required|email|max:255']);

        $sent = $this->domains->sendTestEmail($id, (string) $data['recipient'], $this->mailer);

        return $sent
            ? $this->withSuccess('/settings/domains/' . $id, 'Test message sent to ' . $data['recipient'] . '.')
            : $this->withError('/settings/domains/' . $id, 'The provider refused the test message. Check the logs.');
    }

    public function destroy(Request $request): Response
    {
        $this->domains->remove((int) $request->route('id'));

        return $this->withSuccess('/settings/domains', 'Domain removed.');
    }
}
