<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Compliance\ComplianceService;
use App\Compliance\ReasonCode;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Logger;
use App\Mail\EmailProviderInterface;
use App\Mail\EmailRenderer;
use App\Mail\OutboundMessage;
use App\Queue\PermanentFailure;
use App\Queue\Queueable;
use App\Repositories\CampaignRecipientRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\ContactRepository;
use App\Repositories\EmailMessageRepository;
use App\Repositories\OrganisationRepository;
use App\Services\ActivityService;
use App\Services\LinkTracker;
use App\Support\TenantContext;

/**
 * Send one campaign email.
 *
 * One recipient per job. That is more queue traffic than batching, and it is
 * worth it: each message gets its own compliance decision, its own unsubscribe
 * token, its own tracking, and its own retry. A batch that fails halfway is a
 * much worse problem than a few thousand extra queue entries.
 *
 * THE SECOND COMPLIANCE CHECK LIVES HERE. The snapshot recorded eligibility when
 * the campaign started; minutes or hours may have passed. In that time a contact
 * can unsubscribe, bounce or complain. The preview never authorises a send — this
 * does, immediately before the provider call.
 */
final class SendCampaignEmail implements Queueable
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly CampaignRecipientRepository $recipients,
        private readonly ContactRepository $contacts,
        private readonly EmailMessageRepository $messages,
        private readonly OrganisationRepository $organisations,
        private readonly ComplianceService $compliance,
        private readonly EmailRenderer $renderer,
        private readonly LinkTracker $links,
        private readonly EmailProviderInterface $provider,
        private readonly ActivityService $activity,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /** @param array{organisation_id:int,campaign_id:int,recipient_id:int} $payload */
    public function handle(array $payload): void
    {
        $organisationId = (int) ($payload['organisation_id'] ?? 0);
        $campaignId     = (int) ($payload['campaign_id'] ?? 0);
        $recipientId    = (int) ($payload['recipient_id'] ?? 0);

        if ($organisationId <= 0 || $campaignId <= 0 || $recipientId <= 0) {
            throw new PermanentFailure('Malformed send payload.');
        }

        $organisation = $this->organisations->findById($organisationId);

        if ($organisation === null) {
            throw new PermanentFailure('Organisation ' . $organisationId . ' no longer exists.');
        }

        // The worker runs outside a request, so bind the tenant explicitly and
        // keep every repository call organisation-scoped exactly as in the app.
        // Whatever was bound before is restored afterwards, so a worker that runs
        // several tenants' jobs in one process never leaks one into the next.
        $captured = $this->tenant->capture();

        $this->tenant->clear();
        $this->tenant->bind($organisationId, null, $organisation);

        try {
            $this->send($organisation, $campaignId, $recipientId);
        } finally {
            $this->tenant->restore($captured);
        }
    }

    /** @param array<string,mixed> $organisation */
    private function send(array $organisation, int $campaignId, int $recipientId): void
    {
        $campaign = $this->campaigns->find($campaignId);

        if ($campaign === null) {
            throw new PermanentFailure('Campaign ' . $campaignId . ' no longer exists.');
        }

        // Somebody hit pause, or the platform paused it. Stop before the provider
        // call — that is the whole point of the button.
        if (in_array((string) $campaign['status'], ['paused', 'cancelled'], true)) {
            $this->logger->info('Skipping send: campaign is no longer active', [
                'campaign' => $campaignId,
                'status'   => $campaign['status'],
            ]);

            return;
        }

        $recipient = $this->recipients->findForCampaign($campaignId, $recipientId);

        if ($recipient === null) {
            throw new PermanentFailure('Recipient ' . $recipientId . ' is not part of campaign ' . $campaignId . '.');
        }

        // Claim it. A redelivered job, or one whose worker died after sending but
        // before acknowledging, claims nothing and sends nothing.
        if (!$this->recipients->claimForSending($recipientId)) {
            $this->logger->info('Skipping send: recipient already claimed', ['recipient' => $recipientId]);

            return;
        }

        $contact = $this->contacts->find((int) $recipient['contact_id']);

        if ($contact === null) {
            $this->recipients->markSkipped($recipientId, 'blocked', 'CONTACT_DELETED');

            return;
        }

        // ---- THE SECOND COMPLIANCE CHECK ---------------------------------
        $decision = $this->compliance->canSendMarketingEmail($organisation, $contact, $campaign);

        if ($decision->blocked()) {
            $this->recipients->markSkipped(
                $recipientId,
                ReasonCode::bucket($decision->reason),
                $decision->reason
            );

            $this->logger->info('Send blocked at queue time', [
                'campaign' => $campaignId,
                'reason'   => $decision->reason,
            ]);

            return;
        }

        $uuid = uuid4();

        // Per-recipient rendering: their merge fields, their unsubscribe token,
        // their tracked links.
        $rendered = $this->renderer->render(
            (string) $campaign['html_content'],
            (string) ($campaign['text_content'] ?? ''),
            $organisation,
            $contact,
            $campaignId,
            null,
            'marketing'
        );

        $messageId = $this->messages->create([
            'uuid'                => $uuid,
            'campaign_id'         => $campaignId,
            'contact_id'          => (int) $contact['id'],
            'message_class'       => 'marketing',
            'provider'            => $this->provider->name(),
            'email'               => (string) $contact['email'],
            'subject'             => (string) $campaign['subject'],
            'from_email'          => (string) $campaign['from_email'],
            'status'              => 'sending',
            'queued_at'           => (string) ($recipient['queued_at'] ?? $this->clock->nowString()),
            'created_at'          => $this->clock->nowString(),
        ]);

        $this->recipients->markSent($recipientId, $messageId);

        // Link rewriting happens after the message row exists, because a tracked
        // link has to carry the message identity.
        $html = $this->links->rewrite(
            $rendered['html'],
            $campaign,
            (int) $contact['id'],
            $messageId
        );

        $text = $rendered['text'];

        $message = new OutboundMessage(
            toEmail: (string) $contact['email'],
            subject: (string) $campaign['subject'],
            htmlBody: $html,
            textBody: $text,
            fromEmail: (string) $campaign['from_email'],
            fromName: (string) $campaign['from_name'],
            replyTo: (string) ($campaign['reply_to'] ?? ''),
            toName: trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? '')) ?: null,
            headers: [
                // RFC 8058: a one-click unsubscribe the mail client can offer in
                // its own UI. Recipients who can unsubscribe easily do that
                // instead of pressing "spam", which is what protects the sending
                // reputation of everyone on the platform.
                'List-Unsubscribe'      => '<' . $rendered['unsubscribe_url'] . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                'List-Id'               => '<campaign-' . $campaignId . '.' . $this->hostFrom((string) $campaign['from_email']) . '>',
                'X-Message-Id'          => $uuid,
                'Precedence'            => 'bulk',
            ],
            tags: [
                'campaign_id'     => (string) $campaignId,
                'organisation_id' => (string) $organisation['id'],
                'message_class'   => 'marketing',
            ],
            messageClass: 'marketing',
            configurationSet: (string) $this->config->get('mail.providers.ses.configuration_set', '') ?: null,
            messageUuid: $uuid,
        );

        $result = $this->provider->send($message);

        if ($result->accepted) {
            $this->messages->update($messageId, [
                'provider_message_id' => $result->providerMessageId,
                'status'              => 'sent',
                'sent_at'             => $this->clock->nowString(),
            ]);

            $this->contacts->touchEngagement((int) $contact['id'], 'sent');

            $this->activity->record(
                'email_sent',
                (int) $contact['id'],
                'Sent "' . (string) $campaign['name'] . '"',
                ['campaign_id' => $campaignId, 'message_id' => $messageId],
                'campaign',
                $campaignId
            );

            return;
        }

        // Either way this handoff failed, so the row is closed as failed rather
        // than left 'queued'. A retry creates its own row: leaving this one open
        // would make the campaign look like it sent a message it never did, and
        // nothing would ever come along to correct it.
        $this->messages->update($messageId, [
            'status'         => 'failed',
            'failed_at'      => $this->clock->nowString(),
            'failure_reason' => $result->error === null ? null : substr($result->error, 0, 255),
        ]);

        if (!$result->retryable) {
            // A rejection is the provider saying this will never work. Retrying
            // burns sending reputation for nothing.
            $this->recipients->markFailed($recipientId, (string) $result->error);

            throw new PermanentFailure(
                'Provider rejected the message: ' . (string) $result->error
            );
        }

        // Transient: put the recipient back so the retry can claim it again.
        $this->recipients->release($recipientId);

        throw new \RuntimeException('Provider temporarily unavailable: ' . (string) $result->error);
    }

    private function hostFrom(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? 'localhost' : substr($email, $at + 1);
    }
}
