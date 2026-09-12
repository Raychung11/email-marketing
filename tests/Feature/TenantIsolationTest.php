<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\HttpException;
use App\Repositories\ContactRepository;
use App\Repositories\SuppressionRepository;
use App\Repositories\TagRepository;
use App\Services\ContactService;
use App\Services\SegmentService;
use Tests\Support\TestCase;

/**
 * Multi-tenancy is the invariant everything else rests on: if two customers' data
 * can meet, nothing else about the product matters.
 */
final class TenantIsolationTest extends TestCase
{
    public function testContactsAreInvisibleAcrossOrganisations(): void
    {
        $orgA = $this->createOrganisation(['name' => 'Alpha Plumbing']);
        $orgB = $this->createOrganisation(['name' => 'Beta Dental']);

        $this->bindTenant($orgA['organisation_id']);
        $contactId = $this->createContact(['email' => 'shared@example.test']);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);

        $this->assertNotNull($contacts->find($contactId), 'The owning organisation can read its own contact');

        // Switch tenant: the same primary key must simply not exist.
        $this->bindTenant($orgB['organisation_id']);

        $this->assertNull(
            $contacts->find($contactId),
            'A contact belonging to another organisation must not be readable'
        );
    }

    public function testFindOrFailRaises404RatherThanLeakingExistence(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $this->bindTenant($orgA['organisation_id']);
        $contactId = $this->createContact();

        $this->bindTenant($orgB['organisation_id']);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);

        $exception = $this->assertThrows(
            HttpException::class,
            static fn () => $contacts->findOrFail($contactId)
        );

        // 404, not 403: a 403 would confirm the record exists somewhere, which is
        // an enumeration oracle.
        $this->assertSame(404, $exception->statusCode(), 'Cross-tenant reads must be indistinguishable from missing');
    }

    public function testTheSameEmailCanExistInTwoOrganisations(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $this->bindTenant($orgA['organisation_id']);
        $a = $this->createContact(['email' => 'customer@example.test']);

        $this->bindTenant($orgB['organisation_id']);
        $b = $this->createContact(['email' => 'customer@example.test']);

        $this->assertTrue($a !== $b, 'Deduplication is per organisation, not global');

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);
        $found    = $contacts->findByEmail('customer@example.test');

        $this->assertSame($b, (int) $found['id'], 'Lookup resolves within the bound tenant only');
    }

    public function testSuppressionDoesNotLeakBetweenOrganisations(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $this->bindTenant($orgA['organisation_id']);

        /** @var SuppressionRepository $suppressions */
        $suppressions = $this->container->make(SuppressionRepository::class);
        $suppressions->suppress('opted.out@example.test', 'unsubscribe');

        $this->assertTrue($suppressions->isSuppressed('opted.out@example.test'));

        $this->bindTenant($orgB['organisation_id']);

        // One organisation's unsubscribe is not another's: the recipient opted out
        // of *that* sender, and applying it globally would be wrong in both
        // directions.
        $this->assertFalse(
            $suppressions->isSuppressed('opted.out@example.test'),
            'Suppression is scoped to the organisation that earned it'
        );
    }

    public function testTagsAndCountsStayWithinTheOrganisation(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $this->bindTenant($orgA['organisation_id']);

        /** @var TagRepository $tags */
        $tags  = $this->container->make(TagRepository::class);
        $tagId = $tags->create('VIP Customers');

        $this->bindTenant($orgB['organisation_id']);

        $this->assertNull($tags->find($tagId), 'A tag id from another organisation resolves to nothing');
        $this->assertCount(5, $tags->all(), 'Organisation B sees only its own seeded starter tags');
    }

    public function testSegmentEvaluationOnlyMatchesOwnContacts(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $this->bindTenant($orgA['organisation_id']);
        $this->createContact(['email' => 'a1@example.test', 'country' => 'AU']);
        $this->createContact(['email' => 'a2@example.test', 'country' => 'AU']);

        $this->bindTenant($orgB['organisation_id']);
        $this->createContact(['email' => 'b1@example.test', 'country' => 'AU']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $definition = [
            'match' => 'all',
            'rules' => [['field' => 'country', 'operator' => 'equals', 'value' => 'AU']],
        ];

        $this->assertSame(1, $segments->preview($definition)['total'], 'Organisation B matches only its own contact');

        $this->bindTenant($orgA['organisation_id']);

        $this->assertSame(2, $segments->preview($definition)['total'], 'Organisation A matches only its own contacts');
    }

    public function testUnboundTenantCannotQuery(): void
    {
        $this->createOrganisation();
        $this->tenant->clear();

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);

        // Failing loudly beats silently returning every tenant's rows.
        $this->assertThrows(
            \RuntimeException::class,
            static fn () => $contacts->find(1),
            'A tenant-scoped query with no bound organisation must fail'
        );
    }

    public function testSwitchingToAnOrganisationYouDoNotBelongToIsRejected(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        // Sign in as A's owner, then try to switch into B.
        $this->actingAs($orgA['user_id'], $orgA['organisation_id']);

        /** @var \App\Services\OrganisationService $organisations */
        $organisations = $this->container->make(\App\Services\OrganisationService::class);

        $exception = $this->assertThrows(
            HttpException::class,
            static fn () => $organisations->switchTo($orgB['organisation_id'])
        );

        $this->assertSame(404, $exception->statusCode(), 'Switching without membership must not succeed');
    }
}
