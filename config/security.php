<?php

declare(strict_types=1);

return [
    'bcrypt_cost' => (int) env('BCRYPT_COST', 12),

    'password' => [
        'min_length' => 12,
    ],

    'login' => [
        'max_attempts'  => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('LOGIN_DECAY_SECONDS', 900),
    ],

    'password_reset' => [
        'ttl' => (int) env('PASSWORD_RESET_TTL', 3600),
    ],

    'api' => [
        'rate_limit'    => (int) env('API_RATE_LIMIT', 120),
        'rate_window'   => 60,
        'key_prefix_len' => 8,
    ],

    'public_endpoints' => [
        // Unsubscribe and tracking endpoints are public but rate limited so they
        // cannot be used to enumerate or to hammer the app.
        'rate_limit'  => 60,
        'rate_window' => 60,
    ],

    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'X-XSS-Protection'       => '0',
    ],

    // Only these hosts may be used as a click-tracking redirect target beyond
    // the organisation's own verified domains. Empty means "organisation
    // domains plus the campaign's declared links only".
    'redirect_allowlist' => [],
];
