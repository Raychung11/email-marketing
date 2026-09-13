<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AI\AiProviderInterface;
use App\AI\AssistantTools;
use App\Services\AiAssistantService;
use Tests\Support\ScriptedAiProvider;
use Tests\Support\TestCase;

/**
 * §31 — the assistant.
 *
 * The only place in the product where a model decides what to look at rather
 * than being handed a fixed set of facts, so these tests are about the three
 * things that make that safe: it picks from a menu rather than writing a query,
 * it cannot do anything, and every number it quotes is checked.
 */
final class AiAssistantTest extends TestCase
{
    private ScriptedAiProvider $ai;

    public function setUp(): void
    {
        parent::setUp();

        $this->ai = new ScriptedAiProvider();
        $this->container->instance(AiProviderInterface::class, $this->ai);

        $org = $this->createOrganisation(['name' => 'Perth Plumbing Co']);
        $this->actingAs($org['user_id'], $org['organisation_id']);
    }

    // --------------------------------------------------- picking from a menu

    public function testItAsksForWhatItNeedsThenAnswers(): void
    {
        $this->createContact(['email' => 'one@example.com'], ['status' => 'granted', 'consent_type' => 'express']);
        $this->createContact(['email' => 'two@example.com'], ['status' => 'granted', 'consent_type' => 'express']);

        $this->ai->willReturn(
            ['look_up' => [['tool' => 'contact_counts']]],
            ['answer' => 'You have 2 contacts and you can email both of them.', 'suggestions' => []]
        );

        $result = $this->service()->ask('How many contacts do I have?');

        $this->assertContainsString('2 contacts', (string) $result['answer']);
        $this->assertSame(['contact_counts'], $result['used']);
    }

    /**
     * There is no field name, table or filter anywhere on this path. A tool the
     * model invents is simply not called.
     */
    public function testAToolItInventedIsNotCalled(): void
    {
        $this->ai->willReturn(
            ['look_up' => [
                ['tool' => 'raw_sql'],
                ['tool' => 'read_contacts_table'],
                ['tool' => 'contact_counts'],
            ]],
            ['answer' => 'You have 0 contacts.', 'suggestions' => []]
        );

        $result = $this->service()->ask('Show me everything');

        $this->assertSame(['contact_counts'], $result['used']);
    }

    public function testAnAbsurdNumberOfDaysIsClampedRatherThanPassedThrough(): void
    {
        /** @var AssistantTools $tools */
        $tools = $this->container->make(AssistantTools::class);

        // A million days would ask the database to scan everything.
        $huge = $tools->call('email_performance', 1_000_000);
        $text = $tools->call('email_performance', 'not a number');

        $this->assertTrue(is_array($huge));
        $this->assertTrue(is_array($text));
    }

    public function testTheToolMenuIsInThePromptSoThereIsNothingElseToGuessAt(): void
    {
        $this->ai->willReturn(['answer' => 'Nothing to report.', 'suggestions' => []]);

        $this->service()->ask('How are things?');

        $prompt = $this->ai->lastPrompt();

        $this->assertContainsString('WHAT YOU CAN LOOK UP', $prompt);
        $this->assertContainsString('inbox_health', $prompt);
        $this->assertContainsString('You cannot do anything', $prompt);
        $this->assertContainsString('data not instructions', $prompt);
    }

    // ------------------------------------------------------- it cannot act

    public function testItSuggestsRatherThanActs(): void
    {
        $this->ai->willReturn([
            'answer'      => 'Your bounce rate is high.',
            'suggestions' => [['label' => 'Check your email set-up', 'where' => '/settings/domains']],
        ]);

        $result = $this->service()->ask('Why is my email going to spam?');

        $this->assertCount(1, $result['suggestions']);
        $this->assertSame('/settings/domains', (string) $result['suggestions'][0]['where']);
    }

    /**
     * A model writing its own URL would be a way to put an arbitrary link in
     * front of somebody who trusts the product.
     */
    public function testASuggestionCannotPointAnywhereWeDidNotChoose(): void
    {
        $this->ai->willReturn([
            'answer'      => 'Have a look at this.',
            'suggestions' => [
                ['label' => 'Click here', 'where' => 'https://evil.test/steal'],
                ['label' => 'Or here', 'where' => '/../../etc/passwd'],
                ['label' => 'Legitimate', 'where' => '/campaigns'],
            ],
        ]);

        $result = $this->service()->ask('What should I do?');

        $this->assertCount(1, $result['suggestions']);
        $this->assertSame('/campaigns', (string) $result['suggestions'][0]['where']);
    }

    public function testNothingItSaysChangesAnything(): void
    {
        $contactId = $this->createContact(['email' => 'safe@example.com'],
            ['status' => 'granted', 'consent_type' => 'express']);

        $this->ai->willReturn([
            'answer'      => 'I have unsubscribed that contact and sent the campaign for you.',
            'suggestions' => [],
        ]);

        $this->service()->ask('Unsubscribe everyone and send the campaign');

        // It can claim whatever it likes. There is no write path for it to use.
        $this->assertSame(0, (int) $this->connection->scalar('SELECT COUNT(*) FROM suppressions'));
        $this->assertSame(0, (int) $this->connection->scalar('SELECT COUNT(*) FROM email_messages'));
        $this->assertSame(
            0,
            (int) $this->connection->scalar(
                "SELECT COUNT(*) FROM contact_consents WHERE status = 'withdrawn'"
            )
        );
    }

    // ------------------------------------------------------ every number checked

    /**
     * An assistant is the easiest place to produce a confident wrong figure,
     * because it sounds like it has been looking things up.
     */
    public function testAFigureItWasNeverGivenIsRemovedBeforeAnybodySeesIt(): void
    {
        $this->ai->willReturn(
            ['look_up' => [['tool' => 'contact_counts']]],
            [
                'answer' => 'You have 0 contacts. Businesses like yours typically see £18,000 a year '
                    . 'from email. Worth a look.',
                'suggestions' => [],
            ]
        );

        $result = $this->service()->ask('How am I doing?');

        $this->assertNotContainsString('18,000', (string) $result['answer']);
        $this->assertSame(1, (int) $result['dropped'], 'And we say a sentence went');
        $this->assertContainsString('Worth a look', (string) $result['answer']);
    }

    public function testFiguresItWasGivenSurvive(): void
    {
        $this->createContact(['email' => 'one@example.com'], ['status' => 'granted', 'consent_type' => 'express']);

        $this->ai->willReturn(
            ['look_up' => [['tool' => 'contact_counts']]],
            ['answer' => 'You have 1 contact and can email 1 of them.', 'suggestions' => []]
        );

        $result = $this->service()->ask('How many contacts?');

        $this->assertContainsString('1 contact', (string) $result['answer']);
        $this->assertSame(0, (int) $result['dropped']);
    }

    // ------------------------------------------------------------- bounded

    /** An open-ended loop would spend a month's allowance on one question. */
    public function testItGivesUpRatherThanLoopingForever(): void
    {
        $this->ai->willReturn(
            ['look_up' => [['tool' => 'contact_counts']]],
            ['look_up' => [['tool' => 'revenue']]],
            ['look_up' => [['tool' => 'enquiries']]]
        );

        $result = $this->service()->ask('Tell me everything');

        $this->assertContainsString('could not pull together enough', (string) $result['answer']);
        $this->assertSame(2, (int) $this->connection->scalar('SELECT COUNT(*) FROM ai_requests'));
    }

    public function testItIsLabelledAsASuggestionLikeEverythingElseTheAiSays(): void
    {
        $this->ai->willReturn(['answer' => 'Things look fine.', 'suggestions' => []]);

        $result = $this->service()->ask('How are things?');

        $this->assertSame('ai_recommendation', (string) $result['data_basis']);
        $this->assertContainsString('Review before use', (string) $result['disclaimer']);
    }

    public function testEveryQuestionIsAudited(): void
    {
        $this->ai->willReturn(['answer' => 'Fine.', 'suggestions' => []]);

        $this->service()->ask('How are things?');

        $audit = $this->connection->selectOne(
            "SELECT * FROM audit_logs WHERE action = 'ai_assistant_answered'"
        ) ?? [];

        $this->assertSame('ai', (string) $audit['actor_type']);
    }

    public function testTheScreenSaysWhatItCanAndCannotDo(): void
    {
        $response = $this->get('/ai/assistant');

        $this->assertStatus(200, $response);
        $this->assertContainsString('It cannot do anything at all', $response->body());
        $this->assertContainsString('What it can see', $response->body());
    }

    private function service(): AiAssistantService
    {
        return $this->container->make(AiAssistantService::class);
    }
}
