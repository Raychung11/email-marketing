<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\FakeHttpFetcher;
use App\Support\HttpFetcher;
use Tests\Support\TestCase;

/**
 * §22 — Amazon SES delivery notifications, via SNS.
 *
 * This endpoint is public and unauthenticated, so the signature is the only
 * thing between an anonymous POST and our bounce handling. Someone who could
 * forge one could wipe out a competitor's ability to email their own customers.
 * The signatures below are real: a keypair is generated per test and the
 * certificate is served through a fake fetcher, so openssl_verify() does the
 * same work it does in production.
 */
final class SesWebhookTest extends TestCase
{
    private const TOPIC = 'arn:aws:sns:us-east-1:123456789012:ses-events';

    private const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem';

    private FakeHttpFetcher $http;

    /** @var \OpenSSLAsymmetricKey */
    private $privateKey;

    private string $certificate;

    public function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeHttpFetcher();
        $this->container->instance(HttpFetcher::class, $this->http);

        [$this->privateKey, $this->certificate] = $this->keypair();
        $this->http->stub(self::CERT_URL, $this->certificate);

        $this->configureTopic(self::TOPIC);
    }

    // ------------------------------------------------------- the signature

    public function testAForgedSignatureIsRejected(): void
    {
        $payload = $this->notification($this->bounce('victim@example.com'));
        $payload['Signature'] = base64_encode('not a real signature');

        $response = $this->postJson('/webhooks/aws/ses', $payload);

        $this->assertStatus(403, $response);
        $this->assertSame(0, $this->eventCount(), 'Nothing was recorded');
    }

    /**
     * The check people forget. The message names the certificate that signed it,
     * so an attacker who could point that anywhere would just sign their forgery
     * with their own key and hand us the matching certificate.
     */
    public function testACertificateUrlThatIsNotAmazonsIsRejected(): void
    {
        [$attackerKey, $attackerCert] = $this->keypair();

        foreach ([
            'https://sns.us-east-1.amazonaws.com.evil.test/cert.pem',
            'https://evil.test/sns.us-east-1.amazonaws.com/cert.pem',
            'http://sns.us-east-1.amazonaws.com/cert.pem',
            'https://s3.amazonaws.com/cert.pem',
        ] as $url) {
            $this->http->stub($url, $attackerCert);

            // Properly signed — with the wrong key. Only the URL check catches this.
            $payload = $this->notification($this->bounce('victim@example.com'), $url, $attackerKey);

            $response = $this->postJson('/webhooks/aws/ses', $payload);

            $this->assertStatus(403, $response, 'Accepted a certificate from ' . $url);
        }

        $this->assertSame(0, $this->eventCount());
    }

    public function testAValidlySignedMessageForAnotherTopicIsIgnored(): void
    {
        $this->seedSentMessage('customer@example.com', 'ses-msg-1');

        $payload = $this->notification(
            $this->bounce('customer@example.com'),
            self::CERT_URL,
            null,
            'arn:aws:sns:us-east-1:999999999999:someone-elses-topic'
        );

        $response = $this->postJson('/webhooks/aws/ses', $payload);

        // A valid Amazon signature only proves Amazon sent it. Anyone with an AWS
        // account can publish to their own topic and point it at us.
        $this->assertStatus(200, $response);
        $this->assertSame(0, $this->eventCount());
        $this->assertFalse($this->isOnDoNotEmailList('customer@example.com'));
    }

    public function testAReplayedMessageIsRejectedOnceItIsOld(): void
    {
        $this->seedSentMessage('customer@example.com', 'ses-msg-1');

        $payload = $this->notification($this->bounce('customer@example.com'));
        $payload['Timestamp'] = '2020-01-01T00:00:00.000Z';
        $payload['Signature'] = $this->sign($payload, $this->privateKey);

        $response = $this->postJson('/webhooks/aws/ses', $payload);

        $this->assertStatus(403, $response, 'A captured message stays valid for ever without this check');
        $this->assertSame(0, $this->eventCount());
    }

    // --------------------------------------------------------- the outcomes

    public function testAPermanentBounceSuppressesTheAddress(): void
    {
        $this->seedSentMessage('gone@example.com', 'ses-msg-1');

        $response = $this->postJson(
            '/webhooks/aws/ses',
            $this->notification($this->bounce('gone@example.com', 'Permanent', 'General'))
        );

        $this->assertStatus(200, $response);
        $this->assertTrue($this->isOnDoNotEmailList('gone@example.com'));
        $this->assertSame('hard_bounce', $this->suppressionReason('gone@example.com'));
        $this->assertSame('bounced', $this->messageStatus('ses-msg-1'));
    }

    /**
     * A full mailbox or a server having a bad afternoon is not a reason to stop
     * emailing somebody for ever.
     */
    public function testATransientBounceDoesNotSuppress(): void
    {
        $this->seedSentMessage('busy@example.com', 'ses-msg-1');

        $this->postJson(
            '/webhooks/aws/ses',
            $this->notification($this->bounce('busy@example.com', 'Transient', 'MailboxFull'))
        );

        $this->assertFalse($this->isOnDoNotEmailList('busy@example.com'));
        $this->assertSame('soft_bounced', $this->messageStatus('ses-msg-1'));
        $this->assertSame(1, $this->eventCount());
    }

    public function testAComplaintSuppressesImmediately(): void
    {
        $this->seedSentMessage('annoyed@example.com', 'ses-msg-1');

        $this->postJson(
            '/webhooks/aws/ses',
            $this->notification($this->complaint('annoyed@example.com'))
        );

        // No threshold and no second chance: somebody pressed "this is spam".
        $this->assertTrue($this->isOnDoNotEmailList('annoyed@example.com'));
        $this->assertSame('complaint', $this->suppressionReason('annoyed@example.com'));
    }

    public function testADeliveryMarksTheMessageDelivered(): void
    {
        $this->seedSentMessage('happy@example.com', 'ses-msg-1');

        $this->postJson(
            '/webhooks/aws/ses',
            $this->notification($this->delivery('happy@example.com'))
        );

        $this->assertSame('delivered', $this->messageStatus('ses-msg-1'));
    }

    /**
     * One notification, three bounced addresses. Recording it as a single event
     * would leave two of them still receiving mail.
     */
    public function testANotificationWithSeveralRecipientsBecomesSeveralEvents(): void
    {
        foreach (['one@example.com', 'two@example.com', 'three@example.com'] as $i => $email) {
            $this->seedSentMessage($email, 'ses-msg-' . $i, 'ses-shared');
        }

        $this->postJson('/webhooks/aws/ses', $this->notification([
            'notificationType' => 'Bounce',
            'mail'             => [
                'messageId'   => 'ses-shared',
                'destination' => ['one@example.com', 'two@example.com', 'three@example.com'],
            ],
            'bounce' => [
                'bounceType'        => 'Permanent',
                'bounceSubType'     => 'General',
                'bouncedRecipients' => [
                    ['emailAddress' => 'one@example.com'],
                    ['emailAddress' => 'two@example.com'],
                    ['emailAddress' => 'three@example.com'],
                ],
                'timestamp' => $this->now(),
            ],
        ]));

        $this->assertSame(3, $this->eventCount());

        foreach (['one@example.com', 'two@example.com', 'three@example.com'] as $email) {
            $this->assertTrue($this->isOnDoNotEmailList($email), $email . ' was not suppressed');
        }
    }

    /** Amazon redelivers. The second copy must change nothing. */
    public function testARedeliveredNotificationIsCountedOnce(): void
    {
        $this->seedSentMessage('gone@example.com', 'ses-msg-1');

        $payload = $this->notification($this->bounce('gone@example.com'));

        $this->postJson('/webhooks/aws/ses', $payload);
        $this->postJson('/webhooks/aws/ses', $payload);
        $this->postJson('/webhooks/aws/ses', $payload);

        $this->assertSame(1, $this->eventCount());
        $this->assertSame(
            1,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM suppressions')
        );
    }

    /** The organisation comes from our own records, never from the payload. */
    public function testTenancyIsResolvedFromOurRecordsNotThePayload(): void
    {
        $other = $this->createOrganisation(['name' => 'Someone Else']);
        $mine  = $this->createOrganisation(['name' => 'Mine']);

        $this->bindTenant($mine['organisation_id']);
        $this->seedSentMessage('gone@example.com', 'ses-msg-1');

        $payload = $this->notification($this->bounce('gone@example.com'));
        // A hostile payload naming the other tenant. The field is never read.
        $payload['organisation_id'] = $other['organisation_id'];
        $payload['Signature']       = $this->sign($payload, $this->privateKey);

        $this->postJson('/webhooks/aws/ses', $payload);

        $suppression = $this->connection->selectOne('SELECT * FROM suppressions LIMIT 1') ?? [];

        $this->assertSame($mine['organisation_id'], (int) $suppression['organisation_id']);
    }

    public function testAnUnknownProviderMessageIsIgnoredQuietly(): void
    {
        // A notification for a message we have no record of — a stale topic from a
        // previous installation, say. It must not error, and must not create rows.
        $response = $this->postJson(
            '/webhooks/aws/ses',
            $this->notification($this->bounce('stranger@example.com', 'Permanent', 'General', 'unknown-id'))
        );

        $this->assertStatus(200, $response);
        $this->assertSame(0, $this->eventCount());
    }

    // ------------------------------------------------------- subscription

    public function testSubscriptionConfirmationVisitsAmazonsUrl(): void
    {
        $subscribeUrl = 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc';
        $this->http->stub($subscribeUrl, '<ConfirmSubscriptionResponse/>');

        $payload = [
            'Type'             => 'SubscriptionConfirmation',
            'MessageId'        => 'sub-1',
            'Token'            => 'abc',
            'TopicArn'         => self::TOPIC,
            'Message'          => 'You have chosen to subscribe.',
            'SubscribeURL'     => $subscribeUrl,
            'Timestamp'        => $this->now(),
            'SignatureVersion' => '1',
            'SigningCertURL'   => self::CERT_URL,
        ];
        $payload['Signature'] = $this->sign($payload, $this->privateKey);

        $response = $this->postJson('/webhooks/aws/ses', $payload);

        $this->assertStatus(200, $response);
        $this->assertTrue(in_array($subscribeUrl, $this->http->requested(), true));
    }

    /**
     * Following an arbitrary URL out of a request body is how a webhook becomes
     * a tool for poking at somebody's internal network.
     */
    public function testSubscriptionConfirmationRefusesToVisitANonAmazonUrl(): void
    {
        $evil = 'https://internal.test/admin/delete-everything';

        $payload = [
            'Type'             => 'SubscriptionConfirmation',
            'MessageId'        => 'sub-1',
            'Token'            => 'abc',
            'TopicArn'         => self::TOPIC,
            'Message'          => 'You have chosen to subscribe.',
            'SubscribeURL'     => $evil,
            'Timestamp'        => $this->now(),
            'SignatureVersion' => '1',
            'SigningCertURL'   => self::CERT_URL,
        ];
        $payload['Signature'] = $this->sign($payload, $this->privateKey);

        $response = $this->postJson('/webhooks/aws/ses', $payload);

        $this->assertStatus(200, $response);
        $this->assertFalse(in_array($evil, $this->http->requested(), true), 'It fetched the attacker URL');
    }

    public function testGarbageIsAnsweredCalmly(): void
    {
        // SNS retries anything that is not a 2xx for hours. A body we have decided
        // to ignore will not become acceptable on the twentieth try.
        $response = $this->postRaw('/webhooks/aws/ses', 'this is not json at all');

        $this->assertStatus(200, $response);
    }

    // -------------------------------------------------------------- helpers

    /** @return array{0:\OpenSSLAsymmetricKey,1:string} */
    private function keypair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            $this->fail('Could not generate a keypair: ' . (string) openssl_error_string());
        }

        $csr         = openssl_csr_new(['commonName' => 'sns.us-east-1.amazonaws.com'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 1);

        openssl_x509_export($certificate, $pem);

        return [$key, (string) $pem];
    }

    /** @param array<string,mixed> $payload */
    private function sign(array $payload, mixed $key): string
    {
        $fields = $payload['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $canonical = '';

        foreach ($fields as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null) {
                continue;
            }

            $canonical .= $field . "\n" . (string) $payload[$field] . "\n";
        }

        $signature = '';
        openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA1);

        return base64_encode($signature);
    }

    /**
     * @param array<string,mixed> $message the SES notification body
     * @return array<string,mixed>
     */
    private function notification(
        array $message,
        string $certificateUrl = self::CERT_URL,
        mixed $key = null,
        string $topic = self::TOPIC,
    ): array {
        $payload = [
            'Type'             => 'Notification',
            'MessageId'        => 'sns-' . substr(hash('sha256', json_encode($message) . $topic), 0, 16),
            'TopicArn'         => $topic,
            'Message'          => (string) json_encode($message),
            'Timestamp'        => $this->now(),
            'SignatureVersion' => '1',
            'SigningCertURL'   => $certificateUrl,
        ];

        $payload['Signature'] = $this->sign($payload, $key ?? $this->privateKey);

        return $payload;
    }

    /** @return array<string,mixed> */
    private function bounce(
        string $email,
        string $type = 'Permanent',
        string $subType = 'General',
        string $providerMessageId = 'ses-msg-1',
    ): array {
        return [
            'notificationType' => 'Bounce',
            'mail'             => ['messageId' => $providerMessageId, 'destination' => [$email]],
            'bounce'           => [
                'bounceType'        => $type,
                'bounceSubType'     => $subType,
                'bouncedRecipients' => [['emailAddress' => $email]],
                'timestamp'         => $this->now(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function complaint(string $email, string $providerMessageId = 'ses-msg-1'): array
    {
        return [
            'notificationType' => 'Complaint',
            'mail'             => ['messageId' => $providerMessageId, 'destination' => [$email]],
            'complaint'        => [
                'complainedRecipients'   => [['emailAddress' => $email]],
                'complaintFeedbackType'  => 'abuse',
                'timestamp'              => $this->now(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function delivery(string $email, string $providerMessageId = 'ses-msg-1'): array
    {
        return [
            'notificationType' => 'Delivery',
            'mail'             => ['messageId' => $providerMessageId, 'destination' => [$email]],
            'delivery'         => ['recipients' => [$email], 'timestamp' => $this->now()],
        ];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.000\Z');
    }

    private function configureTopic(string $arn): void
    {
        /** @var \App\Core\Config $config */
        $config = $this->container->make(\App\Core\Config::class);
        $config->set('mail.providers.ses.sns_topic_arn', $arn);
    }

    private function seedSentMessage(
        string $email,
        string $uuidSuffix,
        string $providerMessageId = 'ses-msg-1',
    ): void {
        if (!$this->tenant->isBound()) {
            $context = $this->createOrganisation(['name' => 'Perth Plumbing Co']);
            $this->actingAs($context['user_id'], $context['organisation_id']);
        }

        $this->createContact(['email' => $email], ['status' => 'granted', 'consent_type' => 'express']);

        $this->connection->table('email_messages')->insert([
            'uuid'                => 'msg-' . $uuidSuffix,
            'organisation_id'     => $this->tenant->organisationId(),
            'contact_id'          => (int) ($this->connection->selectOne(
                'SELECT id FROM contacts WHERE email_normalized = ?',
                [normalize_email($email)]
            )['id'] ?? 0),
            'message_class'       => 'marketing',
            'provider'            => 'ses',
            'provider_message_id' => $providerMessageId,
            'email'               => $email,
            'email_normalized'    => normalize_email($email),
            'subject'             => 'Autumn service reminder',
            'status'              => 'sent',
            'sent_at'             => $this->clock->nowString(),
            'created_at'          => $this->clock->nowString(),
        ]);
    }

    private function eventCount(): int
    {
        return (int) $this->connection->scalar('SELECT COUNT(*) FROM email_events');
    }

    private function isOnDoNotEmailList(string $email): bool
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM suppressions WHERE email_normalized = ? AND removed_at IS NULL',
            [normalize_email($email)]
        ) > 0;
    }

    private function suppressionReason(string $email): string
    {
        return (string) $this->connection->scalar(
            'SELECT reason FROM suppressions WHERE email_normalized = ? AND removed_at IS NULL',
            [normalize_email($email)]
        );
    }

    private function messageStatus(string $providerMessageId): string
    {
        return (string) $this->connection->scalar(
            'SELECT status FROM email_messages WHERE provider_message_id = ?',
            [$providerMessageId]
        );
    }
}
