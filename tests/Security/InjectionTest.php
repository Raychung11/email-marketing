<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\ValidationException;
use App\Repositories\ContactRepository;
use App\Services\SegmentService;
use InvalidArgumentException;
use Tests\Support\TestCase;

/**
 * SQL injection.
 *
 * Two lines of defence are tested here: values are always bound, and identifiers
 * (table, column, operator) are whitelisted rather than escaped. The segment
 * engine is the interesting case because it accepts a rule tree from a browser —
 * and, later, from the AI layer.
 */
final class InjectionTest extends TestCase
{
    public function testSearchTermsWithSqlMetacharactersAreTreatedAsData(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $this->createContact(['email' => 'real@example.com', 'first_name' => 'Real']);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);

        foreach ([
            "' OR '1'='1",
            "'; DROP TABLE contacts; --",
            "\\' UNION SELECT * FROM users --",
            "%' OR 1=1 --",
        ] as $payload) {
            $results = $contacts->filtered(['search' => $payload])->get();

            $this->assertCount(0, $results, 'An injection payload matches nothing: ' . $payload);
        }

        // And the table is still there.
        $this->assertCount(1, $contacts->filtered([])->get(), 'The contacts table survived');
    }

    public function testLikeWildcardsInSearchAreEscaped(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $this->createContact(['email' => 'alice@example.com', 'first_name' => 'Alice']);
        $this->createContact(['email' => 'bob@example.com', 'first_name' => 'Bob']);

        /** @var ContactRepository $contacts */
        $contacts = $this->container->make(ContactRepository::class);

        // A literal % must not behave as "match everything".
        $this->assertCount(0, $contacts->filtered(['search' => '%'])->get());
        $this->assertCount(0, $contacts->filtered(['search' => '_'])->get());
    }

    public function testSegmentRejectsAnUnknownField(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        // The field name is the attack surface: it would become a column name.
        foreach ([
            'contacts.password_hash',
            '(SELECT password_hash FROM users)',
            'email; DROP TABLE contacts',
            'unknown_field',
        ] as $field) {
            $this->assertThrows(
                ValidationException::class,
                static fn () => $segments->validate([
                    'match' => 'all',
                    'rules' => [['field' => $field, 'operator' => 'equals', 'value' => 'x']],
                ]),
                'The field registry must reject: ' . $field
            );
        }
    }

    public function testSegmentRejectsAnUnknownOperator(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        foreach (['= 1 OR 1', 'IS NOT NULL; DELETE FROM contacts', 'REGEXP', 'nonsense'] as $operator) {
            $this->assertThrows(
                ValidationException::class,
                static fn () => $segments->validate([
                    'match' => 'all',
                    'rules' => [['field' => 'email', 'operator' => $operator, 'value' => 'x']],
                ])
            );
        }
    }

    public function testSegmentRejectsAnOperatorThatDoesNotSuitTheFieldType(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        // "contains" on a money column is meaningless and is refused, rather than
        // silently producing a LIKE against a DECIMAL.
        $this->assertThrows(
            ValidationException::class,
            static fn () => $segments->validate([
                'match' => 'all',
                'rules' => [['field' => 'customer_value', 'operator' => 'contains', 'value' => '1']],
            ])
        );
    }

    public function testSegmentValuesWithSqlPayloadsAreBoundNotInterpolated(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $this->createContact(['email' => 'victim@example.com', 'city' => 'Perth']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $preview = $segments->preview([
            'match' => 'all',
            'rules' => [[
                'field'    => 'city',
                'operator' => 'equals',
                'value'    => "Perth' OR '1'='1",
            ]],
        ]);

        $this->assertSame(0, $preview['total'], 'The payload is compared as a literal string');

        // The contact is still there and still findable honestly.
        $honest = $segments->preview([
            'match' => 'all',
            'rules' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Perth']],
        ]);

        $this->assertSame(1, $honest['total']);
    }

    public function testCompilingAStoredSegmentWithAnInvalidFieldFailsClosed(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        $this->createContact(['email' => 'someone@example.com']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        // Simulates a definition stored before a config change removed a field.
        // Widening the audience by silently dropping the condition would be much
        // worse than an error, so the compiler refuses.
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => $segments->query([
                'match' => 'all',
                'rules' => [['field' => 'retired_field', 'operator' => 'equals', 'value' => 'x']],
            ])->get()
        );
    }

    public function testQueryBuilderRefusesAnUnfilteredUpdateOrDelete(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        // In a multi-tenant schema a WHERE-less write is always a bug, so the
        // builder refuses rather than trusting the caller.
        $this->assertThrows(
            \RuntimeException::class,
            fn () => $this->connection->table('contacts')->update(['first_name' => 'Oops'])
        );

        $this->assertThrows(
            \RuntimeException::class,
            fn () => $this->connection->table('contacts')->delete()
        );
    }

    public function testCustomFieldSegmentRuleRejectsAnUnknownKey(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var SegmentService $segments */
        $segments = $this->container->make(SegmentService::class);

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => $segments->query([
                'match' => 'all',
                'rules' => [[
                    'field'            => 'custom_field',
                    'operator'         => 'equals',
                    'value'            => 'x',
                    'custom_field_key' => 'value_text FROM contact_custom_fields; --',
                ]],
            ])->get()
        );
    }

    public function testCsvExportNeutralisesFormulaInjection(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        // A contact name is user-supplied; opened in a spreadsheet, a leading "="
        // would be evaluated as a formula.
        $this->createContact([
            'email'      => 'formula@example.com',
            'first_name' => '=HYPERLINK("http://evil.test","click")',
        ]);

        $response = $this->get('/contacts/export');

        $this->assertStatus(200, $response);
        $this->assertContainsString("'=HYPERLINK", $response->body(), 'The cell is prefixed so it stays text');
    }
}
