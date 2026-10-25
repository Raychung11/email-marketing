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
     * Deliberately its own setting rather than something inferred from the
     * queue driver. They are unrelated choices: a deployment can perfectly well
     * run the database queue on shared hosting and still need throttling that
     * outlives a request, and reading one from the other is how a production
     * site ended up with login throttling that reset on every page load.
     *
     *   auto     redis when it answers, otherwise the database table
     *   redis    require redis
     *   database require the rate_limits table
     *   array    in-process only; tests, never production
     */
    'rate_limit' => [
        'store' => env('RATE_LIMIT_STORE', 'auto'),
        // Lapsed buckets older than this are swept up by the scheduler.
        'prune_after_hours' => (int) env('RATE_LIMIT_PRUNE_AFTER_HOURS', 24),
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
