<?php

declare(strict_types=1);

return [
    'bcrypt_cost' => (int) env('BCRYPT_COST', 12),

    'password' => [
        'min_length' => 12,
    ],

    /*
     * Where rate-limit counters live.
     *
     * DELIBERATELY NOT THE QUEUE DRIVER. It used to be: anything but Redis got
     * an in-process array, so every deployment running the database queue —
     * which on shared hosting is every deployment — had login throttling, API
     * key limits and public endpoint limits that silently did nothing, because
     * each HTTP request started with an empty array.
     *
     *   auto      Redis when the extension is loaded and configured, database
     *             otherwise. The right answer for nearly everybody.
     *   redis     insist on Redis.
     *   database  insist on the database. Works anywhere the app works.
     *   array     in-process only. Fine for tests, useless across requests, and
     *             refused in production unless force_insecure_store is set.
     */
    'rate_limit' => [
        'store' => env('RATE_LIMIT_STORE', 'auto'),

        /*
         * The escape hatch, and it is named so nobody sets it by accident.
         * Production with an in-process limiter is production with no limiter.
         */
        'force_insecure_store' => (bool) env('RATE_LIMIT_ALLOW_INSECURE', false),
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
