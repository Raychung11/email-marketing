<?php

declare(strict_types=1);

return [
    'driver' => env('QUEUE_DRIVER', 'redis'),

    'redis' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD', ''),
        'database' => (int) env('REDIS_DB', 0),
        'prefix'   => 'aigh:queue:',
    ],

    // Priority order matters: a worker listening to several queues drains them
    // left to right, so transactional mail never waits behind a 100k blast.
    'queues' => [
        'email_high_priority',
        'email_transactional',
        'email_marketing',
        'email_retry',
        'automation',
        'webhooks',
        'imports',
        'analytics',
    ],

    // Where campaign sends are enqueued. Named here rather than in the
    // dispatcher so an operator can move a noisy tenant onto its own queue
    // without a code change.
    'campaign_queue' => env('QUEUE_CAMPAIGN', 'email_marketing'),

    // Exponential backoff, then dead-letter. Permanent failures are not retried.
    'retry_backoff' => [60, 300, 1800, 7200],
    'max_attempts'  => 4,

    'worker' => [
        'sleep'           => 1,
        'max_jobs'        => 500,
        'memory_limit_mb' => 256,
        'timeout'         => 120,
    ],
];
