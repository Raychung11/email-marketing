<?php

declare(strict_types=1);

namespace App\Mail\Aws;

use App\Support\HttpClient;

/**
 * The four SES v2 calls this application makes, over plain HTTPS.
 *
 * Deliberately shaped like the AWS SDK's SesV2Client for the methods it
 * replaces, returning the same array keys, so AmazonSesProvider reads identical
 * either way and anyone who prefers the SDK can swap back.
 *
 * Everything here is a REST call against email.<region>.amazonaws.com, signed
 * with SigV4. There is no state beyond the credentials.
 */
final class SesClient
{
    public function __construct(
        private readonly SignatureV4 $signer,
        private readonly HttpClient $http,
        private readonly string $region,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function sendEmail(array $request): array
    {
        return $this->call('POST', '/v2/email/outbound-emails', $request);
    }

    /**
     * @param array{EmailIdentity:string} $request
     * @return array<string,mixed>
     */
    public function getEmailIdentity(array $request): array
    {
        return $this->call('GET', '/v2/email/identities/' . rawurlencode((string) $request['EmailIdentity']));
    }

    /**
     * Registers a domain with SES and asks for Easy DKIM, which is what makes
     * SES generate the three CNAME tokens the customer publishes.
     *
     * @param array{EmailIdentity:string} $request
     * @return array<string,mixed>
     */
    public function createEmailIdentity(array $request): array
    {
        return $this->call('POST', '/v2/email/identities', [
            'EmailIdentity' => (string) $request['EmailIdentity'],
        ]);
    }

    /** @return array<string,mixed> */
    public function getAccount(): array
    {
        return $this->call('GET', '/v2/email/account');
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, ?array $payload = null): array
    {
        $url  = 'https://email.' . $this->region . '.amazonaws.com' . $path;
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

        $headers = $payload === null ? [] : ['Content-Type' => 'application/json'];
        $headers = $this->signer->sign($method, $url, $headers, $body);

        $response = $this->http->request($method, $url, $headers, $body, $this->timeoutSeconds);

        if ($response['error'] !== null) {
            throw new SesException('Could not reach Amazon SES: ' . $response['error'], 'TRANSIENT');
        }

        $decoded = json_decode($response['body'], true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return $decoded;
        }

        throw new SesException($this->errorMessage($decoded, $response), $this->errorCode($decoded, $response));
    }

    /**
     * SES puts the error type in a header on some responses and in the body on
     * others. Both are checked, because the type is what decides whether the
     * queue retries a job or gives up on it — and guessing wrong either burns
     * sending reputation on a doomed retry or drops a message that would have
     * gone through on the second attempt.
     *
     * @param array<string,mixed> $decoded
     * @param array{status:int,body:string,error:?string} $response
     */
    private function errorCode(array $decoded, array $response): string
    {
        $type = (string) ($decoded['__type'] ?? $decoded['code'] ?? '');

        if ($type !== '') {
            // "com.amazonaws.services...#MessageRejected" — the part after the
            // last separator is the name the provider matches on.
            $parts = preg_split('/[#:]/', $type) ?: [];

            return (string) end($parts);
        }

        return match (true) {
            $response['status'] === 429 => 'TooManyRequestsException',
            $response['status'] >= 500  => 'ServiceUnavailable',
            $response['status'] === 404 => 'NotFoundException',
            $response['status'] === 403 => 'AccessDeniedException',
            default                     => 'BadRequestException',
        };
    }

    /**
     * @param array<string,mixed> $decoded
     * @param array{status:int,body:string,error:?string} $response
     */
    private function errorMessage(array $decoded, array $response): string
    {
        $message = (string) ($decoded['message'] ?? $decoded['Message'] ?? '');

        if ($message === '') {
            $message = 'HTTP ' . $response['status'] . ' from Amazon SES.';
        }

        return $this->errorCode($decoded, $response) . ': ' . $message;
    }
}
