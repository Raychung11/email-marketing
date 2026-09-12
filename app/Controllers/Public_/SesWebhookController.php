<?php

declare(strict_types=1);

namespace App\Controllers\Public_;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Mail\SnsMessageVerifier;
use App\Services\ProviderEventProcessor;
use App\Support\HttpFetcher;

/**
 * Amazon SES delivery notifications, arriving over SNS.
 *
 * This is how we find out what happened after a message left: did it land, did
 * it bounce, did somebody press "this is spam". Without it the do-not-email list
 * would never fill itself, bounce rates would climb unnoticed, and the whole
 * platform's ability to reach an inbox would quietly degrade.
 *
 * It is a public, unauthenticated URL because Amazon has no credentials of ours
 * to present. Everything it does rests on two checks, in this order:
 *
 *  1. The SNS signature must verify (see SnsMessageVerifier). Forging one would
 *     let an anonymous caller suppress a rival's whole list.
 *  2. The topic must be the one we configured. A valid Amazon signature only
 *     proves Amazon sent it — anybody with an AWS account can publish to their
 *     own topic and point it at us.
 *
 * After that, nothing in the payload is trusted to decide tenancy: the
 * organisation is looked up from our own email_messages row via the provider's
 * message id. A payload claiming an organisation_id would be ignored, because it
 * is never read.
 *
 * The response is always 200. SNS retries anything else for hours, and a payload
 * we have decided to reject will never become acceptable on the twentieth try.
 */
final class SesWebhookController
{
    public function __construct(
        private readonly SnsMessageVerifier $verifier,
        private readonly ProviderEventProcessor $events,
        private readonly HttpFetcher $http,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /** POST /webhooks/aws/ses */
    public function handle(Request $request): Response
    {
        // SNS posts with Content-Type: text/plain, so the body is parsed here
        // rather than relying on a JSON content type.
        $payload = json_decode($request->rawBody(), true);

        if (!is_array($payload)) {
            $this->logger->warning('SNS webhook received a body that is not JSON');

            return $this->ok('ignored');
        }

        if (!$this->verifier->verify($payload)) {
            // Deliberately 403 rather than 200: this is the one case where the
            // caller is not Amazon, so there is no retry storm to worry about and
            // an operator watching their logs should see it clearly.
            return Response::text('Signature verification failed', 403);
        }

        if (!$this->isOurTopic((string) ($payload['TopicArn'] ?? ''))) {
            $this->logger->warning('SNS message for a topic we do not subscribe to', [
                'topic' => (string) ($payload['TopicArn'] ?? ''),
            ]);

            return $this->ok('ignored');
        }

        return match ((string) $payload['Type']) {
            'SubscriptionConfirmation' => $this->confirmSubscription($payload),
            'Notification'             => $this->notification($payload),
            default                    => $this->ok('ignored'),
        };
    }

    /**
     * Complete the SNS handshake.
     *
     * Only ever for our configured topic, and only by visiting a URL on an
     * Amazon host — the SubscribeURL comes from the payload, and following an
     * arbitrary URL from a request body is how a webhook endpoint becomes a
     * tool for scanning somebody's internal network.
     *
     * @param array<string,mixed> $payload
     */
    private function confirmSubscription(array $payload): Response
    {
        $url = (string) ($payload['SubscribeURL'] ?? '');

        if (!$this->isAmazonUrl($url)) {
            $this->logger->warning('SNS subscription confirmation pointed somewhere that is not Amazon', [
                'url' => $url,
            ]);

            return $this->ok('ignored');
        }

        $confirmed = $this->http->get($url) !== null;

        $this->logger->info('SNS subscription confirmation', [
            'topic'     => (string) ($payload['TopicArn'] ?? ''),
            'confirmed' => $confirmed,
        ]);

        return $this->ok($confirmed ? 'subscribed' : 'confirmation_failed');
    }

    /** @param array<string,mixed> $payload */
    private function notification(array $payload): Response
    {
        $message = json_decode((string) ($payload['Message'] ?? ''), true);

        if (!is_array($message)) {
            $this->logger->warning('SNS notification carried a message that is not JSON');

            return $this->ok('ignored');
        }

        $recorded = 0;
        $skipped  = 0;

        foreach ($this->toEvents($message, (string) ($payload['MessageId'] ?? '')) as $event) {
            $result = $this->events->process($event);

            $result['recorded'] ? $recorded++ : $skipped++;
        }

        return $this->ok('processed', ['recorded' => $recorded, 'skipped' => $skipped]);
    }

    /**
     * Flatten an SES notification into one event per recipient.
     *
     * SES reports a bounce for three addresses as a single notification. Storing
     * that as one event would mean two of the three never reach the do-not-email
     * list, so it is split here — and each one gets its own stable event id
     * (the SNS message id plus the address), which is what makes a redelivery a
     * no-op instead of a double count.
     *
     * @param array<string,mixed> $message
     * @return array<int,array<string,mixed>>
     */
    private function toEvents(array $message, string $snsMessageId): array
    {
        // SES uses `notificationType` for plain topic subscriptions and
        // `eventType` for configuration-set event publishing. Same payloads,
        // different key, and an installation may well see both.
        $type = strtolower((string) ($message['notificationType'] ?? $message['eventType'] ?? ''));
        $mail = is_array($message['mail'] ?? null) ? $message['mail'] : [];

        $providerMessageId = (string) ($mail['messageId'] ?? '');
        $timestamp         = $this->toUtc((string) ($message['timestamp'] ?? $mail['timestamp'] ?? ''));

        $base = [
            'provider'            => 'ses',
            'provider_message_id' => $providerMessageId,
            'event_at'            => $timestamp,
        ];

        $events = [];

        switch ($type) {
            case 'bounce':
                $bounce = is_array($message['bounce'] ?? null) ? $message['bounce'] : [];

                foreach ($this->addresses($bounce['bouncedRecipients'] ?? []) as $email) {
                    $events[] = $base + [
                        'event_type'        => 'bounce',
                        'email'             => $email,
                        'bounce_type'       => (string) ($bounce['bounceType'] ?? 'Undetermined'),
                        'bounce_subtype'    => (string) ($bounce['bounceSubType'] ?? ''),
                        'provider_event_id' => $this->eventId($snsMessageId, 'bounce', $email),
                    ];
                }
                break;

            case 'complaint':
                $complaint = is_array($message['complaint'] ?? null) ? $message['complaint'] : [];

                foreach ($this->addresses($complaint['complainedRecipients'] ?? []) as $email) {
                    $events[] = $base + [
                        'event_type'        => 'complaint',
                        'email'             => $email,
                        'complaint_type'    => (string) ($complaint['complaintFeedbackType'] ?? ''),
                        'provider_event_id' => $this->eventId($snsMessageId, 'complaint', $email),
                    ];
                }
                break;

            case 'delivery':
                $delivery = is_array($message['delivery'] ?? null) ? $message['delivery'] : [];

                foreach ($this->addresses($delivery['recipients'] ?? []) as $email) {
                    $events[] = $base + [
                        'event_type'        => 'delivery',
                        'email'             => $email,
                        'provider_event_id' => $this->eventId($snsMessageId, 'delivery', $email),
                    ];
                }
                break;

            case 'open':
            case 'click':
                $detail = is_array($message[$type] ?? null) ? $message[$type] : [];

                foreach ($this->addresses($mail['destination'] ?? []) as $email) {
                    $events[] = $base + [
                        'event_type'        => $type,
                        'email'             => $email,
                        'clicked_url'       => $type === 'click' ? (string) ($detail['link'] ?? '') : null,
                        'ip_address'        => (string) ($detail['ipAddress'] ?? '') ?: null,
                        'user_agent'        => (string) ($detail['userAgent'] ?? '') ?: null,
                        'provider_event_id' => $this->eventId($snsMessageId, $type, $email),
                    ];
                }
                break;

            case 'reject':
                $reject = is_array($message['reject'] ?? null) ? $message['reject'] : [];

                foreach ($this->addresses($mail['destination'] ?? []) as $email) {
                    $events[] = $base + [
                        'event_type'        => 'reject',
                        'email'             => $email,
                        'reason'            => (string) ($reject['reason'] ?? 'Rejected by the provider'),
                        'provider_event_id' => $this->eventId($snsMessageId, 'reject', $email),
                    ];
                }
                break;

            case 'renderingfailure':
                foreach ($this->addresses($mail['destination'] ?? []) as $email) {
                    $events[] = $base + [
                        'event_type'        => 'rendering_failure',
                        'email'             => $email,
                        'reason'            => (string) ($message['failure']['errorMessage'] ?? 'Rendering failure'),
                        'provider_event_id' => $this->eventId($snsMessageId, 'rendering_failure', $email),
                    ];
                }
                break;

            case 'deliverydelay':
                // Recorded, never acted on. A delay is the provider still trying,
                // and treating it as a failure would suppress addresses that are
                // about to work perfectly well.
                foreach ($this->addresses($mail['destination'] ?? []) as $email) {
                    $events[] = $base + [
                        'event_type'        => 'delivery_delay',
                        'email'             => $email,
                        'provider_event_id' => $this->eventId($snsMessageId, 'delivery_delay', $email),
                    ];
                }
                break;

            case 'send':
                foreach ($this->addresses($mail['destination'] ?? []) as $email) {
                    $events[] = $base + [
                        'event_type'        => 'send',
                        'email'             => $email,
                        'provider_event_id' => $this->eventId($snsMessageId, 'send', $email),
                    ];
                }
                break;

            default:
                $this->logger->info('Ignoring an SES notification type we do not handle', ['type' => $type]);
        }

        return array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['email'] !== '' && $event['provider_message_id'] !== ''
        ));
    }

    /**
     * Pull addresses out of either shape SES uses: a list of strings, or a list
     * of objects with an emailAddress.
     *
     * @param mixed $recipients
     * @return array<int,string>
     */
    private function addresses(mixed $recipients): array
    {
        if (!is_array($recipients)) {
            return [];
        }

        $addresses = [];

        foreach ($recipients as $recipient) {
            $email = is_array($recipient)
                ? (string) ($recipient['emailAddress'] ?? '')
                : (is_string($recipient) ? $recipient : '');

            // SES sometimes reports "Name <address>"; keep only the address.
            if (preg_match('/<([^>]+)>/', $email, $matches) === 1) {
                $email = $matches[1];
            }

            $email = trim($email);

            if ($email !== '') {
                $addresses[] = $email;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * A stable id for one recipient's slice of one notification.
     *
     * Stable is the whole point: SNS redelivers, and the unique index on
     * (provider, provider_event_id) is what turns the second delivery into a
     * no-op rather than a second bounce.
     */
    private function eventId(string $snsMessageId, string $type, string $email): string
    {
        return substr(hash('sha256', $snsMessageId . '|' . $type . '|' . strtolower($email)), 0, 190);
    }

    private function isOurTopic(string $topicArn): bool
    {
        $configured = trim((string) $this->config->get('mail.providers.ses.sns_topic_arn', ''));

        // With no topic configured we accept any — an installation that has not
        // set one yet is usually a developer wiring things up, and refusing
        // everything would leave them with no way to see it work. In production
        // the deployment guide sets it, and the check then bites.
        if ($configured === '') {
            return true;
        }

        return hash_equals($configured, $topicArn);
    }

    private function isAmazonUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return false;
        }

        return preg_match(
            '/^sns\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?$/',
            strtolower((string) $parts['host'])
        ) === 1;
    }

    private function toUtc(string $timestamp): string
    {
        if ($timestamp === '') {
            return gmdate('Y-m-d H:i:s');
        }

        try {
            return (new \DateTimeImmutable($timestamp))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return gmdate('Y-m-d H:i:s');
        }
    }

    /** @param array<string,mixed> $extra */
    private function ok(string $status, array $extra = []): Response
    {
        return Response::json(['status' => $status] + $extra);
    }
}
