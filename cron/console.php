<?php

declare(strict_types=1);

/**
 * Console entry point.
 *
 *   php cron/console.php migrate
 *   php cron/console.php migrate:rollback
 *   php cron/console.php migrate:status
 *   php cron/console.php db:seed
 *   php cron/console.php key:generate
 *   php cron/console.php schema:sql [mysql|sqlite]
 *   php cron/console.php route:list
 *   php cron/console.php org:create "Acme Plumbing" owner@acme.test
 *   php cron/console.php down | up
 */

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\Application;
use App\Core\Config;
use App\Core\Encrypter;
use App\Core\Router;
use App\Database\Connection;
use App\Database\Migrator;
use App\Database\Schema\Schema;

$basePath = dirname(__DIR__);
$argv     = $_SERVER['argv'] ?? [];
$command  = $argv[1] ?? 'help';
$args     = array_slice($argv, 2);

$out = static function (string $line = ''): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

$fail = static function (string $line) use ($out): never {
    fwrite(STDERR, $line . PHP_EOL);
    exit(1);
};

// key:generate must work before the container demands an APP_KEY.
if ($command === 'key:generate') {
    $key     = Encrypter::generateKey();
    $envPath = $basePath . '/.env';

    if (!is_file($envPath)) {
        $out('No .env found. Copy .env.example to .env first, then re-run.');
        $out('Generated key (set APP_KEY to this value):');
        $out($key);
        exit(0);
    }

    $contents = (string) file_get_contents($envPath);

    $updated = preg_match('/^APP_KEY=.*$/m', $contents) === 1
        ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents)
        : rtrim($contents) . PHP_EOL . 'APP_KEY=' . $key . PHP_EOL;

    file_put_contents($envPath, $updated);
    $out('APP_KEY set in .env.');
    exit(0);
}

$app       = Application::bootConsole($basePath);
$container = $app->container();

switch ($command) {
    case 'migrate':
        $container->make(Migrator::class)->run($out);
        break;

    case 'migrate:rollback':
        $container->make(Migrator::class)->rollback($out);
        break;

    case 'migrate:fresh':
        if (($args[0] ?? '') !== '--force' && (string) $container->make(Config::class)->get('app.env') === 'production') {
            $fail('Refusing to run migrate:fresh in production without --force.');
        }

        $migrator = $container->make(Migrator::class);

        while ($migrator->rollback() !== []) {
            // roll back every batch
        }

        $migrator->run($out);
        break;

    case 'migrate:status':
        foreach ($container->make(Migrator::class)->status() as $row) {
            $out(sprintf(
                '%-8s %-60s %s',
                $row['applied'] ? '[done]' : '[ ]',
                $row['migration'],
                $row['applied'] ? 'batch ' . $row['batch'] : 'pending'
            ));
        }
        break;

    case 'db:seed':
        $only = $args[0] ?? null;

        foreach (glob($basePath . '/database/seeds/*.php') ?: [] as $file) {
            $name = basename($file, '.php');

            if ($only !== null && $name !== $only) {
                continue;
            }

            /** @var callable(App\Core\Container):void $seeder */
            $seeder = require $file;
            $out("Seeding: {$name}");
            $seeder($container);
        }

        $out('Seeding complete.');
        break;

    case 'schema:sql':
        // Render the whole schema as DDL without touching a database: useful for
        // review, and for handing a DBA the exact production statements.
        $driver = $args[0] ?? 'mysql';

        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            $fail('Usage: php cron/console.php schema:sql [mysql|sqlite]');
        }

        $out("-- {$driver} schema for " . $container->make(Config::class)->get('app.name'));
        $out('-- Generated ' . gmdate('c') . ' from database/migrations');
        $out('');
        $dbConfig = $container->make(Config::class);
        $out((new App\Database\SchemaDumper(
            (string) $dbConfig->get('database.migrations_path'),
            (string) $dbConfig->get('database.connections.mysql.charset', 'utf8mb4'),
            (string) $dbConfig->get('database.connections.mysql.collation', 'utf8mb4_unicode_ci')
        ))->toSql($driver));
        break;

    case 'route:list':
        /** @var Router $router */
        $router = Application::boot($basePath)->container()->make(Router::class);

        foreach ($router->routes() as $route) {
            $out(sprintf(
                '%-7s %-45s %s',
                $route['method'],
                $route['pattern'],
                is_string($route['handler']) ? $route['handler'] : '(closure)'
            ));
        }
        break;

    case 'org:create':
        $name  = $args[0] ?? '';
        $email = $args[1] ?? '';

        if ($name === '' || $email === '') {
            $fail('Usage: php cron/console.php org:create "Organisation name" owner@example.com');
        }

        $password = bin2hex(random_bytes(9));

        $result = $container->make(App\Services\OnboardingService::class)
            ->registerOrganisationWithOwner($name, $email, $password, 'US', 'UTC', 'USD');

        $out('Organisation created: ' . $name);
        $out('Owner: ' . $email);
        $out('Temporary password: ' . $password);
        $out('Organisation id: ' . $result['organisation_id']);
        break;

    case 'down':
        file_put_contents($basePath . '/storage/framework/maintenance.flag', gmdate('c'));
        $out('Maintenance mode enabled.');
        break;

    case 'up':
        @unlink($basePath . '/storage/framework/maintenance.flag');
        $out('Maintenance mode disabled.');
        break;

    case 'db:check':
        $connection = $container->make(Connection::class);
        $schema     = $container->make(Schema::class);
        $out('Driver: ' . $connection->driver());
        $tables = ['organisations', 'users', 'contacts', 'contact_consents', 'suppressions', 'campaigns'];

        foreach ($tables as $table) {
            $out(sprintf('%-20s %s', $table, $schema->hasTable($table) ? 'present' : 'MISSING'));
        }
        break;

    case 'help':
    default:
        $out('Available commands:');
        foreach ([
            'migrate'          => 'Apply pending migrations',
            'migrate:rollback' => 'Roll back the last batch',
            'migrate:fresh'    => 'Roll back everything, then migrate',
            'migrate:status'   => 'Show migration state',
            'db:seed'          => 'Run seeders (roles, permissions, plans, compliance rules)',
            'db:check'         => 'Verify core tables exist',
            'key:generate'     => 'Generate APP_KEY into .env',
            'schema:sql'       => 'Print schema DDL for a driver',
            'route:list'       => 'List registered routes',
            'org:create'       => 'Create an organisation with an owner account',
            'down / up'        => 'Toggle maintenance mode',
        ] as $name => $description) {
            $out(sprintf('  %-18s %s', $name, $description));
        }
        break;
}
