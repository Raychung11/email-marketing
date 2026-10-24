<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendCampaignEmail;
use App\Services\SendDiagnostics;
use Tests\Support\TestCase;

/**
 * The reconciliation report.
 *
 * Two things are being proved here. One, that each finding actually fires on
 * the state it describes — a diagnostic that silently reports nothing is worse
 * than none, because it is trusted. Two, that it changes nothing and leaks
 * nothing: every state it reports on is one where sending again could put a
 * second copy of an email in somebody's inbox, so the decision belongs to a
 * person, and the addresses involved belong to a tenant rather than to whoever
 * is reading a terminal.
 */
final class SendDiagnosticsTest extends TestCase
{
    public function testACleanPipelineReportsNothing(): void
    {
        $this->createOrganisation();

        $report = $this->doctor()->report();

        $this->assertSame([], $report['findings'], 'No findings when there is nothing wrong');
        $this->assertSame(0, $report['queue']['recipients_queued']);
    }

    public function testARecipientMarkedSentWithNoMessageIsReported(): void
    {
        $context = $this->seedCampaign();

        $this->setRecipient($context['recipient_id'], [
            'send_status'      => 'sent',
            'email_message_id' => null,
            'sent_at'          => $this->clock->nowString(),
        ]);

        $finding = $this->finding('sent_without_a_message');

        $this->assertNotNull($finding, 'The old bug\'s output is found');
        $this->assertSame(1, $finding['total']);
    }

    public function testASendWithAnUnknownOutcomeIsReportedForAPersonToSettle(): void
    {
        $context = $this->seedCampaign();

        $this->setRecipient($context['recipient_id'], [
            'send_status'           => 'outcome_unknown',
            'provider_attempted_at' => $this->clock->nowString(),
            'last_error'            => 'Connection reset',
        ]);

        $finding = $this->finding('awaiting_reconciliation');

        $this->assertNotNull($finding);
        $this->assertSame(1, $finding['total']);
        $this->assertContainsString('provider', strtolower($finding['guidance']));
    }

    public function testALapsedLeaseIsReported(): void
    {
        $context = $this->seedCampaign();

        $this->setRecipient($context['recipient_id'], [
            'send_status'      => 'sending',
            'claimed_at'       => '2026-06-15 11:00:00',
            'lease_expires_at' => '2026-06-15 11:05:00',
        ]);

        $this->assertNotNull($this->finding('lapsed_leases'), 'Frozen clock is 12:00, the lease died at 11:05');
    }

    public function testALeaseStillRunningIsNotReported(): void
    {
        $context = $this->seedCampaign();

        $this->setRecipient($context['recipient_id'], [
            'send_status'      => 'sending',
            'claimed_at'       => $this->clock->nowString(),
            'lease_expires_at' => '2026-06-15 12:05:00',
        ]);

        $this->assertNull($this->finding('lapsed_leases'), 'A worker that is still working is not a fault');
    }

    public function testQueuedWorkOnACampaignThatIsNotSendingIsReported(): void
    {
        $context = $this->seedCampaign();

        $this->setRecipient($context['recipient_id'], ['send_status' => 'queued']);
        $this->connection->execute(
            "UPDATE campaigns SET status = 'paused' WHERE id = ?",
            [$context['campaign_id']]
        );

        $finding = $this->finding('orphaned_commitments');

        $this->assertNotNull($finding, 'Nothing will ever pick these up');
        $this->assertSame('paused', (string) $finding['rows'][0]['campaign_status']);
    }

    public function testARecipientPastTheAttemptCeilingIsReported(): void
    {
        $context = $this->seedCampaign();

        $this->setRecipient($context['recipient_id'], [
            'send_status'   => 'pending',
            'attempt_count' => 9,
            'last_error'    => 'Throttled by provider',
        ]);

        $this->assertNotNull($this->finding('past_the_attempt_ceiling'));
    }

    public function testAFailedJobWhoseRecipientStillLooksLiveIsReported(): void
    {
        $context = $this->seedCampaign();

        $this->setRecipient($context['recipient_id'], ['send_status' => 'sending']);

        $this->connection->table('failed_jobs')->insert([
            'queue'           => 'email_marketing',
            'organisation_id' => $context['organisation_id'],
            'job_class'       => SendCampaignEmail::class,
            'payload'         => (string) json_encode([
                'organisation_id' => $context['organisation_id'],
                'campaign_id'     => $context['campaign_id'],
                'recipient_id'    => $context['recipient_id'],
            ]),
            'attempt_count'   => 4,
            'exception'       => 'RuntimeException: provider unreachable',
            'permanent'       => 0,
            'failed_at'       => $this->clock->nowString(),
        ]);

        $finding = $this->finding('abandoned_jobs');

        $this->assertNotNull($finding, 'The job gave up but the row still looks live');
        $this->assertSame('sending', (string) $finding['rows'][0]['send_status']);
    }

    public function testAStalledCampaignIsReported(): void
    {
        $context = $this->seedCampaign();

        // Nothing ready: the only outstanding row is one nobody can resolve.
        $this->setRecipient($context['recipient_id'], [
            'send_status'           => 'outcome_unknown',
            'provider_attempted_at' => $this->clock->nowString(),
        ]);

        $finding = $this->finding('stalled_campaigns');

        $this->assertNotNull($finding);
        $this->assertSame(1, (int) $finding['rows'][0]['unknown']);
        $this->assertSame(0, (int) $finding['rows'][0]['ready']);
    }

    public function testACampaignWithWorkReadyToGoIsNotCalledStalled(): void
    {
        $context = $this->seedCampaign();

        $this->setRecipient($context['recipient_id'], ['send_status' => 'pending', 'retry_after' => null]);

        $this->assertNull(
            $this->finding('stalled_campaigns'),
            'Waiting for the next tick is not the same as stalled'
        );
    }

    // ------------------------------------------------- what it must not do

    public function testEmailAddressesAreMasked(): void
    {
        $context = $this->seedCampaign('someone.identifiable@example.com');

        $this->setRecipient($context['recipient_id'], [
            'send_status'      => 'sent',
            'email_message_id' => null,
        ]);

        $finding = $this->finding('sent_without_a_message');

        $this->assertNotNull($finding);
        $this->assertSame('s*******************@example.com', (string) $finding['rows'][0]['email']);
        $this->assertNotContainsString(
            'someone.identifiable@example.com',
            (string) json_encode($finding),
            'The address does not reach the output in any form'
        );
        $this->assertSame(
            $context['recipient_id'],
            (int) $finding['rows'][0]['id'],
            'The id is printed instead, so an authorised person can look it up properly'
        );
    }

    public function testTheReportChangesNothing(): void
    {
        $context = $this->seedCampaign();

        $this->setRecipient($context['recipient_id'], [
            'send_status'           => 'outcome_unknown',
            'provider_attempted_at' => $this->clock->nowString(),
            'attempt_count'         => 2,
        ]);

        $before = $this->connection->select('SELECT * FROM campaign_recipients ORDER BY id');
        $this->doctor()->report();
        $this->doctor()->report();
        $after = $this->connection->select('SELECT * FROM campaign_recipients ORDER BY id');

        $this->assertSame(
            json_encode($before),
            json_encode($after),
            'Running it twice leaves every row exactly as it was'
        );
    }

    // ----------------------------------------------------------- internals

    private function doctor(): SendDiagnostics
    {
        return $this->container->make(SendDiagnostics::class);
    }

    /** @return array<string,mixed>|null */
    private function finding(string $key): ?array
    {
        foreach ($this->doctor()->report()['findings'] as $finding) {
            if ($finding['key'] === $key) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * A campaign that is sending, with exactly one eligible recipient for the
     * test to put into whatever state it is about.
     *
     * @return array{organisation_id:int,campaign_id:int,recipient_id:int}
     */
    private function seedCampaign(string $email = 'recipient@example.com'): array
    {
        $org = $this->createOrganisation();

        $campaignId = $this->connection->table('campaigns')->insert([
            'organisation_id'       => $org['organisation_id'],
            'uuid'                  => uuid4(),
            'name'                  => 'Report fixture',
            'subject'               => 'Report fixture',
            'status'                => 'sending',
            'send_started_at'       => $this->clock->nowString(),
            'snapshot_completed_at' => $this->clock->nowString(),
            'created_at'            => $this->clock->nowString(),
            'updated_at'            => $this->clock->nowString(),
        ]);

        $contactId = $this->createContact(
            ['email' => $email],
            ['status' => 'granted', 'consent_type' => 'express']
        );

        $recipientId = $this->connection->table('campaign_recipients')->insert([
            'organisation_id'    => $org['organisation_id'],
            'campaign_id'        => $campaignId,
            'contact_id'         => $contactId,
            'email'              => $email,
            'email_normalized'   => normalize_email($email),
            'eligibility_status' => 'eligible',
            'send_status'        => 'pending',
            'attempt_count'      => 0,
            'created_at'         => $this->clock->nowString(),
        ]);

        return [
            'organisation_id' => (int) $org['organisation_id'],
            'campaign_id'     => $campaignId,
            'recipient_id'    => $recipientId,
        ];
    }

    /** @param array<string,mixed> $attributes */
    private function setRecipient(int $recipientId, array $attributes): void
    {
        $this->connection->table('campaign_recipients')
            ->where('id', '=', $recipientId)
            ->update($attributes);
    }
}
