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

    /**
     * One attempt at one recipient.
     *
     * The whole method is organised around a single question: at the moment
     * something goes wrong, do we know whether the provider was asked to send
     * this email? Everything before `markProviderAttempt()` is safe to retry
     * because nothing left the building. Everything after it is not, and a row
     * that reaches that point without a definite answer is parked for a person
     * rather than retried, because the alternative is mailing a customer twice.
     *
     * @param array<string,mixed> $organisation
     */
    private function send(array $organisation, int $campaignId, int $recipientId): void
    {
        $campaign = $this->campaigns->find($campaignId);

        if ($campaign === null) {
            throw new PermanentFailure('Campaign ' . $campaignId . ' no longer exists.');
        }

        if ($this->recipients->findForCampaign($campaignId, $recipientId) === null) {
            throw new PermanentFailure('Recipient ' . $recipientId . ' is not part of campaign ' . $campaignId . '.');
        }

        // Cheap pre-check. The authoritative one happens after the claim, since
        // somebody can press pause in between, but there is no reason to take a
        // claim out on a campaign that is already stopped.
        if ($this->closeIfNotActive($campaign, $recipientId, null)) {
            return;
        }

        $token = bin2hex(random_bytes(16));
        $lease = (int) $this->config->get('queue.claim_lease_seconds', 300);

        $recipient = $this->recipients->claim($recipientId, $token, $lease);

        if ($recipient === null) {
            // Somebody else owns this row: a redelivered job, a duplicate
            // dispatch, or a worker that is still inside the provider call.
            // Doing nothing is the entire point.
            $this->logger->info('Skipping send: recipient is claimed elsewhere', [
                'recipient' => $recipientId,
            ]);

            return;
        }

        $attempted = false;

        try {
            // Re-read under the claim. The pause could have landed between the
            // check above and the UPDATE that took this row.
            $campaign = $this->campaigns->find($campaignId) ?? $campaign;

            if ($this->closeIfNotActive($campaign, $recipientId, $token)) {
                return;
            }

            $contact = $this->contacts->find((int) $recipient['contact_id']);

            if ($contact === null) {
                $this->recipients->markSkipped($recipientId, 'blocked', 'CONTACT_DELETED', $token);

                return;
            }

            // ---- THE SECOND COMPLIANCE CHECK ------------------------------
            $decision = $this->compliance->canSendMarketingEmail($organisation, $contact, $campaign);

            if ($decision->blocked()) {
                $this->recipients->markSkipped(
                    $recipientId,
                    ReasonCode::bucket($decision->reason),
                    $decision->reason,
                    $token
                );

                $this->logger->info('Send blocked at queue time', [
                    'campaign' => $campaignId,
                    'reason'   => $decision->reason,
                ]);

                return;
            }

            $uuid = uuid4();

            // Per-recipient rendering: their merge fields, their unsubscribe
            // token, their tracked links.
            $rendered = $this->renderer->render(
                (string) $campaign['html_content'],
                (string) ($campaign['text_content'] ?? ''),
                $organisation,
                $contact,
                $campaignId,
                null,
                'marketing'
            );

            // The message row exists before the provider call because a tracked
            // link has to carry the message identity. It is created as `sending`
            // and only becomes `sent` once the provider has said yes.
            $messageId = $this->messages->create([
                'uuid'          => $uuid,
                'campaign_id'   => $campaignId,
                'contact_id'    => (int) $contact['id'],
                'message_class' => 'marketing',
                'provider'      => $this->provider->name(),
                'email'         => (string) $contact['email'],
                'subject'       => (string) $campaign['subject'],
                'from_email'    => (string) $campaign['from_email'],
                'status'        => 'sending',
                'queued_at'     => (string) ($recipient['queued_at'] ?? $this->clock->nowString()),
                'created_at'    => $this->clock->nowString(),
            ]);

            $message = $this->outboundMessage(
                $campaign,
                $organisation,
                $contact,
                $rendered,
                $messageId,
                $uuid,
                $campaignId
            );

            // The line either side of which everything changes. Nothing above it
            // has reached the provider; nothing below it can assume it has not.
            $this->recipients->markProviderAttempt($recipientId, $token);
            $attempted = true;

            $result = $this->provider->send($message);
        } catch (PermanentFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($attempted) {
                // A request went out and we never learned what became of it —
                // a timeout, a reset, a worker killed mid-call. Retrying could
                // send the same customer a second copy, so this is parked.
                $this->park($recipientId, $token, $campaignId, $e);
            }

            // Nothing was sent. Hand the row straight back so the retry can pick
            // it up, and let the worker apply its backoff.
            $this->recipients->release($recipientId, $token, $this->backoffUntil($recipient));

            throw $e;
        }

        if ($result->accepted) {
            try {
                $this->messages->update($messageId, [
                    'provider_message_id' => $result->providerMessageId,
                    'status'              => 'sent',
                    'sent_at'             => $this->clock->nowString(),
                ]);

                $this->recipients->markSent($recipientId, $token, $messageId);
            } catch (\Throwable $e) {
                // The provider accepted it and we failed to write that down.
                // The email is gone; the only wrong answer here is to send it
                // again, so the row is parked rather than retried.
                $this->park($recipientId, $token, $campaignId, $e);
            }

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

        // The provider answered, and the answer was no. That is a definite
        // outcome: whatever happens next, this message was not accepted.
        $this->messages->update($messageId, [
            'status'         => 'failed',
            'failed_at'      => $this->clock->nowString(),
            'failure_reason' => $result->error === null ? null : substr($result->error, 0, 255),
        ]);

        if (!$result->retryable) {
            // A rejection is the provider saying this will never work. Retrying
            // burns sending reputation for nothing.
            $this->recipients->markFailed($recipientId, (string) $result->error, $token);

            throw new PermanentFailure('Provider rejected the message: ' . (string) $result->error);
        }

        // Transient — but only up to a point. A recipient released back into the
        // pool is picked up by the next scheduler tick, which is a different
        // path from the queue's own retry, and nothing in the queue's attempt
        // limit reaches it. Without this check the two together make an
        // unbounded loop: the job dead-letters after four tries and the
        // scheduler cheerfully enqueues a fifth, a sixth, and so on for as long
        // as the provider keeps saying no.
        if ((int) ($recipient['attempt_count'] ?? 1) >= $this->maxAttempts()) {
            $this->recipients->markFailed(
                $recipientId,
                'Gave up after ' . $this->maxAttempts() . ' attempts: ' . (string) $result->error,
                $token
            );

            throw new PermanentFailure(
                'Provider unavailable after ' . $this->maxAttempts() . ' attempts: ' . (string) $result->error
            );
        }

        // Back in the pool, but not before the backoff the worker is about to
        // apply — otherwise the next scheduler tick would enqueue it again
        // straight away and the delay would mean nothing.
        $this->recipients->release($recipientId, $token, $this->backoffUntil($recipient));

        throw new \RuntimeException('Provider temporarily unavailable: ' . (string) $result->error);
    }

    /**
     * Stop working on a recipient whose campaign is no longer going out.
     *
     * Pause and cancel are deliberately different. A paused campaign will resume,
     * so its recipients go back to `pending` — still owed, ready to be picked up
     * again, and costing the job no retry budget because it has not failed. A
     * cancelled campaign will not resume, so its recipients are closed out and
     * can never be sent.
     *
     * @param array<string,mixed> $campaign
     */
    private function closeIfNotActive(array $campaign, int $recipientId, ?string $token): bool
    {
        $status = (string) $campaign['status'];

        if ($status === 'cancelled') {
            $this->recipients->markSkipped($recipientId, 'blocked', ReasonCode::CAMPAIGN_CANCELLED, $token);

            $this->logger->info('Skipping send: campaign was cancelled', [
                'campaign'  => (int) $campaign['id'],
                'recipient' => $recipientId,
            ]);

            return true;
        }

        if (!in_array($status, ['sending', 'scheduled'], true)) {
            if ($token !== null) {
                // Give it back rather than leaving it claimed. The work is still
                // owed; it is just not owed right now.
                $this->recipients->release($recipientId, $token, null);
            }

            $this->logger->info('Holding send: campaign is not going out', [
                'campaign'  => (int) $campaign['id'],
                'status'    => $status,
                'recipient' => $recipientId,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Park a recipient whose outcome cannot be established.
     *
     * PermanentFailure rather than a retryable exception on purpose: the job
     * must not run again. It lands in failed_jobs, which is where an operator
     * reconciling `send:doctor` output will go looking for the detail.
     */
    private function park(int $recipientId, string $token, int $campaignId, \Throwable $e): never
    {
        $this->recipients->markOutcomeUnknown($recipientId, $token, $e->getMessage());

        $this->logger->error('Send outcome unknown; parked for reconciliation', [
            'campaign'  => $campaignId,
            'recipient' => $recipientId,
            'error'     => $e->getMessage(),
        ]);

        throw new PermanentFailure(
            'Send outcome unknown for recipient ' . $recipientId . '; parked for reconciliation: '
            . $e->getMessage()
        );
    }

    /**
     * How many attempts a single send gets in total.
     *
     * Read from the queue's own setting so the two cannot drift: the point of
     * the check above is that the scheduler and the queue agree on when to stop.
     */
    private function maxAttempts(): int
    {
        return max(1, (int) $this->config->get('queue.max_attempts', 4));
    }

    /**
     * When this recipient may be dispatched again.
     *
     * The same schedule the worker uses for the job, written onto the row so the
     * scheduler cannot route around it. Without this the two paths disagree: the
     * queue would wait five minutes while the next cron tick enqueued a fresh
     * job one minute later.
     *
     * @param array<string,mixed> $recipient
     */
    private function backoffUntil(array $recipient): ?string
    {
        $attempts = max(0, (int) ($recipient['attempt_count'] ?? 1));

        /** @var array<int,int> $schedule */
        $schedule = $this->config->get('queue.retry_backoff', [60, 300, 1800, 7200]);
        $delay    = $schedule[$attempts - 1] ?? end($schedule);

        if (!is_int($delay) || $delay <= 0) {
            return null;
        }

        return $this->clock->now()->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $organisation
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $rendered
     */
    private function outboundMessage(
        array $campaign,
        array $organisation,
        array $contact,
        array $rendered,
        int $messageId,
        string $uuid,
        int $campaignId,
    ): OutboundMessage {
        // Link rewriting happens once the message row exists, because a tracked
        // link has to carry the message identity.
        $html = $this->links->rewrite($rendered['html'], $campaign, (int) $contact['id'], $messageId);

        return new OutboundMessage(
            toEmail: (string) $contact['email'],
            subject: (string) $campaign['subject'],
            htmlBody: $html,
            textBody: $rendered['text'],
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
                'List-Id'               => '<campaign-' . $campaignId . '.'
                    . $this->hostFrom((string) $campaign['from_email']) . '>',
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
    }

    private function hostFrom(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? 'localhost' : substr($email, $at + 1);
    }
}
