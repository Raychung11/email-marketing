<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\ValidationException;
use App\Services\AbTestService;
use App\Services\FormService;
use Tests\Support\TestCase;

/**
 * §29 — signup forms and A/B tests.
 *
 * Forms are where consent actually comes from, which makes them the most
 * important part of the compliance story: everything else in the product
 * defends a permission captured here, and a permission captured badly cannot be
 * defended at all.
 *
 * A/B tests are tested mostly for what they refuse to claim.
 */
final class FormAndAbTest extends TestCase
{
    /** @var array{organisation_id:int,user_id:int} */
    private array $context;

    public function setUp(): void
    {
        parent::setUp();

        $org = $this->createOrganisation(['name' => 'Perth Plumbing Co']);
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $this->context = ['organisation_id' => $org['organisation_id'], 'user_id' => $org['user_id']];
    }

    // ------------------------------------------------------------- consent

    /** Not a setting. A pre-ticked box is not consent anywhere we operate. */
    public function testTheConsentBoxIsNeverPreTicked(): void
    {
        $id = $this->publishedForm();

        $page = $this->get('/f/' . $this->context['organisation_id'] . '/' . $this->slug($id));

        $this->assertStatus(200, $page);
        $this->assertContainsString('name="consent"', $page->body());
        $this->assertNotContainsString('name="consent" value="1" checked', $page->body());
        $this->assertNotContainsString('checked', $page->body());
    }

    /**
     * Filling in "get a quote" is a request to be answered. It is not permission
     * to be marketed to.
     */
    public function testSubmittingWithoutTickingGrantsNoPermission(): void
    {
        $form = $this->forms()->find($this->publishedForm());

        $this->forms()->submit($form, ['email' => 'quiet@example.com', 'first_name' => 'Sam']);

        $contactId = (int) $this->connection->scalar('SELECT id FROM contacts LIMIT 1');

        $consent = $this->connection->selectOne(
            "SELECT * FROM contact_consents WHERE contact_id = ? AND status = 'granted'",
            [$contactId]
        );

        $this->assertNull($consent, 'No consent record at all');

        $submission = $this->connection->selectOne('SELECT * FROM form_submissions LIMIT 1') ?? [];
        $this->assertSame(0, (int) $submission['consent_given']);
    }

    /**
     * The evidence has to be the wording that was on screen, not a pointer to
     * text that will change.
     */
    public function testTheExactWordingIsStoredWithTheSubmission(): void
    {
        $formId = $this->publishedForm();
        $form   = $this->forms()->find($formId);

        $this->forms()->submit($form, [
            'email'      => 'keen@example.com',
            'first_name' => 'Sam',
            'consent'    => '1',
        ]);

        $original = (string) $form['consent_text'];

        // The business rewrites the form afterwards.
        $this->forms()->update($formId, ['consent_text' => 'Completely different wording now.']);

        $submission = $this->connection->selectOne('SELECT * FROM form_submissions LIMIT 1') ?? [];
        $consent    = $this->connection->selectOne(
            "SELECT * FROM contact_consents WHERE status = 'granted' ORDER BY id DESC LIMIT 1"
        ) ?? [];

        $this->assertSame($original, (string) $submission['consent_text_shown']);
        $this->assertSame($original, (string) $consent['consent_text']);
        $this->assertSame('1', (string) $submission['consent_version']);

        // And the form itself moved on to a new version, so the next person's
        // evidence points at what they will actually see.
        $this->assertSame('2', (string) $this->forms()->find($formId)['consent_version']);
    }

    public function testConsentEvidenceCapturesWhereAndWhen(): void
    {
        $form = $this->forms()->find($this->publishedForm());

        $this->forms()->submit(
            $form,
            ['email' => 'keen@example.com', 'consent' => '1'],
            ['ip' => '203.0.113.10', 'user_agent' => 'Mozilla/5.0']
        );

        $consent = $this->connection->selectOne(
            "SELECT * FROM contact_consents WHERE status = 'granted' ORDER BY id DESC LIMIT 1"
        ) ?? [];

        $this->assertSame('203.0.113.10', (string) $consent['ip_address']);
        $this->assertSame('website_form', (string) $consent['source']);
        $this->assertSame('express', (string) $consent['consent_type']);
    }

    /**
     * Somebody who unsubscribed and has now deliberately opted in again is the
     * one path allowed to clear a suppression — and only an unsubscribe.
     */
    public function testOptingInAgainClearsAnUnsubscribeButNotABounce(): void
    {
        $suppressions = $this->container->make(\App\Services\SuppressionService::class);

        $suppressions->suppress('returner@example.com', 'unsubscribe', ['source' => 'recipient']);
        $suppressions->suppress('dead@example.com', 'hard_bounce', ['source' => 'provider']);

        $form = $this->forms()->find($this->publishedForm());

        $this->forms()->submit($form, ['email' => 'returner@example.com', 'consent' => '1']);
        $this->forms()->submit($form, ['email' => 'dead@example.com', 'consent' => '1']);

        $this->assertFalse($suppressions->isSuppressed('returner@example.com'));
        $this->assertTrue(
            $suppressions->isSuppressed('dead@example.com'),
            'A dead address stays dead however keen they are'
        );
    }

    // ----------------------------------------------------------------- spam

    public function testTheHoneypotIsRecordedRatherThanShoutedAbout(): void
    {
        $form = $this->forms()->find($this->publishedForm());

        $result = $this->forms()->submit($form, [
            'email'       => 'bot@example.com',
            'website_url' => 'http://spam.test',
        ]);

        // Answered as if it worked: telling a bot it was caught only helps
        // whoever wrote it.
        $this->assertContainsString('touch', strtolower((string) $result['message']));
        $this->assertNull($result['contact_id']);

        $this->assertSame(0, (int) $this->connection->scalar('SELECT COUNT(*) FROM contacts'));
        $this->assertSame(1, (int) $this->connection->scalar('SELECT COUNT(*) FROM form_submissions WHERE is_spam = 1'));
    }

    public function testTheHoneypotIsHiddenOffScreenRatherThanDisplayNone(): void
    {
        $id   = $this->publishedForm();
        $page = $this->get('/f/' . $this->context['organisation_id'] . '/' . $this->slug($id))->body();

        // Some form-fillers skip display:none fields, which would defeat it.
        $this->assertContainsString('left:-9999px', $page);
        $this->assertContainsString('aria-hidden="true"', $page);
    }

    // -------------------------------------------------------------- posting

    public function testAFormPostCreatesAContactAndTiesUpTheirBrowsing(): void
    {
        $events = $this->container->make(\App\Services\EventTrackingService::class);
        $events->record(['event_name' => 'pricing_view', 'anonymous_id' => 'anon-1']);

        $id = $this->publishedForm();

        $response = $this->postPublicForm(
            '/f/' . $this->context['organisation_id'] . '/' . $this->slug($id),
            [
                'email'        => 'visitor@example.com',
                'first_name'   => 'Sam',
                'consent'      => '1',
                'anonymous_id' => 'anon-1',
            ]
        );

        $this->assertStatus(200, $response);
        $this->assertContainsString('Thank you', $response->body());

        $this->bindTenant($this->context['organisation_id']);

        $contactId = (int) $this->connection->scalar('SELECT id FROM contacts LIMIT 1');
        $this->assertTrue($contactId > 0);

        $this->assertSame(
            $contactId,
            (int) $this->connection->scalar('SELECT contact_id FROM tracking_events LIMIT 1'),
            'Their earlier browsing is attached now they have told us who they are'
        );
    }

    public function testAFormFromAnotherOrganisationIsNotFound(): void
    {
        $id   = $this->publishedForm();
        $slug = $this->slug($id);

        $other = $this->createOrganisation(['name' => 'Someone Else']);

        // Right slug, wrong organisation. The pair is looked up together.
        $response = $this->get('/f/' . $other['organisation_id'] . '/' . $slug);

        $this->assertStatus(404, $response);
    }

    public function testADraftFormIsNotPubliclyReachable(): void
    {
        $id = $this->forms()->create(['name' => 'Not ready']);

        $response = $this->get('/f/' . $this->context['organisation_id'] . '/' . $this->slug($id));

        $this->assertStatus(404, $response);
    }

    // ------------------------------------------------------------ A/B tests

    /**
     * Most tools will call 4.1% a winner over 3.8% on two hundred people. That
     * is noise dressed as insight, and the customer changes their whole approach
     * on the strength of it.
     */
    public function testASmallSampleIsNeverDeclaredAWinner(): void
    {
        $campaignId = $this->campaign();
        $this->abTests()->create($campaignId, ['Your boiler is due', 'Time for a service']);

        // 50 each, one clearly ahead.
        $this->messages($campaignId, 'A', 50, 20);
        $this->messages($campaignId, 'B', 50, 4);

        $verdict = $this->abTests()->evaluate($campaignId);

        $this->assertFalse($verdict['decided']);
        $this->assertContainsString('Not enough people', (string) $verdict['headline']);
        $this->assertContainsString('as likely to be luck', (string) $verdict['detail']);
        $this->assertSame(50, (int) $verdict['needed']);
    }

    public function testACloseResultIsCalledTooCloseRatherThanGuessed(): void
    {
        $campaignId = $this->campaign();
        $this->abTests()->create($campaignId, ['Your boiler is due', 'Time for a service']);

        // 300 each: 4.0% against 3.7%. Plenty of people, no real difference.
        $this->messages($campaignId, 'A', 300, 12);
        $this->messages($campaignId, 'B', 300, 11);

        $verdict = $this->abTests()->evaluate($campaignId);

        $this->assertFalse($verdict['decided']);
        $this->assertContainsString('Too close to call', (string) $verdict['headline']);
        $this->assertContainsString('do not change how you write', (string) $verdict['detail']);
    }

    public function testARealDifferenceIsCalledAndExplained(): void
    {
        $campaignId = $this->campaign();
        $this->abTests()->create($campaignId, ['Your boiler is due', 'Time for a service']);

        // 500 each: 12% against 3%. That is not chance.
        $this->messages($campaignId, 'A', 500, 60);
        $this->messages($campaignId, 'B', 500, 15);

        $verdict = $this->abTests()->evaluate($campaignId);

        $this->assertTrue($verdict['decided']);
        $this->assertSame('A', (string) $verdict['winner']);
        $this->assertContainsString('big enough to be real rather than luck', (string) $verdict['detail']);

        $test = $this->abTests()->forCampaign($campaignId);
        $this->assertSame('decided', (string) $test['status']);
    }

    public function testTheSampleIsSplitEvenlyRatherThanRandomly(): void
    {
        // Randomising 200 people can easily give 120/80, and then the comparison
        // is between two different-sized groups before anybody opens anything.
        $assignment = $this->abTests()->assign(
            [['key' => 'A', 'subject' => 'x'], ['key' => 'B', 'subject' => 'y']],
            201
        );

        $counts = array_count_values($assignment);

        $this->assertSame(101, $counts['A']);
        $this->assertSame(100, $counts['B']);
    }

    public function testATestNeedsAtLeastTwoSubjectLines(): void
    {
        $campaignId = $this->campaign();
        $service    = $this->abTests();

        $this->assertThrows(
            ValidationException::class,
            static fn () => $service->create($campaignId, ['Only one'])
        );
    }

    public function testTheFormScreensRender(): void
    {
        $id = $this->publishedForm();

        $index = $this->get('/forms');
        $this->assertStatus(200, $index);
        $this->assertContainsString('This is where permission comes from', $index->body());

        $show = $this->get('/forms/' . $id);
        $this->assertStatus(200, $show);
        $this->assertContainsString('The box is never pre-ticked', $show->body());
        $this->assertContainsString('iframe', $show->body(), 'The embed code is offered');
    }

    // ------------------------------------------------------------ internals

    private function forms(): FormService
    {
        return $this->container->make(FormService::class);
    }

    private function abTests(): AbTestService
    {
        return $this->container->make(AbTestService::class);
    }

    private function publishedForm(): int
    {
        $id = $this->forms()->create(['name' => 'Get a quote', 'heading' => 'Get a quote']);
        $this->forms()->publish($id);

        return $id;
    }

    private function slug(int $formId): string
    {
        return (string) $this->connection->scalar('SELECT slug FROM forms WHERE id = ?', [$formId]);
    }

    private function campaign(): int
    {
        return $this->container->make(\App\Services\CampaignService::class)->create([
            'name'          => 'Autumn service reminder',
            'subject'       => 'Your annual check is due',
            'campaign_type' => 'service',
        ]);
    }

    private function messages(int $campaignId, string $variant, int $delivered, int $clicked): void
    {
        for ($i = 0; $i < $delivered; $i++) {
            $email = $variant . '-' . $i . '@example.com';

            $this->connection->table('email_messages')->insert([
                'uuid'             => uuid4(),
                'organisation_id'  => $this->tenant->organisationId(),
                'campaign_id'      => $campaignId,
                'message_class'    => 'marketing',
                'provider'         => 'log',
                'email'            => $email,
                'email_normalized' => $email,
                'subject'          => 'Subject',
                'status'           => 'delivered',
                'ab_variant'       => $variant,
                'sent_at'          => $this->clock->nowString(),
                'clicked_at'       => $i < $clicked ? $this->clock->nowString() : null,
                'created_at'       => $this->clock->nowString(),
            ]);
        }
    }
}
