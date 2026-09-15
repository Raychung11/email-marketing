<?php

declare(strict_types=1);

namespace App\Automation\Actions;

use App\Compliance\ComplianceService;
use App\Core\Clock;
use App\Core\Config;
use App\Mail\EmailProviderInterface;
use App\Mail\EmailRenderer;
use App\Mail\OutboundMessage;
use App\Mail\TemplateRenderer;
use App\Repositories\EmailMessageRepository;
use App\Repositories\TemplateRepository;
use App\Services\ActivityService;
use App\Services\LinkTracker;
use App\Support\TenantContext;

/**
 * Send one email from a journey.
 *
 * The compliance check here is not a copy of the campaign one for convenience —
 * it is the same service, called at the same moment relative to the provider.
 * An automation is the easiest place in a product like this to accidentally
 * build a second, laxer sending path: it runs unattended, its recipients arrive
 * one at a time, and nobody is watching. So it does not get its own path. It
 * gets suppression, consent, the country rule set and the organisation's
 * sending state, exactly as a campaign does, immediately before the send.
 *
 * The other rule: one contact, one message, sent now. A journey never batches,
 * so there is no bulk-send path here to keep out of an HTTP request — but the
 * runner that calls this still runs in a worker, for the same reason.
 */
final class SendEmailAction implements Action
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly TemplateRenderer $blocks,
        private readonly EmailRenderer $renderer,
        private readonly ComplianceService $compliance,
        private readonly EmailProviderInterface $provider,
        private readonly EmailMessageRepository $messages,
        private readonly LinkTracker $links,
        private readonly ActivityService $activity,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $context
     */
    public function perform(string $actionType, array $config, array $contact, array $context): ActionResult
    {
        $organisation = $this->tenant->organisation();

        $templateId = (int) ($config['template_id'] ?? 0);
        $subject    = trim((string) ($config['subject'] ?? ''));

        if ($templateId <= 0 || $subject === '') {
            return ActionResult::failed('This step has no email set up on it.');
        }

        $template = $this->templates->find($templateId);

        if ($template === null) {
            return ActionResult::failed('The email this step used has been deleted.');
        }

        // ---- THE COMPLIANCE CHECK -----------------------------------------
        // Marketing rules, because a journey email is marketing however it was
        // triggered. A "thanks for your enquiry" that also plugs a discount is
        // still marketing, and treating automation as transactional by default
        // is how a platform ends up mailing people who said no.
        $decision = $this->compliance->canSendMarketingEmail($organisation, $contact, [
            'topic' => $config['topic'] ?? null,
        ]);

        if ($decision->blocked()) {
            return ActionResult::blocked($decision->reason, $decision->message);
        }

        $uuid = uuid4();

        $rendered = $this->renderer->render(
            (string) $template['html_cache'],
            (string) $template['text_cache'],
            $organisation,
            $contact,
            null,
            null,
            'marketing'
        );

        $messageId = $this->messages->create([
            'uuid'          => $uuid,
            'automation_id' => isset($context['automation_id']) ? (int) $context['automation_id'] : null,
            'automation_run_id' => isset($context['run_id']) ? (int) $context['run_id'] : null,
            'contact_id'    => (int) $contact['id'],
            'message_class' => 'marketing',
            'provider'      => $this->provider->name(),
            'email'         => (string) $contact['email'],
            'subject'       => $subject,
            'from_email'    => (string) ($organisation['default_sender_email'] ?? ''),
            'status'        => 'sending',
            'queued_at'     => $this->clock->nowString(),
            'created_at'    => $this->clock->nowString(),
        ]);

        $message = new OutboundMessage(
            toEmail: (string) $contact['email'],
            subject: $this->renderer->substitute($subject, $this->renderer->variablesFor(
                $organisation,
                $contact,
                $rendered['unsubscribe_url'],
                $rendered['preferences_url']
            ), false),
            htmlBody: $this->links->rewriteForAutomation($rendered['html'], $organisation, (int) $contact['id'], $messageId),
            textBody: $rendered['text'],
            fromEmail: (string) ($organisation['default_sender_email'] ?? ''),
            fromName: (string) ($organisation['default_sender_name'] ?? ($organisation['name'] ?? '')),
            replyTo: (string) ($organisation['reply_to_email'] ?? ''),
            headers: [
                'List-Unsubscribe'      => '<' . $rendered['unsubscribe_url'] . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                'X-Message-Id'          => $uuid,
                'Precedence'            => 'bulk',
                // Tells autoresponders not to reply to an automated message.
                'Auto-Submitted'        => 'auto-generated',
            ],
            tags: [
                'organisation_id' => (string) $organisation['id'],
                'message_class'   => 'marketing',
                'source'          => 'automation',
            ],
            messageClass: 'marketing',
            configurationSet: (string) $this->config->get('mail.providers.ses.configuration_set', '') ?: null,
            messageUuid: $uuid,
        );

        $result = $this->provider->send($message);

        if (!$result->accepted) {
            $this->messages->update($messageId, [
                'status'         => 'failed',
                'failed_at'      => $this->clock->nowString(),
                'failure_reason' => substr((string) $result->error, 0, 255),
            ]);

            return ActionResult::failed('The email service refused it: ' . (string) $result->error);
        }

        $this->messages->update($messageId, [
            'provider_message_id' => $result->providerMessageId,
            'status'              => 'sent',
            'sent_at'             => $this->clock->nowString(),
        ]);

        $this->activity->record(
            'email_sent',
            (int) $contact['id'],
            'Sent "' . $subject . '" from an automation',
            ['message_id' => $messageId]
        );

        return ActionResult::done('Email sent.', ['email_message_id' => $messageId]);
    }
}
