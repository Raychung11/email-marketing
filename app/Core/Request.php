<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish HTTP request wrapper.
 *
 * Nothing in the application reads $_GET/$_POST/$_SERVER directly, so input
 * handling (and therefore escaping and validation) has exactly one entry point.
 */
final class Request
{
    /** @var array<string,string> */
    private array $routeParams = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,mixed> $cookies
     * @param array<string,mixed> $files
     */
    public function __construct(
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly string $rawBody = '',
    ) {
    }

    public static function capture(): self
    {
        return new self(
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
            $_FILES,
            (string) (file_get_contents('php://input') ?: '')
        );
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));

        // Support method spoofing for HTML forms, but only from POST.
        if ($method === 'POST') {
            $override = (string) ($this->post['_method'] ?? '');
            if (in_array(strtoupper($override), ['PUT', 'PATCH', 'DELETE'], true)) {
                return strtoupper($override);
            }
        }

        return $method;
    }

    public function path(): string
    {
        $uri  = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        return '/' . trim($path, '/');
    }

    public function uri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && $this->server['HTTPS'] !== 'off') {
            return true;
        }

        return strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function host(): string
    {
        return (string) ($this->server['HTTP_HOST'] ?? 'localhost');
    }

    public function ip(): string
    {
        // Trust the proxy header only when the deployment declares a proxy.
        $trustProxy = (bool) Env::get('TRUST_PROXY', false);

        if ($trustProxy) {
            $forwarded = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));

        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }

        // CONTENT_TYPE / CONTENT_LENGTH are not HTTP_ prefixed.
        $alt = str_replace('-', '_', strtoupper($name));

        return isset($this->server[$alt]) ? (string) $this->server[$alt] : $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }

        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        $json = $this->json();

        return $json[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<int,string> */
    public function array(string $key): array
    {
        $value = $this->input($key, []);

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $value));
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->json());
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $contentType = (string) ($this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? '');

        if (!str_contains($contentType, 'json') || $this->rawBody === '') {
            return [];
        }

        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<string,mixed>|null */
    public function file(string $name): ?array
    {
        $file = $this->files[$name] ?? null;

        return is_array($file) ? $file : null;
    }

    public function expectsJson(): bool
    {
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');

        return str_contains($accept, 'application/json')
            || str_starts_with($this->path(), '/api/')
            || strtolower((string) ($this->server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /** @param array<string,string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    /** @return array<string,string> */
    public function routeParams(): array
    {
        return $this->routeParams;
    }
}
