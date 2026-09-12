<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HMAC-signed, URL-safe payload tokens.
 *
 * Used for unsubscribe links, preference-centre links and any other public URL
 * that must identify a record without exposing an internal id. The payload is
 * base64url JSON plus a keyed signature; it is readable but not forgeable, and
 * it never contains a raw primary key (we carry a UUID instead).
 */
final class Signer
{
    public function __construct(private readonly string $key)
    {
        if ($this->key === '') {
            throw new \RuntimeException('Signer requires a non-empty application key.');
        }
    }

    /** @param array<string,mixed> $payload */
    public function sign(array $payload, ?int $ttlSeconds = null): string
    {
        if ($ttlSeconds !== null) {
            $payload['exp'] = time() + $ttlSeconds;
        }

        $json    = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $encoded = $this->b64encode((string) $json);
        $mac     = $this->mac($encoded);

        return $encoded . '.' . $mac;
    }

    /**
     * @return array<string,mixed>|null null when the signature is invalid,
     *                                  malformed, or the token has expired.
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $mac] = $parts;

        if (!hash_equals($this->mac($encoded), $mac)) {
            return null;
        }

        $json = $this->b64decode($encoded);

        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && time() > (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private function mac(string $data): string
    {
        return $this->b64encode(hash_hmac('sha256', $data, $this->key, true));
    }

    private function b64encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64decode(string $encoded): ?string
    {
        $padded  = strtr($encoded, '-_', '+/');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
