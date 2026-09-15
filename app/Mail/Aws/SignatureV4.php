<?php

declare(strict_types=1);

namespace App\Mail\Aws;

/**
 * AWS Signature Version 4.
 *
 * Written out rather than pulled in with the AWS SDK because the SDK is 60MB
 * and several thousand files for four API calls, and this application has no
 * runtime dependencies anywhere else. The algorithm is public and stable; the
 * risk is in getting it subtly wrong, which is what the test vectors are for —
 * SignatureV4Test checks this against AWS's own published expected values, not
 * against itself.
 *
 * Reference: "Signature Version 4 signing process", AWS General Reference.
 */
final class SignatureV4
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $region,
        private readonly string $service,
        private readonly string $sessionToken = '',
        /**
         * Every AWS service signs a double-encoded path except S3, which signs
         * the path exactly as sent. Getting this wrong is invisible until a
         * request carries a character that needs encoding at all.
         */
        private readonly bool $doubleEncodePath = true,
    ) {
    }

    /**
     * Returns the headers to send, including Authorization.
     *
     * @param array<string,string> $headers Headers that are part of the request.
     * @return array<string,string>
     */
    public function sign(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        ?int $timestamp = null
    ): array {
        $parts = parse_url($url);
        $host  = (string) ($parts['host'] ?? '');
        $path  = (string) ($parts['path'] ?? '/');
        $query = (string) ($parts['query'] ?? '');

        $timestamp ??= time();
        $amzDate    = gmdate('Ymd\THis\Z', $timestamp);
        $dateStamp  = gmdate('Ymd', $timestamp);

        // The signature covers the host and the date, so both must be signed
        // headers. A signed request replayed against another host will not
        // verify, which is exactly the property we want.
        $headers['Host']       = $host;
        $headers['X-Amz-Date'] = $amzDate;

        if ($this->sessionToken !== '') {
            $headers['X-Amz-Security-Token'] = $this->sessionToken;
        }

        // The payload hash is the last line of the canonical request, so the body
        // is signed whether or not it also appears as a header. S3 additionally
        // requires x-amz-content-sha256 as a signed header; SES does not, and
        // adding it would put this implementation out of step with the published
        // test vectors for no gain.
        $payloadHash = hash('sha256', $body);

        [$canonicalHeaders, $signedHeaders] = $this->canonicalHeaders($headers);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalPath($path),
            $this->canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $dateStamp . '/' . $this->region . '/' . $this->service . '/aws4_request';

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));

        $headers['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKeyId,
            $scope,
            $signedHeaders,
            $signature
        );

        return $headers;
    }

    /**
     * The derived signing key. Chained HMACs mean the key that signs a request
     * is scoped to one day, one region and one service, so a leaked signature
     * cannot be replayed against another region or a later date.
     */
    public function signingKey(string $dateStamp): string
    {
        $key = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretAccessKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    /**
     * @param array<string,string> $headers
     * @return array{0:string,1:string}
     */
    private function canonicalHeaders(array $headers): array
    {
        $canonical = [];

        foreach ($headers as $name => $value) {
            // Lowercase the name, collapse runs of whitespace in the value, trim.
            // AWS compares the normalised form, so anything else fails to match.
            $canonical[strtolower(trim($name))] = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        }

        ksort($canonical, SORT_STRING);

        $lines = '';

        foreach ($canonical as $name => $value) {
            $lines .= $name . ':' . $value . "\n";
        }

        return [$lines, implode(';', array_keys($canonical))];
    }

    /**
     * The path, URI-encoded per segment — twice.
     *
     * This is the part of SigV4 most likely to be got wrong, because it is
     * invisible until a request carries a character that needs encoding. A path
     * of /v2/email/account signs identically either way; /v2/email/identities/
     * someone%40example.com does not, and the only symptom is
     * SignatureDoesNotMatch on that one call while every other call works.
     *
     * AWS builds the canonical request from a path encoded a second time on top
     * of what went on the wire, so an "@" travels as %40 and is signed as %2540.
     * S3 is the one exception and signs the path as sent.
     *
     * Decoding first means a caller who passes a raw "@" and one who passes an
     * already-encoded "%40" sign the same thing, which is what you want from a
     * function nobody should have to think about.
     */
    private function canonicalPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        $segments = array_map(
            function (string $segment): string {
                $once = rawurlencode(rawurldecode($segment));

                // After one pass the segment holds only unreserved characters
                // and %XX escapes, so a second pass is exactly escaping the
                // percent signs.
                return $this->doubleEncodePath ? str_replace('%', '%25', $once) : $once;
            },
            explode('/', $path)
        );

        return implode('/', $segments);
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $pairs[] = rawurlencode(rawurldecode($name)) . '=' . rawurlencode(rawurldecode($value));
        }

        // Sorted by the encoded name, then the encoded value.
        sort($pairs, SORT_STRING);

        return implode('&', $pairs);
    }
}
