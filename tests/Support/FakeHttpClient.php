<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\HttpClient;

/**
 * Scripted HTTP, so the SES paths can be tested without an AWS account —
 * including the failure paths, which are the ones that matter and which a live
 * account will not produce on demand.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:string}> */
    public array $requests = [];

    /** @var array<int,array{status:int,body:string,error:?string}> */
    private array $responses = [];

    public function queue(int $status, string $body = '', ?string $error = null): self
    {
        $this->responses[] = ['status' => $status, 'body' => $body, 'error' => $error];

        return $this;
    }

    /** @param array<string,mixed> $payload */
    public function queueJson(int $status, array $payload): self
    {
        return $this->queue($status, (string) json_encode($payload));
    }

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
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        return array_shift($this->responses)
            ?? ['status' => 0, 'body' => '', 'error' => 'No response was queued for this request.'];
    }

    /** @return array{method:string,url:string,headers:array<string,string>,body:string} */
    public function lastRequest(): array
    {
        return $this->requests[count($this->requests) - 1]
            ?? ['method' => '', 'url' => '', 'headers' => [], 'body' => ''];
    }
}
