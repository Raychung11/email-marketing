<?php

declare(strict_types=1);

return [
    'name'     => env('SESSION_NAME', 'aigh_session'),
    'lifetime' => (int) env('SESSION_LIFETIME', 7200),
    'domain'   => env('SESSION_DOMAIN', ''),

    // MUST be true in production: the cookie then only travels over TLS.
    'secure'   => (bool) env('SESSION_SECURE', false),
    'samesite' => env('SESSION_SAMESITE', 'Lax'),

    // Hard ceiling regardless of activity, and periodic id rotation to limit the
    // value of a stolen session id.
    'absolute_lifetime' => (int) env('SESSION_ABSOLUTE_LIFETIME', 86400),
    'regenerate_every'  => (int) env('SESSION_REGENERATE_EVERY', 900),
];
