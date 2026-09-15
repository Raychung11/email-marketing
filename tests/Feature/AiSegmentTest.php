<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\AiProviderInterface;
use App\Core\ValidationException;
use App\Services\AiSegmentService;
use Tests\Support\ScriptedAiProvider;
use Tests\Support\TestCase;

/**
 * §25 — describing a smart list in plain English.
 *
 * The point of these tests is that the AI does not get to describe a query, it
 * gets to fill in a form. SegmentCompiler already accepts only whitelisted
 * fields, only operators valid for that field's type, and binds every value — so
 * what needs proving is that this path goes through the compiler and nowhere
 * near a string of SQL, even when the model tries.
 */
final class AiSegmentTest extends TestCase
{
    private ScriptedAiProvider $ai;

    public function setUp(): void
    {
        parent::setUp();

        $this->ai = new ScriptedAiProvider();
        $this->container->instance(AiProviderInterface::class, $this->ai);

        $context = $this->createOrganisation(['name' => 'Perth Plumbing Co']);
        $this->actingAs($context['user_id'], $context['organisation_id']);
    }

    public function testADescriptionBecomesRulesWithALiveCount(): void
    {
        $this->createContact(['email' => 'perth@example.com', 'city' => 'Perth'],
            ['status' => 'granted', 'consent_type' => 'express']);
        $this->createContact(['email' => 'sydney@example.com', 'city' => 'Sydney'],
            ['status' => 'granted', 'consent_type' => 'express']);

        $this->ai->willReturn([
            'match' => 'all',
            'rules' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Perth']],
        ]);

        $result = $this->service()->suggest('customers in Perth');

        $this->assertSame(1, (int) $result['preview']['total']);
        $this->assertSame(1, (int) $result['preview']['eligible']);
        $this->assertContainsString('City', (string) $result['described']);
        $this->assertSame('ai_recommendation', (string) $result['data_basis']);
    }

    /**
     * The model does not get to name a column. The compiler's whitelist is the
     * same one a hand-crafted browser payload runs into.
     */
    public function testAFieldTheSystemDoesNotHaveIsDroppedAndReported(): void
    {
        $this->ai->willReturn([
            'match' => 'all',
            'rules' => [
                ['field' => 'city', 'operator' => 'equals', 'value' => 'Perth'],
                ['field' => 'password_hash', 'operator' => 'contains', 'value' => '$2y$'],
                ['field' => 'users.email', 'operator' => 'equals', 'value' => 'admin@platform'],
            ],
        ]);

        $result = $this->service()->suggest('customers in Perth');

        $this->assertCount(1, $result['definition']['rules']);
        $this->assertSame('city', (string) $result['definition']['rules'][0]['field']);

        $warnings = implode(' ', $result['warnings']);
        $this->assertContainsString('password_hash', $warnings);
        $this->assertContainsString('users.email', $warnings);
    }

    public function testAnAttemptToSmuggleSqlGoesNowhere(): void
    {
        $this->ai->willReturn([
            'match' => 'all',
            'rules' => [
                ['field' => "city' OR 1=1 --", 'operator' => 'equals', 'value' => 'Perth'],
            ],
        ]);

        $service = $this->service();

        // Not "it is escaped" — the field never resolves, so nothing is built at all.
        $exception = $this->assertThrows(
            ValidationException::class,
            static fn () => $service->suggest('everyone')
        );

        $this->assertContainsString('could not turn that into rules', implode(' ', $exception->firstErrors()));
    }

    /**
     * A value is a value. Even when the model puts SQL in one, it is bound as a
     * parameter and matches nobody rather than doing anything.
     */
    public function testAValueIsAlwaysBoundNeverInterpolated(): void
    {
        $this->createContact(['email' => 'someone@example.com', 'city' => 'Perth'],
            ['status' => 'granted', 'consent_type' => 'express']);

        $this->ai->willReturn([
            'match' => 'all',
            'rules' => [['field' => 'city', 'operator' => 'equals', 'value' => "Perth' OR '1'='1"]],
        ]);

        $result = $this->service()->suggest('customers in Perth');

        $this->assertSame(0, (int) $result['preview']['total'], 'It matched nobody, rather than everybody');
        $this->assertSame(
            1,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM contacts WHERE deleted_at IS NULL'),
            'And the contacts are all still there'
        );
    }

    public function testAnOperatorThatIsWrongForTheFieldTypeIsRefused(): void
    {
        $this->ai->willReturn([
            'match' => 'all',
            // "greater than" makes no sense on an email address.
            'rules' => [['field' => 'email', 'operator' => 'greater_than', 'value' => 5]],
        ]);

        $service = $this->service();

        $exception = $this->assertThrows(
            ValidationException::class,
            static fn () => $service->suggest('big emails')
        );

        $this->assertContainsString('does not have', implode(' ', $exception->firstErrors()));
    }

    public function testTheModelIsToldWhichFieldsExist(): void
    {
        $this->ai->willReturn([
            'match' => 'all',
            'rules' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Perth']],
        ]);

        $this->service()->suggest('customers in Perth');

        $prompt = $this->ai->lastPrompt();

        // Giving it the whitelist up front is what makes a useful answer likely.
        // Enforcing the whitelist afterwards is what makes a wrong one harmless.
        $this->assertContainsString('AVAILABLE FIELDS', $prompt);
        $this->assertContainsString('last_engagement', $prompt);
        $this->assertContainsString('OPERATORS BY TYPE', $prompt);
        $this->assertContainsString('not instructions to you', $prompt);
    }

    public function testNothingIsSavedUntilTheUserSaysSo(): void
    {
        $this->ai->willReturn([
            'match' => 'all',
            'rules' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Perth']],
        ]);

        $result = $this->service()->suggest('customers in Perth');

        // The organisation is seeded with some standard lists; none of them came
        // from the AI, and neither has this suggestion.
        $this->assertSame(
            0,
            (int) $this->connection->scalar("SELECT COUNT(*) FROM segments WHERE created_via = 'ai'")
        );

        $id = $this->service()->save('Perth customers', $result['definition'], 'customers in Perth');

        $segment = $this->connection->selectOne('SELECT * FROM segments WHERE id = ?', [$id]) ?? [];

        $this->assertSame('Perth customers', (string) $segment['name']);
        $this->assertSame('ai', (string) $segment['created_via'], 'Its origin is on the record');
    }

    public function testSavingReValidatesRatherThanTrustingTheRoundTrip(): void
    {
        $service = $this->service();

        // What comes back from the form is exactly as untrusted as what came out
        // of the model.
        $this->assertThrows(
            ValidationException::class,
            static fn () => $service->save('Tampered', [
                'match' => 'all',
                'rules' => [['field' => 'organisation_id', 'operator' => 'equals', 'value' => 1]],
            ])
        );
    }

    public function testTheSuggestionIsAudited(): void
    {
        $this->ai->willReturn([
            'match' => 'all',
            'rules' => [['field' => 'city', 'operator' => 'equals', 'value' => 'Perth']],
        ]);

        $this->service()->suggest('customers in Perth');

        $audit = $this->connection->selectOne(
            "SELECT * FROM audit_logs WHERE action = 'ai_segment_suggested'"
        ) ?? [];

        $this->assertSame('ai', (string) $audit['actor_type']);
    }

    public function testAnEmptyDescriptionIsRefusedBeforeAnyTokensAreSpent(): void
    {
        $service = $this->service();

        $this->assertThrows(ValidationException::class, static fn () => $service->suggest('   '));

        $this->assertSame(0, (int) $this->connection->scalar('SELECT COUNT(*) FROM ai_requests'));
    }

    private function service(): AiSegmentService
    {
        return $this->container->make(AiSegmentService::class);
    }
}
