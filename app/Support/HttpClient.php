<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An HTTP request with a method, headers and a body.
 *
 * HttpFetcher next door is GET-only and exists for SNS certificate retrieval.
 * Signing a request for an API means controlling the method, the headers and
 * the exact bytes of the body, because all three are part of what gets signed.
 */
interface HttpClient
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
    ): array;
}
