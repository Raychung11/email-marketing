<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Services\SegmentCompiler;
use App\Services\SegmentService;
use Tests\Support\TestCase;

/**
 * The segmentation engine: nested AND/OR, relative dates, relations, and the
 * eligibility breakdown that stops a preview from flattering a campaign.
 */
final class SegmentCompilerTest extends TestCase
{
    public function testNestedAndOrLogicMatchesTheIntendedContacts(): void
    {
        $org = $this->createOrganisation(['country' => 'AU', 'currency' => 'AUD']);
        $this->bindTenant($org['organisation_id']);

        // (Country = AU AND last purchase > 180 days ago) AND (VIP OR value > 1000)
        $matches = $this->createContact([
            'email' => 'match@example.com.au', 'country' => 'AU', 'customer_status' => 'vip',
        ]);
        $this->setMoney($matches, 200, '2025-01-01 00:00:00');

        $alsoMatches = $this->createContact([
            'email' => 'match2@example.com.au', 'country' => 'AU', 'customer_status' => 'customer',
        ]);
        $this->setMoney($alsoMatches, 2500, '2025-01-01 00:00:00');

        // Right country and recency, but neither VIP nor high value.
        $wrongInnerGroup = $this->createContact([
            'email' => 'no-match@example.com.au', 'country' => 'AU', 'customer_status' => 'customer',
        ]);
        $this->setMoney($wrongInnerGroup, 100, '2025-01-01 00:00:00');

        // VIP and high value, but purchased last week.
        $tooRecent = $this->createContact([
            'email' => 'recent@example.com.au', 'country' => 'AU', 'customer_status' => 'vip',
        ]);
        $this->setMoney($tooRecent, 5000, '2026-06-10 00:00:00');

        // Everything right except the country.
        $wrongCountry = $this->createContact([
            'email' => 'us@example.com', 'country' => 'US', 'customer_status' => 'vip',
        ]);
        $this->setMoney($wrongCountry, 5000, '2025-01-01 00:00:00');

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $definition = [
            'match' => 'all',
            'rules' => [
                [
                    'match' => 'all',
                    'rules' => [
                        ['field' => 'country', 'operator' => 'equals', 'value' => 'AU'],
                        ['field' => 'last_purchase', 'operator' => 'before', 'value' => 'now-180days'],
                    ],
                ],
                [
                    'match' => 'any',
                    'rules' => [
                        ['field' => 'customer_status', 'operator' => 'equals', 'value' => 'vip'],
                        ['field' => 'customer_value', 'operator' => 'greater_than', 'value' => 1000],
                    ],
                ],
            ],
        ];

        $rows   = $segments->query($segments->validate($definition))->get();
        $emails = array_column($rows, 'email');

        sort($emails);

        $this->assertSame(['match2@example.com.au', 'match@example.com.au'], $emails);
    }

    public function testRelativeDatesAreEvaluatedAtQueryTimeNotSaveTime(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var SegmentCompiler $compiler */
        $compiler = $this->container->make(SegmentCompiler::class);

        // The clock is frozen at 2026-06-15 12:00:00.
        $this->assertSame('2025-12-17 12:00:00', $compiler->resolveDate('now-180days'));
        $this->assertSame('2025-06-15 12:00:00', $compiler->resolveDate('now-1year'));
        $this->assertSame('2026-06-15 12:00:00', $compiler->resolveDate('now'));

        // An absolute date still works.
        $this->assertSame('2026-01-31 00:00:00', $compiler->resolveDate('2026-01-31'));
    }

    public function testASavedSegmentKeepsMeaningInactiveForSixMonths(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $contactId = $this->createContact(['email' => 'drifting@example.com']);
        $this->setMoney($contactId, 500, '2026-05-01 00:00:00');

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $definition = [
            'match' => 'all',
            'rules' => [['field' => 'last_purchase', 'operator' => 'before', 'value' => 'now-180days']],
        ];

        // Today the purchase is recent, so nobody matches.
        $this->assertSame(0, $segments->preview($definition)['total']);

        // A year later the same stored definition matches — because the expression
        // is relative, not an absolute date baked in at save time.
        $this->clock->travel('+1 year');

        $this->assertSame(1, $segments->preview($definition)['total']);
    }

    public function testTagRelationsUseExistsAndDoNotDuplicateRows(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var \App\Repositories\TagRepository $tags */
        $tags = $this->container->make(\App\Repositories\TagRepository::class);

        $vip = $tags->firstOrCreate('VIP');
        $hot = $tags->firstOrCreate('Hot Lead');

        $contactId = $this->createContact(['email' => 'tagged@example.com']);
        $tags->attach($contactId, $vip);
        $tags->attach($contactId, $hot);

        $this->createContact(['email' => 'untagged@example.com']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $rows = $segments->query([
            'match' => 'all',
            'rules' => [['field' => 'tag', 'operator' => 'in', 'value' => [$vip, $hot]]],
        ])->get();

        // A join would return this contact twice and inflate every count built on
        // it; an EXISTS subquery returns one row.
        $this->assertCount(1, $rows, 'A contact with two matching tags appears once');
    }

    public function testNotInOnARelationExcludesTaggedContacts(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var \App\Repositories\TagRepository $tags */
        $tags = $this->container->make(\App\Repositories\TagRepository::class);
        $vip  = $tags->firstOrCreate('VIP');

        $tagged = $this->createContact(['email' => 'vip@example.com']);
        $tags->attach($tagged, $vip);

        $this->createContact(['email' => 'ordinary@example.com']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $rows = $segments->query([
            'match' => 'all',
            'rules' => [['field' => 'tag', 'operator' => 'not_in', 'value' => [$vip]]],
        ])->get();

        $this->assertCount(1, $rows);
        $this->assertSame('ordinary@example.com', (string) $rows[0]['email']);
    }

    public function testAnEmptyRelationValueMatchesNobodyRatherThanEveryone(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $this->createContact();
        $this->createContact();

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        // "in an empty set" must be false, not vacuously true — a mistake here
        // would silently mail the whole database.
        $rows = $segments->query([
            'match' => 'all',
            'rules' => [['field' => 'tag', 'operator' => 'in', 'value' => []]],
        ])->get();

        $this->assertCount(0, $rows);
    }

    public function testConsentAndSuppressionRulesReadTheAuthoritativeTables(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $consented = $this->createContact(['email' => 'yes@example.com'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);
        $this->createContact(['email' => 'no@example.com']);

        $suppressed = $this->createContact(['email' => 'bounced@example.com'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var \App\Services\SuppressionService $suppressions */
        $suppressions = $this->container->make(\App\Services\SuppressionService::class);
        $suppressions->suppressForHardBounce('bounced@example.com', 'ses');

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $withConsent = $segments->query([
            'match' => 'all',
            'rules' => [['field' => 'marketing_consent', 'operator' => 'equals', 'value' => true]],
        ])->get();

        $emails = array_column($withConsent, 'email');
        sort($emails);

        $this->assertSame(['bounced@example.com', 'yes@example.com'], $emails, 'Consent and suppression are separate facts');

        $notSuppressed = $segments->query([
            'match' => 'all',
            'rules' => [['field' => 'suppressed', 'operator' => 'equals', 'value' => false]],
        ])->get();

        $notSuppressedEmails = array_column($notSuppressed, 'email');
        sort($notSuppressedEmails);

        $this->assertSame(['no@example.com', 'yes@example.com'], $notSuppressedEmails);
    }

    public function testTypedCustomFieldsCompareNumericallyNotLexicographically(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var \App\Repositories\CustomFieldRepository $customFields */
        $customFields = $this->container->make(\App\Repositories\CustomFieldRepository::class);

        $definitionId = $customFields->createDefinition([
            'entity_type' => 'contact',
            'key'         => 'roof_area',
            'label'       => 'Roof area (m²)',
            'type'        => 'number',
        ]);

        // 9 vs 100: a string comparison would put "9" above "100".
        $small = $this->createContact(['email' => 'small@example.com']);
        $large = $this->createContact(['email' => 'large@example.com']);

        $customFields->setValue($small, $definitionId, 'number', 9);
        $customFields->setValue($large, $definitionId, 'number', 100);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $rows = $segments->query([
            'match' => 'all',
            'rules' => [[
                'field'            => 'custom_field',
                'operator'         => 'greater_than',
                'value'            => 50,
                'custom_field_key' => 'roof_area',
            ]],
        ])->get();

        $this->assertCount(1, $rows);
        $this->assertSame('large@example.com', (string) $rows[0]['email'], '100 is greater than 50; "9" is not');
    }

    public function testBetweenAndExistsOperators(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $a = $this->createContact(['email' => 'a@example.com']);
        $b = $this->createContact(['email' => 'b@example.com']);
        $c = $this->createContact(['email' => 'c@example.com', 'city' => 'Perth']);

        $this->setMoney($a, 100, null);
        $this->setMoney($b, 600, null);
        $this->setMoney($c, 1200, null);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $between = $segments->query([
            'match' => 'all',
            'rules' => [[
                'field' => 'customer_value', 'operator' => 'between', 'value' => 500, 'value2' => 1000,
            ]],
        ])->get();

        $this->assertCount(1, $between);
        $this->assertSame('b@example.com', (string) $between[0]['email']);

        $hasCity = $segments->query([
            'match' => 'all',
            'rules' => [['field' => 'city', 'operator' => 'exists', 'value' => null]],
        ])->get();

        $this->assertCount(1, $hasCity);
        $this->assertSame('c@example.com', (string) $hasCity[0]['email']);
    }

    public function testPreviewSeparatesMatchingFromContactable(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $this->bindTenant($org['organisation_id']);

        // 1 eligible, 1 suppressed, 1 without a consent basis, 1 invalid address.
        $this->createContact(['email' => 'ok@example.com.au', 'country' => 'AU'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        $this->createContact(['email' => 'bounced@example.com.au', 'country' => 'AU'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        $this->createContact(['email' => 'unknown@example.com.au', 'country' => 'AU']);

        /** @var \App\Services\SuppressionService $suppressions */
        $suppressions = $this->container->make(\App\Services\SuppressionService::class);
        $suppressions->suppressForHardBounce('bounced@example.com.au', 'ses');

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $preview = $segments->preview([
            'match' => 'all',
            'rules' => [['field' => 'country', 'operator' => 'equals', 'value' => 'AU']],
        ]);

        $this->assertSame(3, $preview['total']);
        $this->assertSame(1, $preview['eligible'], 'Only one can actually be emailed');
        $this->assertSame(1, $preview['suppressed']);
        $this->assertSame(1, $preview['no_consent']);
    }

    public function testAnEmptySegmentIsRejected(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        // A segment with no conditions would match everyone, which is never what
        // somebody means to build.
        $this->assertThrows(
            ValidationException::class,
            static fn () => $segments->validate(['match' => 'all', 'rules' => []])
        );
    }

    public function testExcessiveNestingIsRejected(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        // Build a tree deeper than the configured maximum.
        $node = ['field' => 'country', 'operator' => 'equals', 'value' => 'AU'];

        for ($i = 0; $i < 8; $i++) {
            $node = ['match' => 'all', 'rules' => [$node]];
        }

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $this->assertThrows(ValidationException::class, static fn () => $segments->validate($node));
    }

    public function testSavedSegmentsMirrorTheirRulesForReporting(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $segmentId = $segments->create('High value Australians', [
            'match' => 'all',
            'rules' => [
                ['field' => 'country', 'operator' => 'equals', 'value' => 'AU'],
                ['field' => 'customer_value', 'operator' => 'greater_than', 'value' => 1000],
            ],
        ]);

        $rules = $this->connection->select(
            'SELECT node_type, field_key, operator, value FROM segment_rules WHERE segment_id = ? ORDER BY id',
            [$segmentId]
        );

        // The JSON definition is authoritative for evaluation; these rows exist so
        // the builder, reporting and a DBA can all read a segment without parsing
        // JSON.
        $this->assertSame('group', (string) $rules[0]['node_type']);
        $this->assertSame('country', (string) $rules[1]['field_key']);
        $this->assertSame('customer_value', (string) $rules[2]['field_key']);
        $this->assertSame('1000', (string) $rules[2]['value']);
    }

    public function testDescribeProducesReadableText(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $summary = $segments->describe([
            'match' => 'all',
            'rules' => [
                ['field' => 'country', 'operator' => 'equals', 'value' => 'AU'],
                ['field' => 'last_purchase', 'operator' => 'before', 'value' => 'now-180days'],
            ],
        ]);

        $this->assertContainsString('Country equals AU', $summary);
        $this->assertContainsString('AND', $summary);
        $this->assertContainsString('Last purchase before now-180days', $summary);
    }

    private function setMoney(int $contactId, float $value, ?string $lastPurchase): void
    {
        $this->connection->execute(
            'UPDATE contacts SET customer_value = ?, total_revenue = ?, purchase_count = 1, last_purchase_at = ?
             WHERE id = ?',
            [$value, $value, $lastPurchase, $contactId]
        );
    }
}
