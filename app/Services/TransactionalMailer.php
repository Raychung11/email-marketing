<?php

declare(strict_types=1);

namespace App\Services;

use App\Compliance\ComplianceService;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Logger;
use App\Database\Connection;
use App\Mail\EmailProviderInterface;
use App\Mail\OutboundMessage;

/**
 * Operational (transactional) email: password resets, invitations, alerts.
 *
 * Deliberately a separate path from campaign sending, with its own queue
 * priority, so a 100k marketing blast can never delay a password reset. It is
 * NOT a way around marketing suppression: the compliance gate still runs, and
 * ComplianceService::assertMessageClassHonest() stops a promotional campaign
 * being relabelled to travel this road.
 */
final class TransactionalMailer
{
    public function __construct(
        private readonly EmailProviderInterface $provider,
        private readonly ComplianceService $compliance,
        private readonly Connection $connection,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function sendPasswordReset(string $email, string $token): bool
    {
        $appName = (string) $this->config->get('app.name', 'AI Growth Hub');
        $url     = url('reset-password/' . rawurlencode($token));
        $ttl     = (int) $this->config->get('security.password_reset.ttl', 3600);
        $minutes = max(1, (int) round($ttl / 60));

        $html = $this->wrap(
            'Reset your password',
            '<p>We received a request to reset the password for your ' . e($appName) . ' account.</p>'
            . '<p><a href="' . $url . '" style="display:inline-block;padding:10px 18px;background:#111827;'
            . 'color:#ffffff;border-radius:6px;text-decoration:none">Choose a new password</a></p>'
            . '<p>This link expires in ' . $minutes . ' minutes and can only be used once.</p>'
            . '<p>If you did not ask for this, you can ignore this email — your password will not change.</p>'
        );

        $text = "Reset your password\n\n"
            . "We received a request to reset the password for your {$appName} account.\n\n"
            . "{$url}\n\n"
            . "This link expires in {$minutes} minutes and can only be used once.\n"
            . "If you did not ask for this, you can ignore this email.\n";

        return $this->send($email, 'Reset your ' . $appName . ' password', $html, $text);
    }

    public function sendTeamInvitation(string $email, string $organisationName, string $inviterName, string $token): bool
    {
        $appName = (string) $this->config->get('app.name', 'AI Growth Hub');
        $url     = url('invitations/' . rawurlencode($token));

        $html = $this->wrap(
            'You have been invited to ' . e($organisationName),
            '<p>' . e($inviterName) . ' has invited you to join <strong>' . e($organisationName)
            . '</strong> on ' . e($appName) . '.</p>'
            . '<p><a href="' . $url . '" style="display:inline-block;padding:10px 18px;background:#111827;'
            . 'color:#ffffff;border-radius:6px;text-decoration:none">Accept the invitation</a></p>'
            . '<p>If you were not expecting this, you can ignore it.</p>'
        );

        $text = "{$inviterName} has invited you to join {$organisationName} on {$appName}.\n\n{$url}\n";

        return $this->send($email, 'Join ' . $organisationName . ' on ' . $appName, $html, $text);
    }

    /**
     * @param array<string,mixed> $organisation
     * @param array<string,mixed> $contact
     */
    public function sendToContact(
        array $organisation,
        array $contact,
        string $subject,
        string $html,
        string $text = '',
    ): bool {
        // Transactional still respects hard bounces, invalid addresses, complaints
        // and legal holds — there is no point (and no right) mailing those.
        $decision = $this->compliance->canSendTransactionalEmail($organisation, $contact);

        if ($decision->blocked()) {
            $this->logger->info('Transactional email suppressed', [
                'reason' => $decision->reason,
                'email'  => \App\Support\Str::maskEmail((string) $contact['email']),
            ]);

            return false;
        }

        return $this->send(
            (string) $contact['email'],
            $subject,
            $html,
            $text,
            (int) $organisation['id'],
            isset($contact['id']) ? (int) $contact['id'] : null,
            (string) ($organisation['default_sender_email'] ?? ''),
            (string) ($organisation['default_sender_name'] ?? ($organisation['name'] ?? ''))
        );
    }

    private function send(
        string $email,
        string $subject,
        string $html,
        string $text,
        ?int $organisationId = null,
        ?int $contactId = null,
        string $fromEmail = '',
        string $fromName = '',
    ): bool {
        $fromEmail = $fromEmail !== '' ? $fromEmail : (string) $this->config->get('mail.from.address');
        $fromName  = $fromName !== '' ? $fromName : (string) $this->config->get('mail.from.name');

        $uuid = uuid4();

        $message = new OutboundMessage(
            toEmail: $email,
            subject: $subject,
            htmlBody: $html,
            textBody: $text,
            fromEmail: $fromEmail,
            fromName: $fromName,
            headers: [
                // Marks the message as automatic so that autoresponders and some
                // filters treat it correctly.
                'Auto-Submitted' => 'auto-generated',
                'X-Message-Id'   => $uuid,
            ],
            tags: ['message_class' => 'transactional'],
            messageClass: 'transactional',
            messageUuid: $uuid,
        );

        $result = $this->provider->send($message);

        if ($organisationId !== null) {
            $this->recordMessage($uuid, $organisationId, $contactId, $email, $subject, $fromEmail, $result);
        }

        if (!$result->accepted) {
            $this->logger->error('Transactional email failed', [
                'email' => \App\Support\Str::maskEmail($email),
                'error' => $result->error,
                'code'  => $result->errorCode,
            ]);
        }

        return $result->accepted;
    }

    private function recordMessage(
        string $uuid,
        int $organisationId,
        ?int $contactId,
        string $email,
        string $subject,
        string $fromEmail,
        \App\Mail\SendResult $result,
    ): void {
        $now = $this->clock->nowString();

        $this->connection->table('email_messages')->insert([
            'uuid'                => $uuid,
            'organisation_id'     => $organisationId,
            'contact_id'          => $contactId,
            'message_class'       => 'transactional',
            'provider'            => $this->provider->name(),
            'provider_message_id' => $result->providerMessageId,
            'email'               => $email,
            'email_normalized'    => normalize_email($email),
            'subject'             => $subject,
            'from_email'          => $fromEmail,
            'status'              => $result->accepted ? 'sent' : 'failed',
            'queued_at'           => $now,
            'sent_at'             => $result->accepted ? $now : null,
            'failed_at'           => $result->accepted ? null : $now,
            'failure_reason'      => $result->error === null ? null : substr($result->error, 0, 255),
            'created_at'          => $now,
        ]);
    }

    private function wrap(string $heading, string $body): string
    {
        $appName = e((string) $this->config->get('app.name', 'AI Growth Hub'));

        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f9fafb;'
            . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111827">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;'
            . 'border-radius:10px;padding:28px">'
            . '<h1 style="margin:0 0 16px;font-size:20px">' . $heading . '</h1>'
            . '<div style="font-size:14px;line-height:1.65">' . $body . '</div>'
            . '<p style="margin:24px 0 0;font-size:12px;color:#6b7280">' . $appName . '</p>'
            . '</div></body></html>';
    }
}
