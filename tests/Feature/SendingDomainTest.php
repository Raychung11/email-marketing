<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\ValidationException;
use App\Repositories\SendingDomainRepository;
use App\Services\SendingDomainService;
use App\Support\DnsResolver;
use App\Support\FakeDnsResolver;
use Tests\Support\TestCase;

/**
 * §31 — domain verification.
 *
 * DKIM decides whether a domain may send. SPF is checked for the two mistakes
 * that actually break mail (missing, or duplicated). DMARC is reported and never
 * blocks, because publishing a strict policy prematurely breaks a business's
 * other mail and that is not our call to make.
 */
final class SendingDomainTest extends TestCase
{
    private FakeDnsResolver $dns;

    public function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsResolver();
        $this->container->instance(DnsResolver::class, $this->dns);
    }

    public function testAddingADomainReturnsTheRecordsToPublish(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('perthplumbing.com.au');

        $domain = $this->repository()->findOrFailWithRecords($id);

        $this->assertSame('pending', (string) $domain['status']);

        $types = array_count_values(array_column($domain['dns_records'], 'type'));

        $this->assertSame(3, $types['CNAME'] ?? 0, 'Three DKIM records');
        $this->assertSame(2, $types['TXT'] ?? 0, 'Plus SPF and DMARC');
    }

    public function testMessyInputIsReducedToADomain(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $service = $this->service();

        // People paste whatever is in front of them.
        $this->assertSame('perthplumbing.com.au', $service->normalise('https://perthplumbing.com.au/contact'));
        $this->assertSame('perthplumbing.com.au', $service->normalise('hello@perthplumbing.com.au'));
        $this->assertSame('perthplumbing.com.au', $service->normalise('  PerthPlumbing.COM.AU/  '));
    }

    public function testRubbishIsRejected(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $service = $this->service();

        foreach (['not a domain', 'localhost', 'http://', '...', 'example'] as $input) {
            $this->assertThrows(
                ValidationException::class,
                static fn () => $service->normalise($input),
                'Rejects: ' . $input
            );
        }
    }

    public function testTheSameDomainCannotBeAddedTwice(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $service = $this->service();
        $service->add('example.com');

        $this->assertThrows(ValidationException::class, static fn () => $service->add('EXAMPLE.COM'));
    }

    public function testAFullyPublishedZoneVerifies(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('example.com');
        $this->publishAllRecords($id);

        $result = $this->service()->verify($id);

        $this->assertSame('verified', $result['status']);
        $this->assertSame('verified', $result['dkim']);
        $this->assertSame('verified', $result['spf']);
        $this->assertSame('verified', $result['dmarc']);

        $domain = $this->repository()->findOrFailWithRecords($id);
        $this->assertNotNull($domain['verified_at']);
        $this->assertNull($domain['last_error']);
    }

    public function testPartialDkimPublicationNamesTheMissingRecords(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id     = $this->service()->add('example.com');
        $domain = $this->repository()->findOrFailWithRecords($id);

        // Publish only the first of the three DKIM records — the classic mistake.
        $cnames = array_values(array_filter(
            $domain['dns_records'],
            static fn (array $r): bool => $r['type'] === 'CNAME'
        ));

        $this->dns->setCname($cnames[0]['name'], $cnames[0]['value']);

        $result = $this->service()->verify($id);

        $this->assertSame('failed', $result['dkim']);
        $this->assertSame('failed', $result['status'], 'A domain with incomplete DKIM cannot send');

        $dkimFinding = $this->finding($result['findings'], 'DKIM');

        $this->assertContainsString('2 of 3', $dkimFinding, 'The message says how many are missing');
        $this->assertContainsString($cnames[1]['name'], $dkimFinding, 'And names them');
    }

    public function testNoDkimAtAllSaysSoPlainly(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id     = $this->service()->add('example.com');
        $result = $this->service()->verify($id);

        $this->assertSame('failed', $result['dkim']);
        $this->assertContainsString('None of the DKIM records', $this->finding($result['findings'], 'DKIM'));
    }

    public function testTwoSpfRecordsAreAHardFailure(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('example.com');
        $this->publishDkim($id);

        // Adding a second SPF record instead of merging is a permerror under the
        // spec, and it is the single most common SPF mistake.
        $this->dns->setTxt('example.com', 'v=spf1 include:amazonses.com ~all', 'v=spf1 include:_spf.google.com ~all');

        $result = $this->service()->verify($id);

        $this->assertSame('failed', $result['spf']);
        $this->assertContainsString('2 SPF records', $this->finding($result['findings'], 'SPF'));
        $this->assertContainsString('Merge them', $this->finding($result['findings'], 'SPF'));

        // But DKIM is fine, so the domain can still send — SPF is not the gate.
        $this->assertSame('verified', $result['dkim']);
        $this->assertSame('verified', $result['status']);
    }

    public function testAnSpfRecordThatDoesNotAuthoriseTheProviderFails(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('example.com');
        $this->publishDkim($id);

        $this->dns->setTxt('example.com', 'v=spf1 include:_spf.google.com -all');

        $result = $this->service()->verify($id);

        $this->assertSame('failed', $result['spf']);
        $this->assertContainsString('does not authorise', $this->finding($result['findings'], 'SPF'));
        $this->assertContainsString('do not add a second one', $this->finding($result['findings'], 'SPF'));
    }

    public function testSpfPolicyStrengthIsReportedNotEnforced(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('example.com');
        $this->publishDkim($id);

        // A permissive +all is poor practice but tightening it is the customer's
        // decision — their other mail may depend on it.
        $this->dns->setTxt('example.com', 'v=spf1 include:amazonses.com ?all');

        $result = $this->service()->verify($id);

        $this->assertSame('verified', $result['spf']);
        $this->assertContainsString('Policy: permissive', $this->finding($result['findings'], 'SPF'));
    }

    public function testAMissingDmarcNeverBlocks(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('example.com');
        $this->publishDkim($id);
        $this->dns->setTxt('example.com', 'v=spf1 include:amazonses.com ~all');

        $result = $this->service()->verify($id);

        $this->assertSame('pending', $result['dmarc']);
        $this->assertSame('verified', $result['status'], 'DMARC is advisory, never a gate');
        $this->assertContainsString('strongly recommended', $this->finding($result['findings'], 'DMARC'));
    }

    public function testDmarcPolicyIsReported(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('example.com');
        $this->publishAllRecords($id);
        $this->dns->setTxt('_dmarc.example.com', 'v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com');

        $result = $this->service()->verify($id);

        $this->assertContainsString('p=quarantine', $this->finding($result['findings'], 'DMARC'));
    }

    public function testOnlyAVerifiedDomainCanBeSentFrom(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('example.com');

        $repository = $this->repository();

        $this->assertFalse($repository->canSendFrom('hello@example.com'), 'Pending is not good enough');

        $this->publishAllRecords($id);
        $this->service()->verify($id);

        $this->assertTrue($repository->canSendFrom('hello@example.com'));
        $this->assertFalse($repository->canSendFrom('hello@somewhere-else.com'), 'A different domain is not covered');
        $this->assertFalse($repository->canSendFrom('not-an-address'));
    }

    public function testATestEmailRequiresAVerifiedDomain(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('example.com');

        $mailer  = $this->container->make(\App\Services\TransactionalMailer::class);
        $service = $this->service();

        $this->assertThrows(
            ValidationException::class,
            static fn () => $service->sendTestEmail($id, 'me@example.com', $mailer)
        );

        $this->publishAllRecords($id);
        $service->verify($id);

        $this->assertTrue($service->sendTestEmail($id, 'me@example.com', $mailer));
    }

    public function testVerificationOutcomesAreAudited(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('example.com');
        $this->service()->verify($id);           // fails: nothing published
        $this->publishAllRecords($id);
        $this->service()->verify($id);           // passes

        $actions = array_column(
            $this->connection->select(
                "SELECT action FROM audit_logs WHERE entity_type = 'sending_domain' ORDER BY id"
            ),
            'action'
        );

        $this->assertTrue(in_array('domain_added', $actions, true));
        $this->assertTrue(in_array('domain_verification_failed', $actions, true));
        $this->assertTrue(in_array('domain_verified', $actions, true));
    }

    public function testDomainsAreScopedToTheirOrganisation(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $this->bindTenant($orgA['organisation_id']);
        $id = $this->service()->add('shared-name.com');

        $this->bindTenant($orgB['organisation_id']);

        $this->assertNull($this->repository()->find($id), 'Another organisation cannot see it');
        $this->assertFalse($this->repository()->canSendFrom('hello@shared-name.com'));

        // And the same domain name can legitimately be added by both — two
        // businesses can share a parent domain.
        $this->service()->add('shared-name.com');
    }

    public function testTheSchedulerRechecksPendingDomains(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $id = $this->service()->add('example.com');
        $this->service()->verify($id);

        $this->assertSame('failed', (string) $this->repository()->findOrFail($id)['status']);

        // The customer publishes their records overnight.
        $this->publishAllRecords($id);
        $this->clock->travel('+30 minutes');

        $results = $this->service()->recheckPending();

        $this->assertCount(1, $results);
        $this->assertSame('verified', $results[0]['status'], 'They wake up verified without pressing anything');
    }

    // ---------------------------------------------------------------- helpers

    private function service(): SendingDomainService
    {
        return $this->container->make(SendingDomainService::class);
    }

    private function repository(): SendingDomainRepository
    {
        return $this->container->make(SendingDomainRepository::class);
    }

    private function publishDkim(int $id): void
    {
        $domain = $this->repository()->findOrFailWithRecords($id);

        foreach ($domain['dns_records'] as $record) {
            if ($record['type'] === 'CNAME') {
                $this->dns->setCname($record['name'], $record['value']);
            }
        }
    }

    private function publishAllRecords(int $id): void
    {
        $this->publishDkim($id);

        $domain = $this->repository()->findOrFailWithRecords($id);

        foreach ($domain['dns_records'] as $record) {
            if ($record['type'] === 'TXT') {
                $this->dns->setTxt($record['name'], $record['value']);
            }
        }
    }

    /** @param array<int,array{record:string,status:string,message:string}> $findings */
    private function finding(array $findings, string $record): string
    {
        foreach ($findings as $finding) {
            if ($finding['record'] === $record) {
                return $finding['message'];
            }
        }

        return '';
    }
}
