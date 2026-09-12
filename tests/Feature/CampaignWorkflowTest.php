<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\CampaignRepository;
use App\Services\CampaignService;
use App\Support\DnsResolver;
use App\Support\FakeDnsResolver;
use Tests\Support\TestCase;

/**
 * §17 / §18 — campaigns and the approval workflow.
 *
 * The two rules that make review mean something: content cannot change after
 * approval, and an author cannot approve their own work.
 */
final class CampaignWorkflowTest extends TestCase
{
    private FakeDnsResolver $dns;

    public function setUp(): void
    {
        parent::setUp();

        $this->dns = new FakeDnsResolver();
        $this->container->instance(DnsResolver::class, $this->dns);
    }

    public function testACampaignStartsAsADraftWithSenderDefaults(): void
    {
        $context = $this->sendableOrganisation();

        $id = $this->service()->create([
            'name'          => 'Autumn service reminder',
            'campaign_type' => 'service',
        ]);

        $campaign = $this->service()->find($id);

        $this->assertSame('draft', (string) $campaign['status']);
        $this->assertSame('hello@perthplumbing.test', (string) $campaign['from_email'], 'Sender defaults are applied');
        $this->assertSame('marketing', (string) $campaign['message_class']);
        $this->assertSame($context['user_id'], (int) $campaign['created_by_user_id']);
    }

    public function testMessageClassCannotBeSetFromInput(): void
    {
        $this->sendableOrganisation();

        // Relabelling a promotion as transactional is the classic way to try to
        // escape suppression and consent. It is derived, never accepted.
        $id = $this->service()->create([
            'name'          => 'Sneaky promotion',
            'campaign_type' => 'promotion',
            'message_class' => 'transactional',
        ]);

        $this->assertSame('marketing', (string) $this->service()->find($id)['message_class']);
    }

    public function testStatusAndCountersCannotBeSetFromInput(): void
    {
        $this->sendableOrganisation();

        $id = $this->service()->create([
            'name'             => 'Crafted',
            'campaign_type'    => 'newsletter',
            'status'           => 'approved',
            'sent_count'       => 99999,
            'approved_at'      => '2020-01-01 00:00:00',
            'organisation_id'  => 424242,
        ]);

        $campaign = $this->service()->find($id);

        $this->assertSame('draft', (string) $campaign['status'], 'A campaign cannot be born approved');
        $this->assertSame(0, (int) $campaign['sent_count']);
        $this->assertNull($campaign['approved_at']);
    }

    public function testAnIncompleteCampaignCannotBeSubmitted(): void
    {
        $this->sendableOrganisation();

        // No subject, no content, no audience.
        $id = $this->service()->create(['name' => 'Empty', 'campaign_type' => 'newsletter']);

        $service = $this->service();

        $exception = $this->assertThrows(
            ValidationException::class,
            static fn () => $service->submitForReview($id)
        );

        $messages = implode(' ', $exception->firstErrors());

        $this->assertContainsString('subject', strtolower($messages));
        $this->assertSame('draft', (string) $this->service()->find($id)['status'], 'It stays a draft');
    }

    public function testACompleteCampaignReachesReview(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $this->service()->submitForReview($id);

        $this->assertSame('pending_review', (string) $this->service()->find($id)['status']);
    }

    /**
     * The separation of duties. A MARKETING_MANAGER holds both create and approve,
     * so the permission matrix cannot enforce this — the service has to.
     */
    public function testAnAuthorCannotApproveTheirOwnCampaign(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $this->service()->submitForReview($id);

        $service = $this->service();

        $exception = $this->assertThrows(
            HttpException::class,
            static fn () => $service->approve($id)
        );

        $this->assertSame(403, $exception->statusCode());
        $this->assertContainsString('cannot approve it', $exception->getMessage());
        $this->assertSame('pending_review', (string) $this->service()->find($id)['status']);
    }

    public function testAColleagueCanApprove(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $this->service()->submitForReview($id);

        // A different person with approval permission.
        $approver = $this->createUserWithRole($context['organisation_id'], 'APPROVER');
        $this->actingAs($approver, $context['organisation_id']);

        $this->service()->approve($id);

        $campaign = $this->service()->find($id);

        $this->assertSame('approved', (string) $campaign['status']);
        $this->assertSame($approver, (int) $campaign['approved_by_user_id']);
        $this->assertNotNull($campaign['approved_at']);
    }

    public function testApprovalIsSkippedWhenTheOrganisationTurnsItOff(): void
    {
        $context = $this->sendableOrganisation();

        $this->connection->execute(
            'UPDATE organisations SET require_campaign_approval = 0 WHERE id = ?',
            [$context['organisation_id']]
        );
        $this->bindTenant($context['organisation_id']);

        $id = $this->completeCampaign($context);
        $this->service()->submitForReview($id);

        $campaign = $this->service()->find($id);

        $this->assertSame('approved', (string) $campaign['status'], 'A one-person business is not forced into review');
        $this->assertNull($campaign['approved_by_user_id'], 'And it is recorded as a policy decision, not a person');

        $audit = $this->connection->selectOne(
            "SELECT new_values FROM audit_logs WHERE action = 'campaign_approved' ORDER BY id DESC"
        );

        $this->assertContainsString('approval not required', (string) $audit['new_values']);
    }

    public function testEditingAnApprovedCampaignIsRefusedRatherThanSilentlyUnapproving(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $this->service()->submitForReview($id);

        $approver = $this->createUserWithRole($context['organisation_id'], 'APPROVER');
        $this->actingAs($approver, $context['organisation_id']);
        $this->service()->approve($id);

        $service = $this->service();

        // An approval that survives a rewrite is worthless.
        $exception = $this->assertThrows(
            ValidationException::class,
            static fn () => $service->update($id, ['subject' => 'Something completely different'])
        );

        $this->assertContainsString('invalidate that approval', implode(' ', $exception->firstErrors()));
        $this->assertSame('Your annual plumbing check is due', (string) $this->service()->find($id)['subject']);
    }

    public function testRequestingChangesReturnsItToTheAuthor(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $this->service()->submitForReview($id);

        $approver = $this->createUserWithRole($context['organisation_id'], 'APPROVER');
        $this->actingAs($approver, $context['organisation_id']);

        $this->service()->requestChanges($id, 'The offer expiry date is wrong.');

        $this->assertSame('draft', (string) $this->service()->find($id)['status']);

        $audit = $this->connection->selectOne(
            "SELECT new_values FROM audit_logs WHERE action = 'campaign_changes_requested' ORDER BY id DESC"
        );

        $this->assertContainsString('expiry date is wrong', (string) $audit['new_values']);
    }

    public function testOnlyAnApprovedCampaignCanBeScheduled(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $service = $this->service();

        $this->assertThrows(
            ValidationException::class,
            static fn () => $service->schedule($id, '2026-07-01 09:00:00'),
            'A draft cannot be scheduled'
        );

        $this->approveAsColleague($context, $id);

        $this->service()->schedule($id, '2026-07-01 09:00:00');

        $this->assertSame('scheduled', (string) $this->service()->find($id)['status']);
    }

    public function testScheduledTimesAreConvertedFromTheOrganisationTimezoneToUtc(): void
    {
        $context = $this->sendableOrganisation('AU', 'Australia/Perth');
        $id      = $this->completeCampaign($context);

        $this->approveAsColleague($context, $id);

        // 09:00 in Perth (UTC+8) is 01:00 UTC.
        $this->service()->schedule($id, '2026-07-01 09:00:00');

        $campaign = $this->service()->find($id);

        $this->assertSame('2026-07-01 01:00:00', (string) $campaign['scheduled_at'], 'Stored in UTC');
        $this->assertSame('Australia/Perth', (string) $campaign['timezone'], 'With the intent preserved');
    }

    public function testAPastScheduleIsRefused(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $this->approveAsColleague($context, $id);

        $service = $this->service();

        $this->assertThrows(
            ValidationException::class,
            static fn () => $service->schedule($id, '2020-01-01 09:00:00')
        );
    }

    /** Bulk email never leaves an HTTP request; "send now" means "queue now". */
    public function testSendNowSchedulesRatherThanSendingInline(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $this->approveAsColleague($context, $id);
        $this->service()->sendNow($id);

        $campaign = $this->service()->find($id);

        $this->assertSame('scheduled', (string) $campaign['status']);
        $this->assertSame($this->clock->nowString(), (string) $campaign['scheduled_at']);
    }

    public function testTwoApprovalsCannotBothWin(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $this->service()->submitForReview($id);

        $approver = $this->createUserWithRole($context['organisation_id'], 'APPROVER');
        $this->actingAs($approver, $context['organisation_id']);

        $this->service()->approve($id);

        // A second reviewer pressing the button on a stale page.
        $second = $this->createUserWithRole($context['organisation_id'], 'APPROVER');
        $this->actingAs($second, $context['organisation_id']);

        $service = $this->service();

        $this->assertThrows(
            ValidationException::class,
            static fn () => $service->approve($id),
            'The transition is conditional, so the second press cannot re-approve'
        );

        $this->assertSame($approver, (int) $this->service()->find($id)['approved_by_user_id']);
    }

    public function testPausingAndResumingASend(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $this->approveAsColleague($context, $id);
        $this->service()->sendNow($id);

        $this->service()->pause($id, 'Wrong offer code');

        $campaign = $this->service()->find($id);
        $this->assertSame('paused', (string) $campaign['status']);
        $this->assertSame('Wrong offer code', (string) $campaign['pause_reason']);

        $this->service()->resume($id);

        // No snapshot was built, so it goes back to scheduled rather than sending.
        $this->assertSame('scheduled', (string) $this->service()->find($id)['status']);
    }

    public function testTheAudienceReportsMatchingAndContactableSeparately(): void
    {
        $context = $this->sendableOrganisation('AU');

        $this->createContact(['email' => 'ok@example.com.au', 'country' => 'AU'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);
        $this->createContact(['email' => 'unknown@example.com.au', 'country' => 'AU']);
        $this->createContact(['email' => 'bounced@example.com.au', 'country' => 'AU'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var \App\Services\SuppressionService $suppressions */
        $suppressions = $this->container->make(\App\Services\SuppressionService::class);
        $suppressions->suppressForHardBounce('bounced@example.com.au', 'ses');

        /** @var \App\Services\SegmentService $segments */
        $segments  = $this->container->make(\App\Services\SegmentService::class);
        $segmentId = $segments->create('Australians', [
            'match' => 'all',
            'rules' => [['field' => 'country', 'operator' => 'equals', 'value' => 'AU']],
        ]);

        $id = $this->service()->create([
            'name'          => 'AU campaign',
            'campaign_type' => 'newsletter',
            'segment_id'    => $segmentId,
        ]);

        $audience = $this->service()->audience($id);

        $this->assertSame(3, $audience['total']);
        $this->assertSame(1, $audience['eligible'], 'Only one can actually be emailed');
        $this->assertSame(1, $audience['suppressed']);
        $this->assertSame(1, $audience['no_consent']);
        $this->assertContainsString('Australians', $audience['description']);
    }

    public function testAListAudienceGetsTheSameEligibilityTreatmentAsASegment(): void
    {
        $context = $this->sendableOrganisation('AU');

        /** @var \App\Repositories\ListRepository $lists */
        $lists  = $this->container->make(\App\Repositories\ListRepository::class);
        $listId = $lists->create('Newsletter');

        $consented = $this->createContact(['email' => 'yes@example.com.au', 'country' => 'AU'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);
        $unknown = $this->createContact(['email' => 'no@example.com.au', 'country' => 'AU']);

        $lists->addContact($listId, $consented);
        $lists->addContact($listId, $unknown);

        $id = $this->service()->create([
            'name'          => 'List campaign',
            'campaign_type' => 'newsletter',
            'list_id'       => $listId,
        ]);

        $audience = $this->service()->audience($id);

        $this->assertSame(2, $audience['total']);
        $this->assertSame(1, $audience['eligible'], 'Being on a list is not consent');
        $this->assertSame(1, $audience['no_consent']);
    }

    public function testApplyingATemplateCopiesTheContent(): void
    {
        $context = $this->sendableOrganisation();

        /** @var \App\Services\TemplateService $templates */
        $templates  = $this->container->make(\App\Services\TemplateService::class);
        $templateId = $templates->create('Reminder', [
            ['type' => 'heading', 'settings' => ['text' => 'Original heading']],
            ['type' => 'footer',  'settings' => []],
        ]);

        $id = $this->service()->create(['name' => 'Uses template', 'campaign_type' => 'service']);
        $this->service()->applyTemplate($id, $templateId);

        $this->assertContainsString('Original heading', (string) $this->service()->find($id)['html_content']);

        // Changing the template afterwards must not reach the campaign, or an
        // approved campaign could change under the approver's feet.
        $templates->update($templateId, [], [
            ['type' => 'heading', 'settings' => ['text' => 'Rewritten heading']],
            ['type' => 'footer',  'settings' => []],
        ]);

        $html = (string) $this->service()->find($id)['html_content'];

        $this->assertContainsString('Original heading', $html);
        $this->assertNotContainsString('Rewritten heading', $html);
    }

    public function testACampaignInFlightCannotBeDeleted(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        /** @var CampaignRepository $repository */
        $repository = $this->container->make(CampaignRepository::class);
        $repository->update($id, ['status' => 'sending']);

        $service = $this->service();

        $this->assertThrows(ValidationException::class, static fn () => $service->delete($id));
    }

    public function testCampaignsAreScopedToTheirOrganisation(): void
    {
        $contextA = $this->sendableOrganisation();
        $id       = $this->completeCampaign($contextA);

        $orgB = $this->createOrganisation();
        $this->actingAs($orgB['user_id'], $orgB['organisation_id']);

        $service = $this->service();

        $this->assertThrows(HttpException::class, static fn () => $service->find($id));
    }

    public function testTheWholeWorkflowIsAudited(): void
    {
        $context = $this->sendableOrganisation();
        $id      = $this->completeCampaign($context);

        $this->service()->submitForReview($id);
        $this->approveAsColleague($context, $id);
        $this->service()->schedule($id, '2026-07-01 09:00:00');

        $actions = array_column(
            $this->connection->select(
                "SELECT action FROM audit_logs WHERE entity_type = 'campaign' AND entity_id = ? ORDER BY id",
                [$id]
            ),
            'action'
        );

        foreach ([
            'campaign_created',
            'campaign_submitted_for_review',
            'campaign_approved',
            'campaign_scheduled',
        ] as $action) {
            $this->assertTrue(in_array($action, $actions, true), $action . ' is audited');
        }
    }

    // ---------------------------------------------------------------- helpers

    private function service(): CampaignService
    {
        return $this->container->make(CampaignService::class);
    }

    /**
     * An organisation configured well enough that a campaign can actually pass
     * validation: postal address, sender identity and a verified domain.
     *
     * @return array{organisation_id:int,user_id:int}
     */
    private function sendableOrganisation(string $country = 'US', string $timezone = 'UTC'): array
    {
        $org = $this->createOrganisation([
            'name'     => 'Perth Plumbing Co',
            'country'  => $country,
            'timezone' => $timezone,
        ]);

        $this->connection->execute(
            'UPDATE organisations SET address_line1 = ?, address_city = ?, address_state = ?, address_postcode = ?,
                    address_country = ?, contact_phone = ?, contact_email = ?,
                    default_sender_name = ?, default_sender_email = ?, timezone = ?, daily_send_limit = 100000
             WHERE id = ?',
            [
                '12 Example Street', 'Perth', 'WA', '6000', $country,
                '+61 8 5550 1000', 'hello@perthplumbing.test',
                'Perth Plumbing Co', 'hello@perthplumbing.test', $timezone,
                $org['organisation_id'],
            ]
        );

        $this->connection->table('sending_domains')->insert([
            'organisation_id' => $org['organisation_id'],
            'domain'          => 'perthplumbing.test',
            'status'          => 'verified',
            'dkim_status'     => 'verified',
            'provider'        => 'log',
            'created_at'      => $this->clock->nowString(),
            'updated_at'      => $this->clock->nowString(),
        ]);

        $this->actingAs($org['user_id'], $org['organisation_id']);

        return ['organisation_id' => $org['organisation_id'], 'user_id' => $org['user_id']];
    }

    /** @param array{organisation_id:int,user_id:int} $context */
    private function completeCampaign(array $context): int
    {
        $this->createContact(['email' => 'recipient@example.com', 'country' => 'US'], [
            'status' => 'granted', 'consent_type' => 'express',
        ]);

        /** @var \App\Services\SegmentService $segments */
        $segments  = $this->container->make(\App\Services\SegmentService::class);
        $segmentId = $segments->create('Everyone', [
            'match' => 'all',
            'rules' => [['field' => 'email', 'operator' => 'contains', 'value' => '@']],
        ]);

        $id = $this->service()->create([
            'name'          => 'Autumn service reminder',
            'subject'       => 'Your annual plumbing check is due',
            'campaign_type' => 'service',
            'segment_id'    => $segmentId,
        ]);

        $this->service()->update($id, [
            'html_content' => '<p>Hello {{first_name}}, book your check.</p>'
                . '<p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
        ]);

        return $id;
    }

    /** @param array{organisation_id:int,user_id:int} $context */
    private function approveAsColleague(array $context, int $id): void
    {
        $status = (string) $this->service()->find($id)['status'];

        if ($status === 'draft') {
            $this->service()->submitForReview($id);
        }

        if ((string) $this->service()->find($id)['status'] === 'approved') {
            $this->actingAs($context['user_id'], $context['organisation_id']);

            return;
        }

        $approver = $this->createUserWithRole($context['organisation_id'], 'ADMIN');
        $this->actingAs($approver, $context['organisation_id']);
        $this->service()->approve($id);

        // Back to the author for whatever the test does next.
        $this->actingAs($context['user_id'], $context['organisation_id']);
    }
}
