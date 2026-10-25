<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mail\EmailProviderInterface;
use App\Mail\LogEmailProvider;
use App\Mail\SendResult;
use App\Queue\QueueDriver;
use App\Repositories\CampaignRecipientRepository;
use App\Services\CampaignDispatcher;
use App\Services\CampaignService;
use App\Support\DnsResolver;
use App\Support\FakeDnsResolver;

/**
 * Setting up a campaign that is genuinely ready to send.
 *
 * Shared because the arrangement is long and the guarantees under test are not
 * about the arrangement: a verified sending domain, a postal address, a sender
 * identity, consented contacts and an approved campaign. Getting any of those
 * wrong makes a test pass for the wrong reason — the compliance checks would
 * block the send before the code under test ever ran.
 */
trait SendsCampaigns
{
    protected function useFakeDns(): void
    {
        $this->container->instance(DnsResolver::class, new FakeDnsResolver());
    }

    /**
     * An organisation that can legitimately send.
     *
     * @return array{organisation_id:int,user_id:int}
     */
    protected function sendableOrganisation(string $name = 'Perth Plumbing Co', string $timezone = 'UTC'): array
    {
        $org = $this->createOrganisation(['name' => $name, 'country' => 'US', 'timezone' => $timezone]);

        $this->connection->execute(
            'UPDATE organisations SET address_line1 = ?, address_city = ?, address_state = ?, address_postcode = ?,
                    address_country = ?, contact_phone = ?, contact_email = ?,
                    default_sender_name = ?, default_sender_email = ?, daily_send_limit = 100000
             WHERE id = ?',
            [
                '12 Example Street', 'Perth', 'WA', '6000', 'US',
                '+61 8 5550 1000', 'hello@perthplumbing.test',
                'Perth Plumbing Co', 'hello@perthplumbing.test',
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

    /**
     * A campaign approved, scheduled and waiting for the scheduler.
     *
     * $context is needed because approval is a separate role from authoring:
     * the author cannot approve their own campaign, so the helper borrows an
     * administrator for that one step and hands the session back.
     *
     * @param array{organisation_id:int,user_id:int} $context
     */
    protected function readyToSendCampaign(array $context, int $recipients, string $name = 'Autumn service reminder'): int
    {
        for ($i = 1; $i <= $recipients; $i++) {
            $this->createContact(
                ['email' => 'recipient-' . $i . '@example.com', 'first_name' => 'Sam', 'country' => 'US'],
                ['status' => 'granted', 'consent_type' => 'express']
            );
        }

        return $this->campaignOverEveryone($context, $name);
    }

    /**
     * A second campaign over the same audience, for budget-sharing tests.
     *
     * @param array{organisation_id:int,user_id:int} $context
     */
    protected function campaignOverEveryone(array $context, string $name): int
    {
        /** @var \App\Services\SegmentService $segments */
        $segments  = $this->container->make(\App\Services\SegmentService::class);
        $segmentId = $segments->create('Everyone ' . bin2hex(random_bytes(3)), [
            'match' => 'all',
            'rules' => [['field' => 'email', 'operator' => 'contains', 'value' => '@']],
        ]);

        $id = $this->campaignService()->create([
            'name'          => $name,
            'subject'       => 'Your annual plumbing check is due',
            'campaign_type' => 'service',
            'segment_id'    => $segmentId,
        ]);

        $this->campaignService()->update($id, [
            'html_content' => '<p>Hello {{first_name}}, book your check at '
                . '<a href="https://perthplumbing.test/book">our site</a>.</p>'
                . '<p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
        ]);

        $this->campaignService()->submitForReview($id);

        if ((string) $this->campaignRow($id)['status'] === 'pending_review') {
            $approver = $this->createUserWithRole($context['organisation_id'], 'ADMIN');
            $this->actingAs($approver, $context['organisation_id']);
            $this->campaignService()->approve($id);
            $this->actingAs($context['user_id'], $context['organisation_id']);
        }

        $this->campaignService()->sendNow($id);

        return $id;
    }

    protected function campaignService(): CampaignService
    {
        return $this->container->make(CampaignService::class);
    }

    protected function dispatcher(): CampaignDispatcher
    {
        return $this->container->make(CampaignDispatcher::class);
    }

    protected function recipientRepository(): CampaignRecipientRepository
    {
        return $this->container->make(CampaignRecipientRepository::class);
    }

    protected function queueDriver(): QueueDriver
    {
        return $this->container->make(QueueDriver::class);
    }

    /** @return array<string,mixed> */
    protected function campaignRow(int $id): array
    {
        return $this->connection->selectOne('SELECT * FROM campaigns WHERE id = ?', [$id]) ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    protected function snapshotRows(int $campaignId): array
    {
        return $this->connection->select(
            'SELECT * FROM campaign_recipients WHERE campaign_id = ? ORDER BY id',
            [$campaignId]
        );
    }

    /** @return array<string,mixed> */
    protected function recipientRow(int $recipientId): array
    {
        return $this->connection->selectOne(
            'SELECT * FROM campaign_recipients WHERE id = ?',
            [$recipientId]
        ) ?? [];
    }

    protected function logProvider(): LogEmailProvider
    {
        /** @var LogEmailProvider $provider */
        $provider = $this->container->make(EmailProviderInterface::class);

        return $provider;
    }

    protected function scriptProvider(SendResult ...$results): ScriptedEmailProvider
    {
        $provider = new ScriptedEmailProvider();
        $provider->script(...$results);

        $this->container->instance(EmailProviderInterface::class, $provider);

        return $provider;
    }
}
