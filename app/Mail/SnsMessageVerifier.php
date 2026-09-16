<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Clock;
use App\Core\Logger;
use App\Support\HttpFetcher;

/**
 * Proves an inbound SNS message really came from Amazon.
 *
 * The endpoint is a public URL with no authentication — it has to be, because
 * Amazon calls it — so the signature is the *only* thing standing between an
 * anonymous POST and our bounce handling. Anyone who could forge one could
 * suppress a competitor's entire mailing list, or clear bounces off their own.
 *
 * Three checks, and all three matter:
 *
 *  1. THE CERTIFICATE URL MUST BE AMAZON'S. This is the one people miss. The
 *     message names the certificate used to sign it, so an attacker who could
 *     point that anywhere would simply sign their forgery with their own key and
 *     hand us the matching certificate. The host is allow-listed against
 *     Amazon's own SNS domains before a single byte is fetched.
 *
 *  2. THE SIGNATURE MUST VERIFY against a canonical string built from the
 *     fields SNS says it signed — rebuilt here from the payload rather than
 *     taken from it.
 *
 *  3. THE MESSAGE MUST BE RECENT. A valid signature stays valid for ever, so
 *     without this a captured message could be replayed a year later.
 *
 * The topic check lives in the controller, not here: this class answers "is this
 * genuinely from Amazon", and the controller answers "is it for us".
 */
final class SnsMessageVerifier
{
    /**
     * Hosts that may serve a signing certificate.
     *
     * Anchored at both ends. A pattern like "contains amazonaws.com" would accept
     * `sns.amazonaws.com.evil.test`, which is exactly the attack.
     */
    private const CERTIFICATE_HOST = '/^sns\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?$/';

    /** Fields SNS signs, per message type, in the order it signs them. */
    private const SIGNED_FIELDS = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => [
            'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type',
        ],
        'UnsubscribeConfirmation' => [
            'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type',
        ],
    ];

    /** How old a message may be before we treat it as a replay. */
    private const MAX_AGE_SECONDS = 3600;

    public function __construct(
        private readonly HttpFetcher $http,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function verify(array $payload): bool
    {
        $type = (string) ($payload['Type'] ?? '');

        if (!isset(self::SIGNED_FIELDS[$type])) {
            $this->reject('unknown message type', ['type' => $type]);

            return false;
        }

        if (!$this->isFresh($payload)) {
            return false;
        }

        $certificateUrl = (string) ($payload['SigningCertURL'] ?? $payload['SigningCertUrl'] ?? '');

        if (!$this->isAmazonCertificateUrl($certificateUrl)) {
            $this->reject('signing certificate URL is not an Amazon SNS host', ['url' => $certificateUrl]);

            return false;
        }

        $signature = base64_decode((string) ($payload['Signature'] ?? ''), true);

        if ($signature === false || $signature === '') {
            $this->reject('missing or malformed signature');

            return false;
        }

        $certificate = $this->http->get($certificateUrl);

        if ($certificate === null) {
            $this->reject('could not fetch the signing certificate', ['url' => $certificateUrl]);

            return false;
        }

        $publicKey = openssl_pkey_get_public($certificate);

        if ($publicKey === false) {
            $this->reject('signing certificate could not be read');

            return false;
        }

        // Version 1 is SHA1, version 2 SHA256. Amazon still sends 1 to older
        // subscriptions; both are accepted, nothing else is.
        $version   = (string) ($payload['SignatureVersion'] ?? '1');
        $algorithm = match ($version) {
            '1'     => OPENSSL_ALGO_SHA1,
            '2'     => OPENSSL_ALGO_SHA256,
            default => null,
        };

        if ($algorithm === null) {
            $this->reject('unsupported signature version', ['version' => $version]);

            return false;
        }

        $verified = openssl_verify(
            $this->canonicalString($payload, self::SIGNED_FIELDS[$type]),
            $signature,
            $publicKey,
            $algorithm
        );

        if ($verified !== 1) {
            $this->reject('signature did not verify');

            return false;
        }

        return true;
    }

    /**
     * Rebuild the string Amazon signed.
     *
     * Built from the payload's own fields in Amazon's fixed order, never from
     * anything the sender chose the shape of. Optional fields (Subject) are
     * skipped when absent, exactly as SNS does.
     *
     * @param array<string,mixed> $payload
     * @param array<int,string>   $fields
     */
    private function canonicalString(array $payload, array $fields): string
    {
        $parts = '';

        foreach ($fields as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null) {
                continue;
            }

            $parts .= $field . "\n" . (string) $payload[$field] . "\n";
        }

        return $parts;
    }

    private function isAmazonCertificateUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return false;
        }

        return preg_match(self::CERTIFICATE_HOST, strtolower($parts['host'])) === 1;
    }

    /** @param array<string,mixed> $payload */
    private function isFresh(array $payload): bool
    {
        $timestamp = (string) ($payload['Timestamp'] ?? '');

        if ($timestamp === '') {
            $this->reject('no timestamp');

            return false;
        }

        try {
            $sentAt = new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            $this->reject('unreadable timestamp', ['timestamp' => $timestamp]);

            return false;
        }

        $age = abs($this->clock->now()->getTimestamp() - $sentAt->getTimestamp());

        if ($age > self::MAX_AGE_SECONDS) {
            $this->reject('message is too old to accept', ['age_seconds' => $age]);

            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $context */
    private function reject(string $reason, array $context = []): void
    {
        // Logged at warning, not error: a rejected webhook is usually a scanner,
        // and a page of errors nobody can act on trains people to ignore the log.
        $this->logger->warning('Rejected an SNS message: ' . $reason, $context);
    }
}
