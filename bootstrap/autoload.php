<?php

declare(strict_types=1);

/*
 * Autoloading.
 *
 * Composer is the supported path in production (`composer install`), but the
 * application core has no runtime package dependencies, so a PSR-4 fallback is
 * registered when vendor/ is absent. That means migrations, the console and the
 * test suite work on a freshly cloned checkout before anything is installed.
 */

$vendor = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($vendor)) {
    require $vendor;
} else {
    spl_autoload_register(static function (string $class): void {
        static $prefixes = [
            'App\\'   => __DIR__ . '/../app/',
            'Tests\\' => __DIR__ . '/../tests/',
        ];

        foreach ($prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require $file;

                return;
            }
        }
    });

    require __DIR__ . '/../app/Support/helpers.php';
}
