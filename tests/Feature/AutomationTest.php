<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Automation\AutomationService;
use App\Automation\JourneyRunner;
use App\Automation\TriggerDispatcher;
use App\Core\ValidationException;
use App\Mail\EmailProviderInterface;
use App\Mail\LogEmailProvider;
use App\Services\ContactService;
use App\Support\DnsResolver;
use App\Support\FakeDnsResolver;
use Tests\Support\TestCase;

/**
 * §27 — journeys.
 *
 * An automation is the easiest place in a product like this to accidentally
 * build a second, laxer sending path: it runs unattended, its recipients arrive
 * one at a time, and nobody is watching. So most of what is tested here is that
 * it did not get one — the same compliance service, the same suppression, at the
 * same moment relative to the provider.
 */
final class AutomationTest extends TestCase
{
    /** @var array{organisation_id:int,user_id:int} */
    private array $context;

    public function setUp(): void
    {
        parent::setUp();

        $this->container->instance(DnsResolver::class, new FakeDnsResolver());

        $org = $this->createOrganisation(['name' => 'Perth Plumbing Co']);

        $this->connection->execute(
            'UPDATE organisations SET address_line1 = ?, address_city = ?, address_country = ?,
                    contact_email = ?, default_sender_name = ?, default_sender_email = ?
             WHERE id = ?',
            ['12 Example St', 'Perth', 'AU', 'hello@perthplumbing.test', 'Perth Plumbing Co',
             'hello@perthplumbing.test', $org['organisation_id']]
        );

        $this->actingAs($org['user_id'], $org['organisation_id']);
        $this->context = ['organisation_id' => $org['organisation_id'], 'user_id' => $org['user_id']];
    }

    // ------------------------------------------------------------- the rules

    public function testAJourneyIsAlwaysCreatedSwitchedOff(): void
    {
        $id = $this->service()->create(['name' => 'Welcome', 'trigger_type' => 'contact_created']);

        $automation = $this->service()->find($id);

        // One that went live the moment it was created would send email nobody
        // had looked at.
        $this->assertSame('draft', (string) $automation['status']);
        $this->assertCount(1, $automation['nodes'], 'It starts with a trigger step and nothing else');
    }

    public function testAJourneyWithNoStepsCannotBeSwitchedOn(): void
    {
        $id      = $this->service()->create(['name' => 'Empty', 'trigger_type' => 'contact_created']);
        $service = $this->service();

        $exception = $this->assertThrows(ValidationException::class, static fn () => $service->activate($id));

        $this->assertContainsString('Nothing happens after the start', implode(' ', $exception->firstErrors()));
    }

    public function testAnEmailStepWithNoEmailOnItBlocksActivation(): void
    {
        $id = $this->journeyWith([['action', 'send_email', ['subject' => '']]]);

        $problems = $this->service()->problems($id);

        // Better caught now than at 3am with two hundred contacts already in it.
        $this->assertContainsString('no email or no subject', implode(' ', $problems));
    }

    // ------------------------------------------------------------- entering

    public function testAddingAContactStartsTheJourney(): void
    {
        $id = $this->journeyWith([['action', 'add_tag', ['tag_id' => $this->tag('Welcomed')]]]);
        $this->service()->activate($id);

        $this->container->make(ContactService::class)->create(
            ['email' => 'new@example.com', 'first_name' => 'Sam'],
            ['status' => 'granted', 'consent_type' => 'express']
        );

        $this->assertSame(1, $this->runCount($id));
    }

    /**
     * A contact re-tagged nightly by an import must not be emailed nightly.
     */
    public function testSomebodyOnlyGoesThroughOnce(): void
    {
        $tagId = $this->tag('VIP');
        $id    = $this->journeyWith([['action', 'add_tag', ['tag_id' => $this->tag('Welcomed')]]], 'tag_added', ['tag_id' => $tagId]);
        $this->service()->activate($id);

        $contactId = $this->createContact(['email' => 'repeat@example.com']);
        $contacts  = $this->container->make(ContactService::class);

        $contacts->addTag($contactId, $tagId);
        $this->runQueueToCompletion();

        $contacts->removeTag($contactId, $tagId);
        $contacts->addTag($contactId, $tagId);
        $this->runQueueToCompletion();

        $this->assertSame(1, $this->runCount($id), 'The second tagging did not start a second run');
    }

    public function testReEnteringIsPossibleWhenTheAuthorAsksForIt(): void
    {
        $tagId = $this->tag('VIP');
        $id    = $this->journeyWith(
            [['action', 'add_tag', ['tag_id' => $this->tag('Welcomed')]]],
            'tag_added',
            ['tag_id' => $tagId]
        );

        $this->service()->update($id, [
            'allow_reentry'          => 1,
            'reentry_cooldown_hours' => 0,
            'max_runs_per_contact'   => 3,
        ]);
        $this->service()->activate($id);

        $contactId = $this->createContact(['email' => 'repeat@example.com']);
        $contacts  = $this->container->make(ContactService::class);

        for ($i = 0; $i < 4; $i++) {
            $contacts->addTag($contactId, $tagId);
            $this->runQueueToCompletion();
            $contacts->removeTag($contactId, $tagId);
        }

        // Three runs, not four: the lifetime cap still applies.
        $this->assertSame(3, $this->runCount($id));
    }

    /** Re-applying a tag somebody already has is not an event. */
    public function testATagThatWasAlreadyThereDoesNotStartAnything(): void
    {
        $tagId = $this->tag('VIP');
        $id    = $this->journeyWith(
            [['action', 'add_tag', ['tag_id' => $this->tag('Welcomed')]]],
            'tag_added',
            ['tag_id' => $tagId]
        );
        $this->service()->activate($id);

        $contactId = $this->createContact(['email' => 'already@example.com']);
        $contacts  = $this->container->make(ContactService::class);

        $contacts->addTag($contactId, $tagId);
        $this->connection->execute('DELETE FROM automation_runs');
        $contacts->addTag($contactId, $tagId);

        $this->assertSame(0, $this->runCount($id));
    }

    // -------------------------------------------------------------- running

    public function testAJourneyWalksItsStepsAndFinishes(): void
    {
        $welcomed = $this->tag('Welcomed');
        $id       = $this->journeyWith([
            ['action', 'add_tag', ['tag_id' => $welcomed]],
            ['action', 'create_task', ['title' => 'Ring the new customer', 'due_in_hours' => 48]],
        ]);
        $this->service()->activate($id);

        $contactId = $this->createContact(['email' => 'walker@example.com']);
        $this->enter($id, $contactId);
        $this->runQueueToCompletion();

        $run = $this->connection->selectOne('SELECT * FROM automation_runs LIMIT 1') ?? [];

        $this->assertSame('completed', (string) $run['status']);
        $this->assertSame(1, (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM contact_tags WHERE contact_id = ? AND tag_id = ?',
            [$contactId, $welcomed]
        ));
        $this->assertSame(1, (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM lead_tasks WHERE contact_id = ? AND created_via = 'automation'",
            [$contactId]
        ));
    }

    public function testAWaitStepParksTheRunUntilItsTimeComes(): void
    {
        $id = $this->journeyWith([
            ['wait', null, [], 1440],
            ['action', 'add_tag', ['tag_id' => $this->tag('Welcomed')]],
        ]);
        $this->service()->activate($id);

        $contactId = $this->createContact(['email' => 'waiter@example.com']);
        $this->enter($id, $contactId);
        $this->runQueueToCompletion();

        $run = $this->connection->selectOne('SELECT * FROM automation_runs LIMIT 1') ?? [];

        $this->assertSame('waiting', (string) $run['status']);
        $this->assertNotNull($run['resume_at'], 'And it knows when to wake up');

        // A journey that waits three weeks costs nothing while it waits.
        $this->assertSame(0, (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM contact_tags WHERE contact_id = ?',
            [$contactId]
        ));

        // Time passes.
        $this->connection->execute(
            "UPDATE automation_runs SET resume_at = '2020-01-01 00:00:00' WHERE id = ?",
            [(int) $run['id']]
        );

        $this->service()->advanceDueRuns();

        $this->assertSame(
            'completed',
            (string) ($this->connection->selectOne('SELECT * FROM automation_runs LIMIT 1')['status'] ?? '')
        );
    }

    /** A journey wired in a circle must fail loudly, not burn a worker. */
    public function testALoopIsStoppedAtTheStepCap(): void
    {
        $id = $this->service()->create(['name' => 'Loop', 'trigger_type' => 'contact_created']);

        $trigger = $this->service()->find($id)['nodes'][0];
        $a = $this->service()->addNode($id, ['node_type' => 'action', 'action_type' => 'add_tag',
            'config' => ['tag_id' => $this->tag('A')]]);
        $b = $this->service()->addNode($id, ['node_type' => 'action', 'action_type' => 'add_tag',
            'config' => ['tag_id' => $this->tag('B')]]);

        $this->service()->connect($id, (int) $trigger['id'], $a);
        $this->service()->connect($id, $a, $b);
        $this->service()->connect($id, $b, $a);

        $this->connection->execute("UPDATE automations SET status = 'active' WHERE id = ?", [$id]);

        $contactId = $this->createContact(['email' => 'looper@example.com']);
        $this->enter($id, $contactId);
        $this->runQueueToCompletion();

        $run = $this->connection->selectOne('SELECT * FROM automation_runs LIMIT 1') ?? [];

        $this->assertSame('failed', (string) $run['status']);
        $this->assertContainsString('round in circles', (string) $run['last_error']);
    }

    /** One contact's broken step must not stop three hundred others. */
    public function testABrokenStepFailsOneRunAndNotTheJourney(): void
    {
        $id = $this->journeyWith([['action', 'add_tag', ['tag_id' => 999999]]]);
        $this->service()->activate($id);

        foreach (['one@example.com', 'two@example.com'] as $email) {
            $this->enter($id, $this->createContact(['email' => $email]));
        }

        $this->runQueueToCompletion();

        $this->assertSame(2, (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM automation_runs WHERE status = 'failed'"
        ));
        $this->assertSame(
            'active',
            (string) ($this->connection->selectOne('SELECT status FROM automations WHERE id = ?', [$id])['status'] ?? '')
        );
    }

    // ----------------------------------------------------------- compliance

    /**
     * The whole point. An automation does not get its own sending path.
     */
    public function testAJourneyWillNotEmailSomebodyOnTheDoNotEmailList(): void
    {
        $id = $this->emailJourney();
        $this->service()->activate($id);

        $contactId = $this->createContact(['email' => 'gone@example.com'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        $this->container->make(\App\Services\SuppressionService::class)
            ->suppress('gone@example.com', 'unsubscribe', ['source' => 'recipient']);

        $this->enter($id, $contactId);
        $this->runQueueToCompletion();

        $this->assertCount(0, $this->provider()->sentMessages());

        // And it is written down, because a silent non-send is indistinguishable
        // from a bug.
        $log = $this->connection->selectOne(
            "SELECT * FROM automation_run_logs WHERE outcome = 'blocked'"
        ) ?? [];

        $this->assertSame('SUPPRESSED_UNSUBSCRIBE', (string) $log['reason_code']);
    }

    public function testAJourneyWillNotEmailSomebodyWhoNeverAgreed(): void
    {
        $this->connection->execute(
            "UPDATE organisations SET country = 'AU' WHERE id = ?",
            [$this->context['organisation_id']]
        );
        $this->bindTenant($this->context['organisation_id']);

        $id = $this->emailJourney();
        $this->service()->activate($id);

        // No consent recorded at all.
        $contactId = $this->createContact(['email' => 'unknown@example.com', 'country' => 'AU']);

        $this->enter($id, $contactId);
        $this->runQueueToCompletion();

        $this->assertCount(0, $this->provider()->sentMessages());
    }

    public function testAJourneyEmailCarriesAnUnsubscribeLinkLikeEverythingElse(): void
    {
        $id = $this->emailJourney();
        $this->service()->activate($id);

        $contactId = $this->createContact(['email' => 'ok@example.com'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        $this->enter($id, $contactId);
        $this->runQueueToCompletion();

        $messages = $this->provider()->sentMessages();
        $this->assertCount(1, $messages);

        $headers = $messages[0]->allHeaders();
        $this->assertContainsString('/unsubscribe/', (string) $headers['List-Unsubscribe']);
        $this->assertSame('auto-generated', (string) $headers['Auto-Submitted']);
    }

    // ------------------------------------------------------------- isolation

    public function testAJourneyNeverReachesIntoAnotherTenant(): void
    {
        $id = $this->journeyWith([['action', 'add_tag', ['tag_id' => $this->tag('Welcomed')]]]);
        $this->service()->activate($id);

        $other = $this->createOrganisation(['name' => 'Someone Else']);
        $this->actingAs($other['user_id'], $other['organisation_id']);

        // A contact created in the other tenant must not enter our journey.
        $this->container->make(ContactService::class)->create(
            ['email' => 'theirs@example.com'],
            ['status' => 'granted', 'consent_type' => 'express']
        );

        $this->bindTenant($this->context['organisation_id']);

        $this->assertSame(0, $this->runCount($id));
    }

    // -------------------------------------------------------------- the page

    public function testTheJourneyScreensRender(): void
    {
        $index = $this->get('/automations');
        $this->assertStatus(200, $index);
        $this->assertContainsString('Chase an enquiry nobody answered', $index->body(), 'Templates are offered');

        $id = $this->journeyWith([['action', 'add_tag', ['tag_id' => $this->tag('Welcomed')]]]);

        $show = $this->get('/automations/' . $id);
        $this->assertStatus(200, $show);
        $this->assertContainsString('Permission still applies', $show->body());
    }

    // ------------------------------------------------------------- internals

    private function service(): AutomationService
    {
        return $this->container->make(AutomationService::class);
    }

    private function provider(): LogEmailProvider
    {
        /** @var LogEmailProvider $provider */
        $provider = $this->container->make(EmailProviderInterface::class);

        return $provider;
    }

    private function runCount(int $automationId): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM automation_runs WHERE automation_id = ?',
            [$automationId]
        );
    }

    private function enter(int $automationId, int $contactId): void
    {
        $this->container->make(JourneyRunner::class)
            ->enter($this->service()->find($automationId), $contactId);
    }

    /** Advance every run until nothing moves, the way successive ticks would. */
    private function runQueueToCompletion(int $ticks = 6): void
    {
        $organisationId = $this->tenant->organisationId();

        for ($i = 0; $i < $ticks; $i++) {
            $runs = $this->connection->select(
                "SELECT id FROM automation_runs WHERE status IN ('active','waiting') AND (resume_at IS NULL OR resume_at <= ?)",
                [$this->clock->nowString()]
            );

            if ($runs === []) {
                break;
            }

            foreach ($runs as $run) {
                $this->container->make(JourneyRunner::class)->advance((int) $run['id']);
            }
        }

        $this->bindTenant($organisationId);
    }

    private function tag(string $name): int
    {
        return $this->container->make(\App\Repositories\TagRepository::class)->firstOrCreate($name);
    }

    /**
     * @param array<int,array{0:string,1:?string,2:array<string,mixed>,3?:int}> $steps
     * @param array<string,mixed> $triggerConfig
     */
    private function journeyWith(
        array $steps,
        string $trigger = 'contact_created',
        array $triggerConfig = [],
    ): int {
        $id = $this->service()->create([
            'name'           => 'Test journey',
            'trigger_type'   => $trigger,
            'trigger_config' => $triggerConfig,
        ]);

        $previous = (int) $this->service()->find($id)['nodes'][0]['id'];

        foreach ($steps as $step) {
            $nodeId = $this->service()->addNode($id, [
                'node_type'    => $step[0],
                'action_type'  => $step[1],
                'config'       => $step[2],
                'wait_minutes' => $step[3] ?? 60,
            ]);

            $this->service()->connect($id, $previous, $nodeId);
            $previous = $nodeId;
        }

        return $id;
    }

    private function emailJourney(): int
    {
        $templateId = $this->container->make(\App\Services\TemplateService::class)->create('Hello', [
            ['type' => 'text', 'settings' => ['html' => '<p>Hello {{first_name}}.</p>']],
        ]);

        return $this->journeyWith([
            ['action', 'send_email', ['template_id' => $templateId, 'subject' => 'Thanks for choosing us']],
        ]);
    }
}
