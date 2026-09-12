<?php

declare(strict_types=1);

namespace App\Support;

final class Str
{
    public static function random(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function limit(string $value, int $length = 100, string $end = '…'): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length) . $end;
    }

    public static function initials(string ...$parts): string
    {
        $initials = '';

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }

        return $initials === '' ? '?' : mb_substr($initials, 0, 2);
    }

    /** Mask an email for display in logs and audit trails. */
    public static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');

        if ($at === false || $at < 1) {
            return '***';
        }

        $local  = substr($email, 0, $at);
        $domain = substr($email, $at);

        return mb_substr($local, 0, 1) . str_repeat('*', max(1, mb_strlen($local) - 1)) . $domain;
    }

    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    /**
     * Strip characters that would let a value break out of a CSV cell into a
     * spreadsheet formula. Applied to every exported field.
     */
    public static function csvSafe(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
