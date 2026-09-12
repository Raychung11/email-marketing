<?php

declare(strict_types=1);

return [
    'path'  => base_path((string) env('LOG_PATH', 'storage/logs/app.log')),
    'level' => env('LOG_LEVEL', 'info'),
];
