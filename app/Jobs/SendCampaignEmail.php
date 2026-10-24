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
use App\Queue\QueueManager;
use App\Repositories\CampaignRecipientRepository;
use App\Repositories\CampaignRepository;
use App\Repositories\ContactRepository;
use App\Repositories\EmailMessageRepository;
use App\Repositories\OrganisationRepository;
use App\Services\ActivityService;
use App\Services\LinkTracker;
use App\Support\TenantContext;
use Throwable;

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
        private readonly QueueManager $queue,
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

        $status = (string) $campaign['status'];

        /*
         * Paused, and this is where the work used to disappear.
         *
         * The old code returned here, the worker acknowledged the job away, and
         * the recipient stayed `queued` — a state the dispatcher never looks at
         * again, because it only ever re-queues `pending`. The campaign resumed
         * and those people were simply never written to.
         *
         * Pausing now sweeps the campaign's outstanding rows back to `pending`
         * (see CampaignService::pause), so by the time a straggling job gets
         * here the row is already waiting to be picked up again. Returning is
         * then correct and costs nothing: no claim was taken, no retry is
         * burned, and the job is acknowledged rather than thrown back at a
         * queue that would only hand it over again in a tight loop.
         *
         * Cancelled is NOT the same and must never share this path: its rows
         * are closed out permanently when the campaign is cancelled, so there
         * is nothing to resume and nothing here may send.
         */
        if (in_array($status, ['paused', 'cancelled'], true)) {
            $this->logger->info('Skipping send: campaign is no longer active', [
                'campaign' => $campaignId,
                'status'   => $status,
            ]);

            return;
        }

        $recipient = $this->recipients->findForCampaign($campaignId, $recipientId);

        if ($recipient === null) {
            throw new PermanentFailure('Recipient ' . $recipientId . ' is not part of campaign ' . $campaignId . '.');
        }

        /*
         * Claim it, and hold a token for as long as this takes.
         *
         * The claim moves the row to `sending` — not `sent`, which is what it
         * used to do before anything had been rendered, let alone sent. Only
         * one worker can win; a redelivered job, or a second worker racing the
         * same recipient, claims nothing and returns.
         *
         * Every write from here on carries the token, so a worker whose lease
         * lapsed cannot wake up and stamp its stale conclusion over the row
         * somebody else has since taken and finished.
         */
        $token = uuid4();

        if (!$this->recipients->claimForSending($recipientId, $token)) {
            $this->logger->info('Skipping send: recipient is not claimable', [
                'recipient' => $recipientId,
                'status'    => (string) $recipient['send_status'],
            ]);

            return;
        }

        $contact = $this->contacts->find((int) $recipient['contact_id']);

        if ($contact === null) {
            $this->recipients->markSkipped($recipientId, $token, 'blocked', 'CONTACT_DELETED');

            return;
        }

        // ---- THE SECOND COMPLIANCE CHECK ---------------------------------
        $decision = $this->compliance->canSendMarketingEmail($organisation, $contact, $campaign);

        if ($decision->blocked()) {
            $this->recipients->markSkipped(
                $recipientId,
                $token,
                ReasonCode::bucket($decision->reason),
                $decision->reason
            );

            $this->logger->info('Send blocked at queue time', [
                'campaign' => $campaignId,
                'reason'   => $decision->reason,
            ]);

            return;
        }

        /*
         * EVERYTHING IN HERE IS LOCAL AND THEREFORE SAFELY RETRYABLE.
         *
         * Rendering, the message row, link rewriting, building the envelope —
         * none of it has told the provider anything. If any of it throws, the
         * recipient goes back on the queue and the next attempt starts over,
         * which is exactly what could not happen before: the row was already
         * `sent`, so the retry claimed nothing and the email was lost in
         * silence while the report said it had gone.
         */
        try {
            $prepared  = $this->prepare($organisation, $campaign, $contact, $recipient, $campaignId);
            $messageId = $prepared['message_id'];
            $message   = $this->envelope($organisation, $campaign, $contact, $campaignId, $prepared);
        } catch (Throwable $e) {
            $this->recipients->release($recipientId, $token);

            throw $e;
        }

        /*
         * The line past which nothing is certain any more.
         *
         * Stamped BEFORE the call, because afterwards may never arrive. If this
         * process dies one instruction later, the sweep finds a lapsed lease
         * with this column set and records the recipient as `outcome_unknown`
         * rather than guessing. Had the column still been null, the sweep would
         * know the request never left and could safely re-queue it.
         *
         * No transaction is open across this call, and none may be: holding one
         * while waiting on a third party is how a connection pool dies.
         */
        if (!$this->recipients->markProviderAttempted($recipientId, $token)) {
            /*
             * The lease is gone — swept as expired, or taken over by another
             * worker. Whoever holds it now is responsible for this recipient,
             * and calling the provider anyway is how one person gets two
             * copies: this process would send, fail to record it (the token no
             * longer matches), and leave the row for somebody else to send
             * again.
             *
             * The message row prepared a moment ago is closed out so it does
             * not sit in `sending` for ever pretending to be in flight.
             */
            $this->messages->update($messageId, [
                'status'         => 'failed',
                'failed_at'      => $this->clock->nowString(),
                'failure_reason' => 'Abandoned: the lease on this recipient was lost before sending.',
            ]);

            $this->logger->warning('Abandoning send: no longer the lease holder', [
                'campaign'  => $campaignId,
                'recipient' => $recipientId,
            ]);

            return;
        }

        try {
            $result = $this->provider->send($message);
        } catch (Throwable $e) {
            /*
             * The request left and the answer never came back — a timeout, a
             * dropped connection, a provider that died mid-reply. The message
             * may be in somebody's inbox and it may not, and nothing available
             * here can tell which.
             *
             * So it is recorded as unresolved and NOT retried. PermanentFailure
             * is what stops the queue trying again: a blind retry here is how
             * one person receives the same email twice.
             */
            $this->recipients->markOutcomeUnknown(
                $recipientId,
                $token,
                'Provider call did not complete: ' . $e->getMessage()
            );

            /*
             * The message row keeps `sending`, which is the literal truth: the
             * handoff started and never concluded. There is no `unknown` in
             * this column's values and inventing one would mean rebuilding the
             * largest table in the schema to record something the recipient row
             * already says authoritatively.
             *
             * It also lands it in the diagnostic's "stuck in sending" report,
             * which is precisely where somebody should be looking for it.
             */
            $this->messages->update($messageId, [
                'failure_reason' => substr('Provider call did not complete: ' . $e->getMessage(), 0, 255),
            ]);

            $this->logger->error('Send outcome unknown; held for reconciliation', [
                'campaign'  => $campaignId,
                'recipient' => $recipientId,
                'message'   => $messageId,
                'error'     => $e->getMessage(),
            ]);

            throw new PermanentFailure(
                'Outcome unknown for recipient ' . $recipientId . '; held for manual reconciliation.'
            );
        }

        if ($result->accepted) {
            /*
             * The recipient is closed FIRST, before the message row is touched.
             *
             * This is the write that stops a second copy going out, so it is
             * the one that must land. If the message-row update fails after it,
             * the campaign has a reporting inconsistency — which the diagnostic
             * command finds and a person can repair — rather than a live risk
             * of sending the same email again.
             */
            $this->recipients->markSent($recipientId, $token, $messageId);

            try {
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
            } catch (Throwable $e) {
                // Swallowed on purpose. The provider has the message and the
                // recipient says so; throwing now would hand the job back to a
                // queue whose only move is to send it again.
                $this->logger->error('Accepted by the provider but the local record did not finish', [
                    'campaign'  => $campaignId,
                    'recipient' => $recipientId,
                    'message'   => $messageId,
                    'error'     => $e->getMessage(),
                ]);
            }

            return;
        }

        // Rejected before it left. The handoff failed, so the message row is
        // closed as failed rather than left 'sending' — a retry creates its own
        // row, and leaving this one open would make the campaign look like it
        // sent a message it never did.
        $this->messages->update($messageId, [
            'status'         => 'failed',
            'failed_at'      => $this->clock->nowString(),
            'failure_reason' => $result->error === null ? null : substr($result->error, 0, 255),
        ]);

        if (!$result->retryable) {
            // The provider saying this will never work. Retrying burns sending
            // reputation for nothing.
            $this->recipients->markFailed($recipientId, $token, (string) $result->error);

            throw new PermanentFailure(
                'Provider rejected the message: ' . (string) $result->error
            );
        }

        /*
         * A temporary refusal, reported by the provider rather than inferred.
         * The provider explicitly did not take the message, so returning the
         * recipient to the pool cannot duplicate anything — and the exception
         * is what puts the job on the retry queue with its backoff.
         */
        /*
         * Held back for exactly as long as the queue is about to hold the job.
         *
         * The recipient used to go straight back to `pending`, which the
         * scheduler re-queues every tick — so a provider having a bad minute
         * was retried once a minute by the scheduler while the retry queue
         * waited out its two-hour backoff. One of the two was being ignored.
         */
        $attempt = (int) $recipient['attempt_count'] + 1;

        $this->recipients->release(
            $recipientId,
            $token,
            $this->queue->backoffFor(max(0, $attempt - 1)) ?? 0
        );

        throw new \RuntimeException('Provider temporarily unavailable: ' . (string) $result->error);
    }

    /**
     * Everything local that has to exist before the provider is called.
     *
     * Split out so the caller can wrap exactly this in a try/catch and know
     * that anything thrown inside it happened before the message left — which
     * is what makes releasing the recipient safe rather than a coin toss.
     *
     * @param array<string,mixed> $organisation
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $recipient
     * @return array{message_id:int,uuid:string,html:string,text:string,unsubscribe_url:string}
     */
    private function prepare(
        array $organisation,
        array $campaign,
        array $contact,
        array $recipient,
        int $campaignId
    ): array {
        $uuid = uuid4();

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

        // Link rewriting happens after the message row exists, because a tracked
        // link has to carry the message identity.
        $html = $this->links->rewrite(
            $rendered['html'],
            $campaign,
            (int) $contact['id'],
            $messageId
        );

        return [
            'message_id'      => $messageId,
            'uuid'            => $uuid,
            'html'            => $html,
            'text'            => $rendered['text'],
            'unsubscribe_url' => $rendered['unsubscribe_url'],
        ];
    }

    /**
     * The envelope handed to the provider.
     *
     * Takes what prepare() produced rather than reading it off $this. This
     * handler is resolved from the container and reused by the worker for job
     * after job, so anything kept on the instance leaks from one recipient into
     * the next — which on this particular class would mean sending one person's
     * rendered email to somebody else.
     *
     * @param array<string,mixed> $organisation
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $contact
     * @param array{message_id:int,uuid:string,html:string,text:string,unsubscribe_url:string} $prepared
     */
    private function envelope(
        array $organisation,
        array $campaign,
        array $contact,
        int $campaignId,
        array $prepared
    ): OutboundMessage {
        return new OutboundMessage(
            toEmail: (string) $contact['email'],
            subject: (string) $campaign['subject'],
            htmlBody: $prepared['html'],
            textBody: $prepared['text'],
            fromEmail: (string) $campaign['from_email'],
            fromName: (string) $campaign['from_name'],
            replyTo: (string) ($campaign['reply_to'] ?? ''),
            toName: trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? '')) ?: null,
            headers: [
                // RFC 8058: a one-click unsubscribe the mail client can offer in
                // its own UI. Recipients who can unsubscribe easily do that
                // instead of pressing "spam", which is what protects the sending
                // reputation of everyone on the platform.
                'List-Unsubscribe'      => '<' . $prepared['unsubscribe_url'] . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                'List-Id'               => '<campaign-' . $campaignId . '.' . $this->hostFrom((string) $campaign['from_email']) . '>',
                'X-Message-Id'          => $prepared['uuid'],
                'Precedence'            => 'bulk',
            ],
            tags: [
                'campaign_id'     => (string) $campaignId,
                'organisation_id' => (string) $organisation['id'],
                'message_class'   => 'marketing',
            ],
            messageClass: 'marketing',
            configurationSet: (string) $this->config->get('mail.providers.ses.configuration_set', '') ?: null,
            messageUuid: $prepared['uuid'],
        );
    }

    private function hostFrom(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? 'localhost' : substr($email, $at + 1);
    }
}
