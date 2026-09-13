<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\Aws\SignatureV4;
use Tests\Support\TestCase;

/**
 * Signing is the one part of talking to AWS that cannot be "nearly right".
 *
 * The first two tests are known answers from AWS's own published material — the
 * documented signing-key derivation and the get-vanilla case from the
 * aws-sig-v4-test-suite. They are what separates a correct implementation from
 * one that merely agrees with itself. The rest assert the properties the
 * signature exists to provide.
 */
final class SignatureV4Test extends TestCase
{
    private const KEY    = 'AKIDEXAMPLE';
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    /** 2015-08-30T12:36:00Z, the timestamp the published vectors use. */
    private const WHEN = 1440938160;

    private function signer(string $service = 'service', string $token = ''): SignatureV4
    {
        return new SignatureV4(self::KEY, self::SECRET, 'us-east-1', $service, $token);
    }

    public function testTheDerivedSigningKeyMatchesTheValuePublishedByAws(): void
    {
        $this->assertSame(
            'c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9',
            bin2hex($this->signer('iam')->signingKey('20150830')),
            'The chained HMAC derivation produces AWS\'s documented key'
        );
    }

    public function testTheGetVanillaVectorProducesThePublishedSignature(): void
    {
        $headers = $this->signer()->sign('GET', 'https://example.amazonaws.com/', [], '', self::WHEN);

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['Authorization'],
            'The whole Authorization header matches the aws-sig-v4-test-suite vector'
        );
    }

    public function testHostAndDateAreAlwaysSigned(): void
    {
        $headers = $this->signer()->sign('POST', 'https://email.us-east-1.amazonaws.com/v2/email/account', [], '{}', self::WHEN);

        $this->assertSame('email.us-east-1.amazonaws.com', $headers['Host']);
        $this->assertSame('20150830T123600Z', $headers['X-Amz-Date']);
        $this->assertContainsString('host', $headers['Authorization']);
        $this->assertContainsString('x-amz-date', $headers['Authorization']);
    }

    public function testChangingTheBodyChangesTheSignature(): void
    {
        $signer = $this->signer();
        $url    = 'https://email.us-east-1.amazonaws.com/v2/email/outbound-emails';

        $first  = $signer->sign('POST', $url, [], '{"to":"alice@example.com"}', self::WHEN);
        $second = $signer->sign('POST', $url, [], '{"to":"mallory@example.com"}', self::WHEN);

        // If the body were not covered, an intermediary could redirect a send.
        $this->assertNotSame(
            $first['Authorization'],
            $second['Authorization'],
            'The payload hash is part of what is signed'
        );
    }

    public function testASignatureIsBoundToOneHost(): void
    {
        $signer = $this->signer();

        $real = $signer->sign('POST', 'https://email.us-east-1.amazonaws.com/v2/email/account', [], '{}', self::WHEN);
        $fake = $signer->sign('POST', 'https://email.us-east-1.amazonaws.com.evil.test/v2/email/account', [], '{}', self::WHEN);

        $this->assertNotSame(
            $real['Authorization'],
            $fake['Authorization'],
            'A captured signature cannot be replayed against another host'
        );
    }

    public function testASignatureIsScopedToOneRegionAndService(): void
    {
        $one = new SignatureV4(self::KEY, self::SECRET, 'us-east-1', 'ses');
        $two = new SignatureV4(self::KEY, self::SECRET, 'ap-southeast-2', 'ses');

        $this->assertNotSame(
            bin2hex($one->signingKey('20150830')),
            bin2hex($two->signingKey('20150830')),
            'A key leaked for one region is useless in another'
        );

        $later = new SignatureV4(self::KEY, self::SECRET, 'us-east-1', 'ses');

        $this->assertNotSame(
            bin2hex($one->signingKey('20150830')),
            bin2hex($later->signingKey('20150831')),
            'And useless the next day'
        );
    }

    public function testHeaderOrderDoesNotMatterButHeaderValuesDo(): void
    {
        $signer = $this->signer();
        $url    = 'https://email.us-east-1.amazonaws.com/v2/email/account';

        $a = $signer->sign('POST', $url, ['Content-Type' => 'application/json', 'X-Custom' => 'one'], '{}', self::WHEN);
        $b = $signer->sign('POST', $url, ['X-Custom' => 'one', 'Content-Type' => 'application/json'], '{}', self::WHEN);

        $this->assertSame($a['Authorization'], $b['Authorization'], 'Headers are canonically sorted');

        $c = $signer->sign('POST', $url, ['Content-Type' => 'application/json', 'X-Custom' => 'two'], '{}', self::WHEN);

        $this->assertNotSame($a['Authorization'], $c['Authorization'], 'But their values are signed');
    }

    public function testWhitespaceInAHeaderValueIsNormalisedRatherThanBreakingTheSignature(): void
    {
        $signer = $this->signer();
        $url    = 'https://email.us-east-1.amazonaws.com/v2/email/account';

        $tidy  = $signer->sign('POST', $url, ['X-Note' => 'a b'], '{}', self::WHEN);
        $messy = $signer->sign('POST', $url, ['X-Note' => "  a   b  "], '{}', self::WHEN);

        // AWS compares the collapsed form. Not collapsing it here produces a
        // signature mismatch that reads as a credentials problem.
        $this->assertSame($tidy['Authorization'], $messy['Authorization']);
    }

    public function testQueryParametersAreSortedSoOrderingCannotBreakTheSignature(): void
    {
        $signer = $this->signer();

        $a = $signer->sign('GET', 'https://example.amazonaws.com/?PageSize=10&NextToken=abc', [], '', self::WHEN);
        $b = $signer->sign('GET', 'https://example.amazonaws.com/?NextToken=abc&PageSize=10', [], '', self::WHEN);

        $this->assertSame($a['Authorization'], $b['Authorization']);
    }

    public function testATemporaryCredentialSignsItsSessionToken(): void
    {
        $withToken = $this->signer('ses', 'FQoGZXIvYXdzEXAMPLETOKEN')
            ->sign('POST', 'https://email.us-east-1.amazonaws.com/v2/email/account', [], '{}', self::WHEN);

        $this->assertSame('FQoGZXIvYXdzEXAMPLETOKEN', $withToken['X-Amz-Security-Token']);
        $this->assertContainsString(
            'x-amz-security-token',
            $withToken['Authorization'],
            'The token is signed, not merely sent alongside'
        );
    }
}
