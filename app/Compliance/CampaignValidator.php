<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Core\Clock;
use App\Core\Config;
use App\Database\Connection;
use App\Mail\EmailRenderer;
use App\Repositories\SendingDomainRepository;
use App\Support\TenantContext;

/**
 * Campaign preconditions.
 *
 * A campaign cannot be approved or scheduled while any blocking finding stands.
 * These are the checks that a template author cannot be relied upon to remember —
 * sender identity, a physical postal address, a working unsubscribe mechanism —
 * plus the organisation-level limits that protect shared deliverability.
 *
 * The send engine lands in phase 2; this validator is here now because the rules
 * it enforces are compliance requirements rather than features, and because the
 * tests that prove them should exist before anything can send.
 */
final class CampaignValidator
{
    public function __construct(
        private readonly ComplianceService $compliance,
        private readonly EmailRenderer $renderer,
        private readonly SendingDomainRepository $domains,
        private readonly Connection $connection,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $organisation
     * @param array{recipients?:int,eligible?:int} $audience
     * @return array{valid:bool,blocking:array<int,array{code:string,message:string}>,warnings:array<int,array{code:string,message:string}>}
     */
    public function validate(array $campaign, array $organisation, array $audience = []): array
    {
        $blocking = [];
        $warnings = [];

        $country = strtoupper((string) ($organisation['country'] ?? 'US'));
        $rules   = $this->rulesFor($country);

        // --- Sender identity ------------------------------------------------
        $fromEmail = trim((string) ($campaign['from_email'] ?? $organisation['default_sender_email'] ?? ''));
        $fromName  = trim((string) ($campaign['from_name'] ?? $organisation['default_sender_name'] ?? ''));

        if ($fromEmail === '' || $fromName === '') {
            $blocking[] = $this->finding(ReasonCode::NO_SENDER_IDENTITY);
        } elseif (!is_valid_email($fromEmail)) {
            $blocking[] = $this->finding(
                ReasonCode::NO_SENDER_IDENTITY,
                'The from address is not a valid email address.'
            );
        } else {
            // The sending domain must be verified: unauthenticated mail does not
            // reach inboxes, and spoofing someone else's domain is worse.
            $domain = substr($fromEmail, strrpos($fromEmail, '@') + 1);

            if (!$this->domains->canSendFrom($fromEmail)) {
                $blocking[] = $this->finding(
                    ReasonCode::SENDER_DOMAIN_UNVERIFIED,
                    'The domain ' . $domain . ' is not verified for sending. Verify it in Settings → Domains.'
                );
            }
        }

        // --- Business identity ----------------------------------------------
        if (trim((string) ($organisation['name'] ?? '')) === '') {
            $blocking[] = $this->finding(ReasonCode::NO_BUSINESS_IDENTITY);
        }

        if (($rules['require_contact_details'] ?? true)
            && trim((string) ($organisation['contact_email'] ?? '')) === ''
            && trim((string) ($organisation['contact_phone'] ?? '')) === ''
        ) {
            $warnings[] = $this->finding(
                ReasonCode::NO_BUSINESS_IDENTITY,
                'No contact phone or email is configured for your business. Recipients should be able to reach you.'
            );
        }

        // --- Physical postal address (a hard requirement in the US) ---------
        $address = $this->renderer->formatAddress($organisation);

        if ($address === '' || trim((string) ($organisation['address_line1'] ?? '')) === '') {
            if ($rules['require_postal_address'] ?? false) {
                $blocking[] = $this->finding(ReasonCode::NO_POSTAL_ADDRESS);
            } else {
                $warnings[] = $this->finding(
                    ReasonCode::NO_POSTAL_ADDRESS,
                    'No physical postal address is configured. It is required for US recipients and good practice everywhere.'
                );
            }
        }

        // --- Content ---------------------------------------------------------
        $subject = trim((string) ($campaign['subject'] ?? ''));
        $html    = (string) ($campaign['html_content'] ?? '');

        if ($subject === '') {
            $blocking[] = $this->finding(ReasonCode::NO_SUBJECT);
        }

        if (trim(strip_tags($html)) === '') {
            $blocking[] = $this->finding(ReasonCode::NO_CONTENT);
        }

        $messageClass = (string) ($campaign['message_class'] ?? 'marketing');

        if ($messageClass === 'marketing') {
            // The renderer adds a footer if one is missing, but a campaign that
            // relies on that has not been reviewed properly, so it is flagged.
            if (!$this->renderer->hasUnsubscribeLink($html)) {
                $warnings[] = $this->finding(
                    ReasonCode::NO_UNSUBSCRIBE,
                    'The content has no unsubscribe link. One will be added to the footer automatically, '
                    . 'but placing it deliberately is better.'
                );
            }

            if (($rules['require_honest_subject'] ?? false) && $this->subjectLooksDeceptive($subject)) {
                $blocking[] = $this->finding(
                    ReasonCode::DECEPTIVE_SUBJECT,
                    'The subject line looks misleading ("' . $subject . '"). Subject lines that imply a reply, '
                    . 'a transaction or an urgency that does not exist are not permitted for commercial email.'
                );
            }
        }

        // Marketing content must not travel the transactional path.
        $honesty = $this->compliance->assertMessageClassHonest($campaign);

        if ($honesty->blocked()) {
            $blocking[] = ['code' => $honesty->reason, 'message' => $honesty->message];
        }

        // --- Audience --------------------------------------------------------
        $hasAudience = (int) ($campaign['segment_id'] ?? 0) > 0 || (int) ($campaign['list_id'] ?? 0) > 0;

        if (!$hasAudience) {
            $blocking[] = $this->finding(ReasonCode::NO_AUDIENCE);
        } elseif (array_key_exists('eligible', $audience) && (int) $audience['eligible'] < 1) {
            $blocking[] = $this->finding(
                ReasonCode::NO_ELIGIBLE_RECIPIENTS,
                'No recipients are eligible: every contact in this audience is suppressed, lacks a consent basis, '
                . 'or has an invalid address.'
            );
        }

        // --- Organisation limits ---------------------------------------------
        if ((string) ($organisation['status'] ?? 'active') === 'suspended') {
            $blocking[] = $this->finding(ReasonCode::ORG_SUSPENDED);
        }

        if ((int) ($organisation['sending_paused'] ?? 0) === 1) {
            $blocking[] = $this->finding(
                ReasonCode::ORG_SENDING_PAUSED,
                (string) ($organisation['sending_paused_reason'] ?? '') !== ''
                    ? (string) $organisation['sending_paused_reason']
                    : ReasonCode::describe(ReasonCode::ORG_SENDING_PAUSED)
            );
        }

        $dailyLimit = (int) ($organisation['daily_send_limit'] ?? 0);
        $eligible   = (int) ($audience['eligible'] ?? 0);

        if ($dailyLimit > 0 && $eligible > 0) {
            $sentToday = (int) $this->connection->scalar(
                "SELECT COUNT(*) FROM email_messages
                 WHERE organisation_id = ? AND message_class = 'marketing' AND created_at >= ?",
                [(int) $organisation['id'], $this->clock->now()->format('Y-m-d 00:00:00')]
            );

            if (($sentToday + $eligible) > $dailyLimit) {
                $blocking[] = $this->finding(
                    ReasonCode::ORG_DAILY_LIMIT_REACHED,
                    sprintf(
                        'This campaign would send %s emails, taking today\'s total past your %s daily limit '
                        . '(%s already sent). Limits increase as your account builds a sending history.',
                        number_format($eligible),
                        number_format($dailyLimit),
                        number_format($sentToday)
                    )
                );
            }
        }

        return [
            'valid'    => $blocking === [],
            'blocking' => $blocking,
            'warnings' => $warnings,
        ];
    }

    /**
     * A deliberately conservative check for the most common deceptive patterns:
     * faking a reply, faking a transaction, or impersonating a system notice.
     */
    private function subjectLooksDeceptive(string $subject): bool
    {
        $normalised = mb_strtolower(trim($subject));

        foreach ([
            '/^(re|fw|fwd)\s*[:\-]/i',
            '/^(your )?(order|invoice|payment|receipt|refund) (confirmation|confirmed|received|#)/i',
            '/^(urgent|action required)\s*[:\-]?\s*(your )?(account|payment|password)/i',
            '/^(undelivered|delivery failure|mail delivery)/i',
        ] as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function rulesFor(string $country): array
    {
        /** @var array<string,mixed> $rules */
        $rules = $this->config->get('compliance.countries.' . $country)
            ?? $this->config->get('compliance.countries.*', []);

        return $rules;
    }

    /** @return array{code:string,message:string} */
    private function finding(string $code, string $message = ''): array
    {
        return [
            'code'    => $code,
            'message' => $message !== '' ? $message : ReasonCode::describe($code),
        ];
    }
}
