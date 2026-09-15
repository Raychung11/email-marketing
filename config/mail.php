<?php

declare(strict_types=1);

return [
    // Providers are interchangeable behind App\Mail\EmailProviderInterface.
    'provider' => env('MAIL_PROVIDER', 'ses'),

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
        'name'    => env('MAIL_FROM_NAME', 'AI Growth Hub'),
    ],

    'providers' => [
        'ses' => [
            'region'            => env('AWS_REGION', 'us-east-1'),
            'key'               => env('AWS_ACCESS_KEY_ID', ''),
            'secret'            => env('AWS_SECRET_ACCESS_KEY', ''),
            'configuration_set' => env('AWS_SES_CONFIGURATION_SET', ''),
            'sns_topic_arn'     => env('AWS_SES_SNS_TOPIC_ARN', ''),
            // Only for temporary credentials from STS. Long-lived keys leave it empty.
            'session_token'     => env('AWS_SESSION_TOKEN', ''),
            'timeout'           => (int) env('AWS_SES_TIMEOUT', 30),
        ],
        'log' => [
            'path' => base_path('storage/logs/mail.log'),
        ],
    ],

    // SES quotas differ per account and per Region, and sandbox accounts are
    // heavily restricted. These are only a starting point: the real limit is
    // read from the provider at runtime via getQuota() and throttled against.
    'throttle' => [
        'default_per_second' => (int) env('MAIL_RATE_PER_SECOND', 14),
        'respect_provider_quota' => true,
        // The scheduler runs once a minute, so this is how much of the send rate
        // one tick is allowed to enqueue. Enqueueing is not sending, but the
        // workers drain as fast as they can, so bounding the queue is what
        // actually keeps the provider's rate limit intact.
        'dispatch_window_seconds' => 60,
    ],

    'tracking' => [
        'open_pixel'      => true,
        'click_wrapping'  => true,
        // Opens are unreliable: privacy proxies in modern mail clients pre-fetch
        // images. Clicks and conversions are weighted higher everywhere in BI.
        'open_confidence' => 'low',
    ],
];
