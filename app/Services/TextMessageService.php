<?php

declare(strict_types=1);

namespace App\Services;

use App\Compliance\ComplianceDecision;
use App\Compliance\ReasonCode;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Logger;
use App\Database\Connection;
use App\Messaging\OutboundText;
use App\Messaging\TextProviderInterface;
use App\Support\TenantContext;

/**
 * Sending a text.
 *
 * The rule that matters here, and the reason this is not just the email path
 * with a different provider:
 *
 *   AGREEING TO EMAIL IS NOT AGREEING TO TEXTS.
 *
 * `contact_consents` has always had a `channel` column, and this is what it was
 * for. A contact who ticked a box about your newsletter has not said you may
 * text them, and treating one permission as the other is the single most likely
 * way a business using this product gets into trouble — texts are personal,
 * people notice them, and the complaint goes to a regulator rather than a spam
 * folder.
 *
 * Two further things a text needs that an email does not:
 *
 *  QUIET HOURS. Email arriving at 2am is ignored until morning. A text arriving
 *  at 2am wakes somebody up, and they blame the business. Marketing texts
 *  outside the window are held, not dropped.
 *
 *  A COST WARNING. Email is effectively free per message; texts are not. A
 *  product that lets somebody send 900 texts without saying what it will cost
 *  is a product that loses that customer at the next invoice.
 */
final class TextMessageService
{
    public function __construct(
        private readonly TextProviderInterface $provider,
        private readonly ConsentService $consent,
        private readonly SuppressionService $suppressions,
        private readonly Connection $connection,
        private readonly TenantContext $tenant,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly ActivityService $activity,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * May we text this person, right now?
     *
     * @param array<string,mixed> $contact
     */
    public function canSend(array $contact, string $channel = 'sms'): ComplianceDecision
    {
        $organisation = $this->tenant->organisation();

        if ((string) ($organisation['status'] ?? 'active') === 'suspended') {
            return ComplianceDecision::block(ReasonCode::ORG_SUSPENDED, ['ORG_STATUS_CHECK']);
        }

        if ((int) ($organisation['sending_paused'] ?? 0) === 1) {
            return ComplianceDecision::block(ReasonCode::ORG_SENDING_PAUSED, ['ORG_SENDING_PAUSE_CHECK']);
        }

        $number = $this->normaliseNumber((string) ($contact['phone'] ?? ''), (string) ($contact['country'] ?? ''));

        if ($number === null) {
            return ComplianceDecision::block(
                ReasonCode::INVALID_EMAIL,
                ['PHONE_VALIDITY_CHECK'],
                'We do not have a usable mobile number for this person.'
            );
        }

        // Suppression is keyed on the address for email and on the number here.
        // Somebody who replied STOP is suppressed for texts and stays that way.
        if ($this->numberSuppressed($number)) {
            return ComplianceDecision::block(
                ReasonCode::SUPPRESSED_UNSUBSCRIBE,
                ['SMS_SUPPRESSION_CHECK'],
                'They replied STOP to a previous message.'
            );
        }

        // THE POINT OF THIS CLASS. Consent for this channel, not for email.
        $consent = $this->consent->current((int) $contact['id'], $channel);

        if ($consent === null || (string) $consent['status'] !== 'granted') {
            return ComplianceDecision::block(
                ReasonCode::CONSENT_UNKNOWN,
                ['SMS_CONSENT_CHECK'],
                'They have not agreed to receive text messages. Agreeing to your emails is not '
                . 'the same thing, so we will not text them on the strength of it.'
            );
        }

        if ($this->consent->isExpired($consent)) {
            return ComplianceDecision::block(ReasonCode::CONSENT_EXPIRED, ['SMS_CONSENT_CHECK']);
        }

        return ComplianceDecision::allow(['SMS_CONSENT_CHECK', 'SMS_SUPPRESSION_CHECK']);
    }

    /**
     * Send one, or say why not.
     *
     * @param array<string,mixed> $contact
     * @return array{sent:bool,reason:?string,message:string,held_until:?string}
     */
    public function send(array $contact, string $body, string $channel = 'sms'): array
    {
        $decision = $this->canSend($contact, $channel);

        if ($decision->blocked()) {
            return ['sent' => false, 'reason' => $decision->reason, 'message' => $decision->message,
                    'held_until' => null];
        }

        // Marketing texts wait for a civilised hour. Held, not dropped: the
        // message is still worth sending, just not at 2am.
        $holdUntil = $this->quietHoursHold($contact);

        if ($holdUntil !== null) {
            return ['sent' => false, 'reason' => 'QUIET_HOURS',
                    'message' => 'Held until ' . $holdUntil . ' — we do not text people at night.',
                    'held_until' => $holdUntil];
        }

        $number = (string) $this->normaliseNumber(
            (string) $contact['phone'],
            (string) ($contact['country'] ?? '')
        );

        $body = $this->prepareBody($body);

        $result = $this->provider->send(new OutboundText(
            toNumber: $number,
            body: $body,
            channel: $channel,
            messageUuid: uuid4(),
        ));

        if ($result->accepted) {
            $this->activity->record(
                'sms_sent',
                (int) $contact['id'],
                'Sent a text message',
                ['segments' => $this->segments($body)]
            );

            return ['sent' => true, 'reason' => null, 'message' => 'Sent.', 'held_until' => null];
        }

        $this->logger->warning('Text message refused', ['error' => $result->error]);

        return ['sent' => false, 'reason' => 'PROVIDER_REFUSED',
                'message' => (string) $result->error, 'held_until' => null];
    }

    // ------------------------------------------------------------- estimating

    /**
     * What sending to this many people will cost, before they press anything.
     *
     * @return array{recipients:int,segments:int,messages:int,cost:float,currency:string,needs_confirmation:bool,note:string}
     */
    public function estimate(int $recipients, string $body, string $countryCode = ''): array
    {
        $countryCode = $countryCode !== '' ? $countryCode : $this->tenant->country();

        $segments = $this->segments($body);
        $messages = $recipients * $segments;
        $cost     = round($messages * $this->provider->costPerMessage($countryCode), 2);

        $threshold = (float) $this->config->get('messaging.confirm_spend_above', 25.0);

        $note = $segments > 1
            ? 'This message is ' . mb_strlen($body) . ' characters, which counts as '
                . $segments . ' texts per person rather than one. Trimming it to '
                . (int) $this->config->get('messaging.segment_length', 160)
                . ' characters would cut the cost by about '
                . round((1 - 1 / $segments) * 100) . '%.'
            : 'One text per person.';

        return [
            'recipients'         => $recipients,
            'segments'           => $segments,
            'messages'           => $messages,
            'cost'               => $cost,
            'currency'           => $this->tenant->currency(),
            'needs_confirmation' => $cost >= $threshold,
            'note'               => $note,
        ];
    }

    /** How many texts this body will actually be charged as. */
    public function segments(string $body): int
    {
        $length = mb_strlen($body);
        $per    = max(1, (int) $this->config->get('messaging.segment_length', 160));

        return max(1, (int) ceil($length / $per));
    }

    // ---------------------------------------------------------- opting out

    /**
     * They replied STOP.
     *
     * Every text carries the instruction, and this is what happens when somebody
     * follows it. Recorded against the number, because that is what the carrier
     * gave us — they may not have told us which contact they are.
     */
    public function optOut(string $number, string $via = 'reply'): void
    {
        $number = (string) $this->normaliseNumber($number, $this->tenant->country());

        if ($number === '') {
            return;
        }

        $existing = $this->connection->table('suppressions')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('email_normalized', '=', $this->numberKey($number))
            ->first();

        if ($existing !== null) {
            return;
        }

        $now = $this->clock->nowString();

        $this->connection->table('suppressions')->insert([
            'organisation_id'  => $this->tenant->organisationId(),
            'email'            => $number,
            'email_normalized' => $this->numberKey($number),
            'email_hash'       => hash('sha256', $this->numberKey($number)),
            'reason'           => 'unsubscribe',
            'source'           => 'sms_' . $via,
            'detail'           => 'Replied STOP to a text message',
            'created_at'       => $now,
        ]);

        // And the consent history says so too, because the suppression is the
        // enforcement and the consent record is the evidence.
        $contact = $this->connection->table('contacts')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('phone', '=', $number)
            ->first();

        if ($contact !== null) {
            $this->consent->withdraw((int) $contact['id'], [
                'channel' => 'sms',
                'source'  => 'unsubscribe',
                'source_reference' => 'Replied STOP',
            ]);
        }
    }

    // ------------------------------------------------------------ internals

    /**
     * Trim to what the provider will take, and make sure the opt-out is on it.
     *
     * Appended in code rather than trusted to whoever wrote the message: a text
     * with no way out is the fastest route to a complaint, and the one line
     * nobody remembers to type.
     */
    private function prepareBody(string $body): string
    {
        $body    = trim($body);
        $optOut  = ' Reply STOP to opt out.';
        $maximum = min(
            $this->provider->maximumLength(),
            (int) $this->config->get('messaging.max_length', 1600)
        );

        if (stripos($body, 'stop') === false) {
            $body = mb_substr($body, 0, $maximum - mb_strlen($optOut)) . $optOut;
        }

        return mb_substr($body, 0, $maximum);
    }

    /**
     * Is it too late to text this person?
     *
     * @param array<string,mixed> $contact
     * @return string|null the time it would be sent instead, or null to send now
     */
    private function quietHoursHold(array $contact): ?string
    {
        if (!(bool) $this->config->get('messaging.quiet_hours.enabled', true)) {
            return null;
        }

        $from  = (int) $this->config->get('messaging.quiet_hours.from', 21);
        $until = (int) $this->config->get('messaging.quiet_hours.until', 8);

        $timezone = (string) ($contact['timezone'] ?? '') ?: $this->tenant->timezone();

        try {
            $local = $this->clock->now()->setTimezone(new \DateTimeZone($timezone));
        } catch (\Throwable) {
            return null;
        }

        $hour = (int) $local->format('G');

        if ($hour < $from && $hour >= $until) {
            return null;
        }

        $resume = $local->modify($hour >= $from ? 'tomorrow' : 'today')->setTime($until, 0);

        return $resume->format('D j M, g:ia');
    }

    /**
     * A number in one shape, so the same phone is the same phone however it was
     * typed in.
     */
    private function normaliseNumber(string $number, string $countryCode): ?string
    {
        $digits = (string) preg_replace('/[^\d+]/', '', trim($number));

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '+')) {
            return strlen($digits) >= 9 ? $digits : null;
        }

        // A local number needs a country to mean anything.
        $dialling = ['AU' => '+61', 'US' => '+1', 'GB' => '+44', 'NZ' => '+64', 'CA' => '+1'];
        $prefix   = $dialling[strtoupper($countryCode)] ?? null;

        if ($prefix === null) {
            return null;
        }

        return $prefix . ltrim($digits, '0');
    }

    private function numberKey(string $number): string
    {
        return 'tel:' . $number;
    }

    private function numberSuppressed(string $number): bool
    {
        return $this->connection->table('suppressions')
            ->where('organisation_id', '=', $this->tenant->organisationId())
            ->where('email_normalized', '=', $this->numberKey($number))
            ->whereNull('removed_at')
            ->exists();
    }
}
