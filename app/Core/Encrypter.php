<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Authenticated symmetric encryption (AES-256-GCM) for the few values that must
 * live in the database but must not be readable from a database dump alone —
 * for example a tenant's provider credentials. Application secrets themselves
 * always come from the environment and are never stored.
 */
final class Encrypter
{
    private const CIPHER = 'aes-256-gcm';

    private string $key;

    public function __construct(string $appKey)
    {
        $this->key = self::deriveKey($appKey);
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): ?string
    {
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $iv         = substr($raw, 0, 12);
        $tag        = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? null : $plaintext;
    }

    private static function deriveKey(string $appKey): string
    {
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is not set. Run: php cron/console.php key:generate');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        return hash('sha256', $appKey, true);
    }
}
