<?php

declare(strict_types=1);

/**
 * Test runner.
 *
 *   php tests/run.php               run everything
 *   php tests/run.php Compliance    run classes matching a substring
 *
 * Self-contained on purpose: the suite must run on a fresh clone with no vendor
 * directory, because the first thing anyone does with this repository is check
 * that the compliance and tenancy guarantees actually hold.
 *
 * PHPUnit is declared in require-dev; this runner and PHPUnit are not mutually
 * exclusive, but this is what CI uses today.
 */

require __DIR__ . '/../bootstrap/autoload.php';

use Tests\Support\AssertionFailed;
use Tests\Support\TestCase;

$filter = $argv[1] ?? null;

$directories = ['Unit', 'Feature', 'Security'];
$classes     = [];

foreach ($directories as $directory) {
    foreach (glob(__DIR__ . '/' . $directory . '/*.php') ?: [] as $file) {
        require_once $file;

        $class = 'Tests\\' . $directory . '\\' . basename($file, '.php');

        if (class_exists($class) && is_subclass_of($class, TestCase::class)) {
            $classes[] = $class;
        }
    }
}

sort($classes);

$totalTests      = 0;
$totalAssertions = 0;
$failures        = [];
$errors          = [];
$startedAt       = microtime(true);

$colour = static function (string $text, string $code): string {
    return (getenv('NO_COLOR') !== false || !stream_isatty(STDOUT)) ? $text : "\033[{$code}m{$text}\033[0m";
};

$green = static fn (string $t): string => $colour($t, '32');
$red   = static fn (string $t): string => $colour($t, '31');
$dim   = static fn (string $t): string => $colour($t, '2');
$bold  = static fn (string $t): string => $colour($t, '1');

foreach ($classes as $class) {
    $shortName = substr($class, strrpos($class, '\\') + 1);

    if ($filter !== null && stripos($class, $filter) === false) {
        continue;
    }

    $methods = array_values(array_filter(
        get_class_methods($class),
        static fn (string $method): bool => str_starts_with($method, 'test')
    ));

    if ($methods === []) {
        continue;
    }

    echo PHP_EOL . $bold($shortName) . PHP_EOL;

    foreach ($methods as $method) {
        $totalTests++;
        $label = preg_replace('/(?<!^)[A-Z]/', ' $0', substr($method, 4)) ?? $method;
        $label = strtolower(trim((string) $label));

        /** @var TestCase $instance */
        $instance = new $class();

        try {
            $instance->setUp();
            $instance->{$method}();
            $instance->tearDown();

            $totalAssertions += $instance->assertionCount();
            echo '  ' . $green('✓') . ' ' . $label . $dim(' (' . $instance->assertionCount() . ')') . PHP_EOL;
        } catch (AssertionFailed $e) {
            $totalAssertions += $instance->assertionCount();
            $failures[]       = ['test' => $shortName . '::' . $method, 'message' => $e->getMessage()];
            echo '  ' . $red('✗') . ' ' . $label . PHP_EOL;
            echo '    ' . $red($e->getMessage()) . PHP_EOL;

            try {
                $instance->tearDown();
            } catch (Throwable) {
                // Ignore teardown noise once a test has already failed.
            }
        } catch (Throwable $e) {
            $totalAssertions += $instance->assertionCount();
            $errors[]         = [
                'test'    => $shortName . '::' . $method,
                'message' => $e::class . ': ' . $e->getMessage(),
                'where'   => $e->getFile() . ':' . $e->getLine(),
            ];
            echo '  ' . $red('!') . ' ' . $label . PHP_EOL;
            echo '    ' . $red($e::class . ': ' . $e->getMessage()) . PHP_EOL;
            echo '    ' . $dim($e->getFile() . ':' . $e->getLine()) . PHP_EOL;

            try {
                $instance->tearDown();
            } catch (Throwable) {
            }
        }
    }
}

$duration = round(microtime(true) - $startedAt, 2);

echo PHP_EOL . str_repeat('─', 64) . PHP_EOL;

if ($failures === [] && $errors === []) {
    echo $green(sprintf(
        'OK — %d tests, %d assertions in %ss',
        $totalTests,
        $totalAssertions,
        $duration
    )) . PHP_EOL;

    exit(0);
}

echo $red(sprintf(
    'FAILED — %d tests, %d assertions, %d failures, %d errors in %ss',
    $totalTests,
    $totalAssertions,
    count($failures),
    count($errors),
    $duration
)) . PHP_EOL;

foreach ($failures as $failure) {
    echo PHP_EOL . $red('FAIL ') . $failure['test'] . PHP_EOL . '  ' . $failure['message'] . PHP_EOL;
}

foreach ($errors as $error) {
    echo PHP_EOL . $red('ERROR ') . $error['test'] . PHP_EOL
        . '  ' . $error['message'] . PHP_EOL
        . '  ' . $error['where'] . PHP_EOL;
}

exit(1);
