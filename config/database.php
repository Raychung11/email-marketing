<?php

declare(strict_types=1);

return [
    'default' => env('DB_DRIVER', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => (int) env('DB_PORT', 3306),
            'database'  => env('DB_DATABASE', 'ai_growth_hub'),
            'username'  => env('DB_USERNAME', 'root'),
            'password'  => env('DB_PASSWORD', ''),
            'charset'   => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_0900_ai_ci'),
        ],

        // Used by the test suite. MySQL remains the canonical production schema;
        // the schema builder renders both from the same migration files.
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_SQLITE_PATH', ':memory:'),
        ],
    ],

    'migrations_path' => base_path('database/migrations'),
    'seeds_path'      => base_path('database/seeds'),

    // Chunk size for batch jobs. Nothing loads a full result set into memory.
    'chunk_size' => 1000,
];
