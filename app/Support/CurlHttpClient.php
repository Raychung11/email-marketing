<?php

declare(strict_types=1);

namespace App\Support;

final class CurlHttpClient implements HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,error:?string}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 30
    ): array {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'The curl extension is not installed.'];
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'error' => 'Could not start an HTTP request.'];
        }

        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_HTTPHEADER     => $formatted,
            // Never off. This request carries a signature derived from a secret
            // key; sending it down a connection we have not authenticated would
            // defeat the point of signing it.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Following a redirect would replay a signed request at a host the
            // signature was not computed for, and the signature covers the host.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error    = curl_error($handle);

        curl_close($handle);

        return [
            'status' => $status,
            'body'   => is_string($response) ? $response : '',
            'error'  => $error === '' ? null : $error,
        ];
    }
}
