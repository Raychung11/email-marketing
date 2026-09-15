<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Logger;

final class CurlHttpFetcher implements HttpFetcher
{
    /** Fetched certificates are reused within a request; SNS rotates them rarely. */
    private array $cache = [];

    public function __construct(private readonly ?Logger $logger = null)
    {
    }

    public function get(string $url, int $timeoutSeconds = 5): ?string
    {
        if (array_key_exists($url, $this->cache)) {
            return $this->cache[$url];
        }

        if (!function_exists('curl_init')) {
            return $this->cache[$url] = null;
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return $this->cache[$url] = null;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            // Certificate verification stays on. This request exists to establish
            // trust; doing it over a connection we have not verified would be
            // theatre.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Redirects are refused: the caller allow-lists the host, and a
            // redirect would move the request somewhere it never checked.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
        ]);

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error  = curl_error($handle);

        curl_close($handle);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            $this->logger?->warning('HTTP fetch failed', [
                'url'    => $url,
                'status' => $status,
                'error'  => $error,
            ]);

            return $this->cache[$url] = null;
        }

        return $this->cache[$url] = $body;
    }
}
