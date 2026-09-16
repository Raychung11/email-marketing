<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AmazonSesProvider;
use App\Mail\OutboundMessage;
use Tests\Support\FakeHttpClient;
use Tests\Support\TestCase;

/**
 * The SES provider, driven against scripted HTTP.
 *
 * Every one of these is a case a live AWS account will not produce on demand —
 * a throttle, a paused account, an identity that does not exist yet — and those
 * are precisely the ones whose handling decides whether a campaign retries
 * sensibly or burns its sending reputation.
 */
final class SesProviderTest extends TestCase
{
    private function provider(FakeHttpClient $http, array $overrides = []): AmazonSesProvider
    {
        return new AmazonSesProvider(
            [
                'region' => 'ap-southeast-2',
                'key'    => 'AKIDEXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            ] + $overrides,
            null,
            $http
        );
    }

    private function message(): OutboundMessage
    {
        return new OutboundMessage(
            toEmail: 'sarah@example.com',
            subject: 'Your quote is ready',
            htmlBody: '<p>Hello</p>',
            textBody: 'Hello',
            fromEmail: 'hello@rsresearch.my',
            fromName: 'RS Research',
        );
    }

    public function testAProviderWithoutCredentialsRefusesRatherThanPretending(): void
    {
        $provider = new AmazonSesProvider(['region' => 'ap-southeast-2'], null, new FakeHttpClient());

        $this->assertFalse($provider->isConfigured());

        $result = $provider->send($this->message());

        $this->assertFalse($result->accepted, 'Nothing is reported as sent that was never sent');
        $this->assertSame('SES_NOT_CONFIGURED', $result->errorCode);
    }

    public function testASendGoesToTheRegionalEndpointSignedAndAsOneRecipient(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['MessageId' => '0100018f-abc']);

        $result = $this->provider($http)->send($this->message());

        $this->assertTrue($result->accepted);
        $this->assertSame('0100018f-abc', $result->providerMessageId);

        $request = $http->lastRequest();

        $this->assertSame('POST', $request['method']);
        $this->assertSame(
            'https://email.ap-southeast-2.amazonaws.com/v2/email/outbound-emails',
            $request['url'],
            'The endpoint follows the configured region rather than a hardcoded one'
        );
        $this->assertContainsString('AWS4-HMAC-SHA256', $request['headers']['Authorization'] ?? '');
        $this->assertContainsString('/ap-southeast-2/ses/aws4_request', $request['headers']['Authorization'] ?? '');

        $body = json_decode($request['body'], true);

        // One recipient per call, always. BCC and multi-recipient sends are how
        // one person's unsubscribe fails to apply to the others.
        $this->assertSame(['sarah@example.com'], $body['Destination']['ToAddresses']);
        $this->assertCount(1, $body['Destination']['ToAddresses']);
    }

    public function testAThrottleIsRetriedAndARejectionIsNot(): void
    {
        $throttled = $this->provider(
            (new FakeHttpClient())->queueJson(429, ['__type' => 'TooManyRequestsException', 'message' => 'Maximum sending rate exceeded.'])
        )->send($this->message());

        $this->assertFalse($throttled->accepted);
        $this->assertTrue($throttled->retryable, 'Throttling is worth a backed-off retry');

        $rejected = $this->provider(
            (new FakeHttpClient())->queueJson(400, ['__type' => 'com.amazonaws.ses#MessageRejected', 'message' => 'Email address is not verified.'])
        )->send($this->message());

        $this->assertFalse($rejected->accepted);
        $this->assertFalse($rejected->retryable, 'A rejection will say the same thing four more times');
        $this->assertSame('MessageRejected', $rejected->errorCode, 'The namespaced type is reduced to the name');
    }

    public function testAPausedAccountIsPermanentRatherThanRetriedForever(): void
    {
        $result = $this->provider(
            (new FakeHttpClient())->queueJson(400, [
                '__type'  => 'AccountSendingPausedException',
                'message' => 'Your account is currently paused.',
            ])
        )->send($this->message());

        $this->assertFalse($result->retryable, 'Retrying a paused account achieves nothing four times over');
    }

    public function testAConnectionFailureIsTransientNotAnEndorsement(): void
    {
        $result = $this->provider(
            (new FakeHttpClient())->queue(0, '', 'Could not resolve host')
        )->send($this->message());

        $this->assertFalse($result->accepted, 'A message that never left is never reported as sent');
        $this->assertTrue($result->retryable);
    }

    public function testAnUnknownDomainIsRegisteredWithSesSoItsDkimTokensExist(): void
    {
        // The bug this covers: asking about an identity SES has never seen
        // returned a 404, the wizard showed SPF and DMARC with no DKIM, the
        // customer published them, and verification could never pass — because
        // DKIM alone decides it.
        $http = (new FakeHttpClient())
            ->queueJson(404, ['__type' => 'NotFoundException', 'message' => 'Identity does not exist.'])
            ->queueJson(200, ['DkimAttributes' => ['Status' => 'PENDING', 'Tokens' => ['aaa', 'bbb', 'ccc']]]);

        $records = $this->provider($http)->dnsRecordsFor('rsresearch.my');

        $this->assertSame('POST', $http->requests[1]['method'], 'The identity is created, not just asked about');
        $this->assertSame(
            'https://email.ap-southeast-2.amazonaws.com/v2/email/identities',
            $http->requests[1]['url']
        );

        $cnames = array_values(array_filter(
            $records,
            static fn (array $record): bool => $record['type'] === 'CNAME'
        ));

        $this->assertCount(3, $cnames, 'All three DKIM CNAMEs are returned');
        $this->assertSame('aaa._domainkey.rsresearch.my', $cnames[0]['name']);
        $this->assertSame('aaa.dkim.amazonses.com', $cnames[0]['value']);

        $types = array_column($records, 'type');
        $this->assertContainsString('TXT', implode(',', $types), 'SPF and DMARC are there too');
    }

    public function testAnAlreadyKnownDomainIsNotReRegistered(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, [
            'DkimAttributes' => ['Status' => 'SUCCESS', 'Tokens' => ['x1', 'x2', 'x3']],
            'VerifiedForSendingStatus' => true,
        ]);

        $this->provider($http)->dnsRecordsFor('rsresearch.my');

        $this->assertCount(1, $http->requests, 'One call when the identity already exists');
        $this->assertSame('GET', $http->requests[0]['method']);
    }

    public function testAnUnknownQuotaIsTreatedAsNoHeadroomRatherThanUnlimited(): void
    {
        $quota = $this->provider(
            (new FakeHttpClient())->queueJson(500, ['message' => 'Internal error'])
        )->getQuota();

        // Failing open here would let a transient API error turn into a flood.
        $this->assertSame(0.0, $quota->maxSendRate);
        $this->assertTrue($quota->sandbox);
    }

    public function testSandboxIsReportedSoTheWorkerThrottlesToIt(): void
    {
        $quota = $this->provider(
            (new FakeHttpClient())->queueJson(200, [
                'SendQuota' => ['Max24HourSend' => 200, 'MaxSendRate' => 1, 'SentLast24Hours' => 12],
                'ProductionAccessEnabled' => false,
            ])
        )->getQuota();

        $this->assertSame(1.0, $quota->maxSendRate);
        $this->assertTrue($quota->sandbox, 'A sandbox account must not be treated as production');
    }
}
