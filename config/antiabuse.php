<?php

declare(strict_types=1);

/*
 * Anti-abuse. This platform sends email on behalf of strangers; a single
 * careless tenant damages deliverability for everyone on the shared IP pool.
 * New accounts therefore start conservative and earn volume.
 */

return [
    'trust_levels' => [
        'new' => [
            'label'              => 'New',
            'daily_send_limit'   => (int) env('SENDING_LIMIT_NEW_DAILY', 500),
            'max_import_rows'    => 5_000,
            'requires_approval'  => true,
        ],
        'verified' => [
            'label'              => 'Verified',
            'daily_send_limit'   => (int) env('SENDING_LIMIT_VERIFIED_DAILY', 5_000),
            'max_import_rows'    => 50_000,
            'requires_approval'  => false,
        ],
        'trusted' => [
            'label'              => 'Trusted',
            'daily_send_limit'   => (int) env('SENDING_LIMIT_TRUSTED_DAILY', 250_000),
            'max_import_rows'    => 500_000,
            'requires_approval'  => false,
        ],
    ],

    'default_trust_level' => 'new',

    // Promotion to 'verified' requires all of these.
    'verification_requirements' => [
        'domain_verified'      => true,
        'min_account_age_days' => 3,
        'max_bounce_rate'      => 0.05,
        'max_complaint_rate'   => 0.001,
    ],

    /*
     * Risk score inputs (0-100; higher is riskier). A campaign above
     * `auto_pause_threshold` is paused automatically and queued for review
     * rather than sent.
     */
    'risk_weights' => [
        'account_age_under_7_days' => 20,
        'domain_unverified'        => 25,
        'bounce_rate_high'         => 20,
        'complaint_rate_high'      => 25,
        'import_source_unknown'    => 15,
        'volume_spike'             => 15,
        'first_large_send'         => 10,
    ],

    'auto_pause_threshold' => 60,

    'alerts' => [
        'bounce_rate'    => (float) env('BOUNCE_RATE_ALERT', 0.05),
        'complaint_rate' => (float) env('COMPLAINT_RATE_ALERT', 0.001),
        'volume_spike_multiplier' => 5.0,
        'quota_utilisation'       => 0.8,
    ],
];
