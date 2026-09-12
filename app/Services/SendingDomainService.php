<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\ValidationException;
use App\Mail\EmailProviderInterface;
use App\Repositories\SendingDomainRepository;
use App\Support\DnsResolver;
use App\Support\TenantContext;

/**
 * Sending domain setup and verification.
 *
 * Authentication is not optional decoration: unauthenticated mail from a shared
 * IP pool lands in spam, and it lets anyone spoof the customer's domain. So a
 * campaign cannot be sent from a domain that has not passed DKIM here.
 *
 * The three checks are deliberately graded:
 *
 *   DKIM  — REQUIRED. Without it the mail is not authenticated at all.
 *   SPF   — required to be *present and not contradictory*. We do not demand a
 *           strict `-all`, because tightening SPF can break a customer's other
 *           mail and that is their decision, not ours.
 *   DMARC — recommended, and reported, but never blocking. Publishing
 *           `p=reject` before a customer knows what else sends as them is how
 *           you take down their invoicing.
 */
final class SendingDomainService
{
    private const SES_SPF_INCLUDE = 'amazonses.com';

    public function __construct(
        private readonly SendingDomainRepository $domains,
        private readonly EmailProviderInterface $provider,
        private readonly DnsResolver $dns,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->domains->all();
    }

    /**
     * Step 1: register the domain and fetch the records the customer must publish.
     */
    public function add(string $domain): int
    {
        $domain = $this->normalise($domain);

        if ($this->domains->findByDomain($domain) !== null) {
            throw new ValidationException(['domain' => ['That domain has already been added.']]);
        }

        $limit = $this->domainLimit();

        if ($limit > 0 && count($this->domains->all()) >= $limit) {
            throw new ValidationException([
                'domain' => ['Your plan allows ' . $limit . ' sending ' . ($limit === 1 ? 'domain' : 'domains') . '.'],
            ]);
        }

        // Ask the provider to create the identity and tell us its DKIM tokens.
        $records = $this->provider->dnsRecordsFor($domain);

        $id = $this->domains->create($domain, [
            'provider'    => $this->provider->name(),
            'dns_records' => $records,
            'status'      => 'pending',
        ]);

        $this->audit->log('domain_added', 'sending_domain', $id, null, ['domain' => $domain]);

        return $id;
    }

    public function remove(int $id): void
    {
        $domain = $this->domains->findOrFail($id);

        $this->domains->delete($id);

        $this->audit->log('domain_removed', 'sending_domain', $id, ['domain' => $domain['domain']]);
    }

    /**
     * Re-read the provider's records for a domain.
     *
     * DKIM tokens are issued by the provider, so if the identity was created
     * outside the app — or the provider re-issued them — this picks them up.
     *
     * @return array<int,array{type:string,name:string,value:string,purpose:string}>
     */
    public function refreshRecords(int $id): array
    {
        $domain  = $this->domains->findOrFailWithRecords($id);
        $records = $this->provider->dnsRecordsFor((string) $domain['domain']);

        if ($records !== []) {
            $this->domains->update($id, ['dns_records' => $records]);

            return $records;
        }

        /** @var array<int,array{type:string,name:string,value:string,purpose:string}> $existing */
        $existing = $domain['dns_records'];

        return $existing;
    }

    /**
     * Step 4-7: check what is actually published and record the outcome.
     *
     * @return array{
     *   status:string, dkim:string, spf:string, dmarc:string,
     *   findings:array<int,array{record:string,status:string,message:string}>
     * }
     */
    public function verify(int $id): array
    {
        $domainRow = $this->domains->findOrFailWithRecords($id);
        $domain    = (string) $domainRow['domain'];

        /** @var array<int,array{type:string,name:string,value:string,purpose:string}> $expected */
        $expected = $domainRow['dns_records'];

        if ($expected === []) {
            $expected = $this->refreshRecords($id);
        }

        $findings = [];

        $dkim  = $this->checkDkim($expected, $findings);
        $spf   = $this->checkSpf($domain, $findings);
        $dmarc = $this->checkDmarc($domain, $findings);

        // DKIM alone decides whether the domain may send. The provider is also
        // asked, because it will not accept mail for an identity it considers
        // unverified however good the DNS looks from here.
        $providerStatus = $this->provider->validateIdentity($domain);

        $verified = $dkim === 'verified' && ($providerStatus->verified || !$this->provider->isConfigured());

        if (!$providerStatus->verified && $this->provider->isConfigured()) {
            $findings[] = [
                'record'  => 'provider',
                'status'  => 'failed',
                'message' => 'Your email provider has not yet confirmed this identity. '
                    . 'DNS changes can take up to 72 hours to propagate; this will clear on its own.',
            ];
        }

        $status = $verified ? 'verified' : ($dkim === 'failed' ? 'failed' : 'pending');

        $this->domains->update($id, [
            'status'          => $status,
            'dkim_status'     => $dkim,
            'spf_status'      => $spf,
            'dmarc_status'    => $dmarc,
            'last_checked_at' => $this->clock->nowString(),
            'verified_at'     => $verified
                ? ($domainRow['verified_at'] ?? $this->clock->nowString())
                : null,
            'last_error'      => $verified ? null : $this->firstFailure($findings),
        ]);

        if ($verified && (string) $domainRow['status'] !== 'verified') {
            $this->audit->log('domain_verified', 'sending_domain', $id, null, ['domain' => $domain]);
        } elseif (!$verified) {
            $this->audit->log('domain_verification_failed', 'sending_domain', $id, null, [
                'domain' => $domain,
                'dkim'   => $dkim,
            ]);
        }

        return [
            'status'   => $status,
            'dkim'     => $dkim,
            'spf'      => $spf,
            'dmarc'    => $dmarc,
            'findings' => $findings,
        ];
    }

    /**
     * DKIM: every expected CNAME must resolve to the expected target.
     *
     * Partial publication is the most common mistake — people paste two of the
     * three records — so the finding says which one is missing rather than just
     * "DKIM failed".
     *
     * @param array<int,array{type:string,name:string,value:string,purpose:string}> $expected
     * @param array<int,array{record:string,status:string,message:string}> $findings
     */
    private function checkDkim(array $expected, array &$findings): string
    {
        $cnames = array_values(array_filter(
            $expected,
            static fn (array $record): bool => strtoupper($record['type']) === 'CNAME'
        ));

        if ($cnames === []) {
            $findings[] = [
                'record'  => 'DKIM',
                'status'  => 'pending',
                'message' => 'No DKIM records have been issued yet. Refresh the records and try again.',
            ];

            return 'pending';
        }

        $missing = [];

        foreach ($cnames as $record) {
            $published = $this->dns->cname($record['name']);
            $wanted    = strtolower(rtrim($record['value'], '.'));

            if (!in_array($wanted, $published, true)) {
                $missing[] = $record['name'];
            }
        }

        if ($missing === []) {
            $findings[] = [
                'record'  => 'DKIM',
                'status'  => 'verified',
                'message' => 'All ' . count($cnames) . ' DKIM records are published correctly.',
            ];

            return 'verified';
        }

        $findings[] = [
            'record'  => 'DKIM',
            'status'  => 'failed',
            'message' => count($missing) === count($cnames)
                ? 'None of the DKIM records are published yet. Add all ' . count($cnames) . ' CNAME records below.'
                : count($missing) . ' of ' . count($cnames) . ' DKIM records are missing or wrong: '
                    . implode(', ', $missing),
        ];

        return 'failed';
    }

    /**
     * SPF: exactly one record, and it must authorise the provider.
     *
     * Two SPF records is a hard failure in the spec — receivers treat it as
     * permerror — and it is a mistake people make constantly by adding a second
     * one instead of merging.
     *
     * @param array<int,array{record:string,status:string,message:string}> $findings
     */
    private function checkSpf(string $domain, array &$findings): string
    {
        $records = array_values(array_filter(
            $this->dns->txt($domain),
            static fn (string $value): bool => stripos(trim($value), 'v=spf1') === 0
        ));

        if ($records === []) {
            $findings[] = [
                'record'  => 'SPF',
                'status'  => 'failed',
                'message' => 'No SPF record found. Publish a TXT record on ' . $domain
                    . ' that includes ' . self::SES_SPF_INCLUDE . '.',
            ];

            return 'failed';
        }

        if (count($records) > 1) {
            $findings[] = [
                'record'  => 'SPF',
                'status'  => 'failed',
                'message' => 'This domain has ' . count($records) . ' SPF records. Only one is allowed — '
                    . 'receivers reject multiple records outright. Merge them into a single TXT record.',
            ];

            return 'failed';
        }

        $record = $records[0];

        if (stripos($record, self::SES_SPF_INCLUDE) === false) {
            $findings[] = [
                'record'  => 'SPF',
                'status'  => 'failed',
                'message' => 'Your SPF record does not authorise your email provider. '
                    . 'Add "include:' . self::SES_SPF_INCLUDE . '" to the existing record — do not add a second one.',
            ];

            return 'failed';
        }

        // A strict -all is good practice but not ours to insist on: tightening it
        // can break a customer's other mail.
        $mechanism = str_contains($record, '-all') ? 'strict (-all)'
            : (str_contains($record, '~all') ? 'soft fail (~all)' : 'permissive');

        $findings[] = [
            'record'  => 'SPF',
            'status'  => 'verified',
            'message' => 'SPF authorises your email provider. Policy: ' . $mechanism . '.',
        ];

        return 'verified';
    }

    /**
     * DMARC: reported, never blocking.
     *
     * @param array<int,array{record:string,status:string,message:string}> $findings
     */
    private function checkDmarc(string $domain, array &$findings): string
    {
        $records = array_values(array_filter(
            $this->dns->txt('_dmarc.' . $domain),
            static fn (string $value): bool => stripos(trim($value), 'v=dmarc1') === 0
        ));

        if ($records === []) {
            $findings[] = [
                'record'  => 'DMARC',
                'status'  => 'pending',
                'message' => 'No DMARC record. Not required to send, but strongly recommended — '
                    . 'start with p=none to collect reports, then tighten once you know what sends as you.',
            ];

            return 'pending';
        }

        $policy = 'none';

        if (preg_match('/\bp\s*=\s*(none|quarantine|reject)\b/i', $records[0], $matches) === 1) {
            $policy = strtolower($matches[1]);
        }

        $findings[] = [
            'record'  => 'DMARC',
            'status'  => 'verified',
            'message' => 'DMARC is published with policy p=' . $policy . '.'
                . ($policy === 'none' ? ' Consider moving to quarantine once your reports look clean.' : ''),
        ];

        return 'verified';
    }

    /**
     * Step 8: send a test email to prove the whole path works end to end.
     */
    public function sendTestEmail(int $id, string $recipient, TransactionalMailer $mailer): bool
    {
        $domain = $this->domains->findOrFailWithRecords($id);

        if ((string) $domain['status'] !== 'verified') {
            throw new ValidationException([
                'domain' => ['Verify the domain before sending a test.'],
            ]);
        }

        if (!is_valid_email($recipient)) {
            throw new ValidationException(['recipient' => ['Enter a valid email address.']]);
        }

        $organisation = $this->tenant->organisation();

        $html = '<p>This is a test message from ' . e((string) ($organisation['name'] ?? '')) . '.</p>'
            . '<p>If you received it, <strong>' . e((string) $domain['domain']) . '</strong> is set up correctly '
            . 'and your campaigns will authenticate.</p>';

        $sent = $mailer->sendToContact(
            $organisation,
            ['id' => null, 'email' => $recipient],
            'Test message from ' . (string) ($organisation['name'] ?? 'your account'),
            $html,
            "This is a test message. If you received it, {$domain['domain']} is set up correctly.\n"
        );

        $this->audit->log('domain_test_email_sent', 'sending_domain', $id, null, [
            'domain'    => $domain['domain'],
            'recipient' => \App\Support\Str::maskEmail($recipient),
            'accepted'  => $sent,
        ]);

        return $sent;
    }

    /**
     * Re-check domains in the background so a customer who publishes their
     * records overnight is verified by morning without pressing anything.
     *
     * @return array<int,array{domain:string,status:string}>
     */
    public function recheckPending(int $limit = 25): array
    {
        $before  = $this->clock->now()->modify('-15 minutes')->format('Y-m-d H:i:s');
        $results = [];

        foreach ($this->domains->dueForRecheck($before, $limit) as $row) {
            $organisationId = (int) $row['organisation_id'];

            // The scheduler crosses tenants; bind each one explicitly.
            $wasBound = $this->tenant->isBound();

            if (!$wasBound || $this->tenant->organisationId() !== $organisationId) {
                $this->tenant->clear();
                $this->tenant->bind($organisationId, null, ['id' => $organisationId]);
            }

            try {
                $outcome   = $this->verify((int) $row['id']);
                $results[] = ['domain' => (string) $row['domain'], 'status' => $outcome['status']];
            } catch (\Throwable) {
                // One unreachable zone must not stop the rest of the sweep.
                continue;
            }
        }

        $this->tenant->clear();

        return $results;
    }

    private function domainLimit(): int
    {
        $plan = (string) $this->config->get('plans.default', 'starter');

        return (int) $this->config->get('plans.plans.' . $plan . '.limits.sending_domains', 1);
    }

    /**
     * Accept whatever the customer pastes — a URL, an address, a trailing slash —
     * and reduce it to a registrable domain.
     */
    public function normalise(string $input): string
    {
        $value = strtolower(trim($input));

        if (str_contains($value, '@')) {
            $value = substr($value, strrpos($value, '@') + 1);
        }

        $value = (string) preg_replace('#^[a-z]+://#', '', $value);
        $value = explode('/', $value)[0];
        $value = trim($value, '.');

        if (preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $value) !== 1) {
            throw new ValidationException([
                'domain' => ['That does not look like a domain name. Enter something like example.com.'],
            ]);
        }

        return $value;
    }

    /** @param array<int,array{record:string,status:string,message:string}> $findings */
    private function firstFailure(array $findings): ?string
    {
        foreach ($findings as $finding) {
            if ($finding['status'] === 'failed') {
                return substr($finding['message'], 0, 255);
            }
        }

        return null;
    }
}
