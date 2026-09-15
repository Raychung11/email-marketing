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

    case 'user:password':
        // The reset flow in the app emails a link. That is the right design, and
        // it is useless in exactly the situation where you need it most: no
        // sending domain verified yet, SES still in sandbox, or the mail
        // configuration itself is what is broken. This is the way back in for
        // whoever has SSH.
        $email = $args[0] ?? '';

        if ($email === '') {
            $fail('Usage: php cron/console.php user:password owner@example.com');
        }

        $users = $container->make(App\Repositories\UserRepository::class);
        $user  = $users->findByEmail($email);

        if ($user === null) {
            $fail('No user with that email address.');
        }

        // Generated rather than taken as an argument: a password typed on the
        // command line is written to your shell history in plain text.
        $password = bin2hex(random_bytes(9));

        // Clearing the lock is the point. Five bad attempts locks the account for
        // fifteen minutes and login is then refused whatever the password is, so
        // handing someone a correct password while leaving them locked out is not
        // a recovery command — it is a second wrong answer.
        $users->update((int) $user['id'], [
            'password_hash'       => $container->make(App\Core\Hash::class)->make($password),
            'password_changed_at' => gmdate('Y-m-d H:i:s'),
            'failed_login_count'  => 0,
            'locked_until'        => null,
        ]);

        $wasLocked = ($user['locked_until'] ?? null) !== null
            || ((int) ($user['failed_login_count'] ?? 0)) > 0;

        $out('Password changed for ' . $email);

        if ($wasLocked) {
            $out('Account unlocked (it had ' . (int) ($user['failed_login_count'] ?? 0) . ' failed attempts).');
        }

        $out('New password: ' . $password);
        $out('');
        $out('Sign in with it, then change it under Profile. It is in this');
        $out('terminal\'s scrollback until you clear it.');
        break;

    case 'db:credentials':
        // Writes a MySQL defaults file for mysqldump.
        //
        // Not for humans: it exists so backup.sh never has to put the password on
        // a command line. Process arguments are world-readable on a shared host —
        // `ps aux` from any other account on the machine would show it — and a
        // password that leaks that way leaks to strangers.
        $target = $args[0] ?? '';

        if ($target === '') {
            $fail('Usage: php cron/console.php db:credentials /path/to/file.cnf');
        }

        $dbConfig = (array) $container->make(Config::class)->get('database.connections.mysql', []);

        // Create it empty and locked down BEFORE the secret goes in, so there is
        // no instant where it exists and is readable.
        if (@file_put_contents($target, '') === false) {
            $fail('Could not write ' . $target);
        }

        @chmod($target, 0600);

        $escape = static fn (string $value): string => '"' . addcslashes($value, '"\\') . '"';

        file_put_contents($target, implode(PHP_EOL, [
            '[client]',
            'host = ' . $escape((string) ($dbConfig['host'] ?? '127.0.0.1')),
            'port = ' . (int) ($dbConfig['port'] ?? 3306),
            'user = ' . $escape((string) ($dbConfig['username'] ?? '')),
            'password = ' . $escape((string) ($dbConfig['password'] ?? '')),
            'default-character-set = ' . ((string) ($dbConfig['charset'] ?? 'utf8mb4')),
            '',
        ]));

        $out((string) ($dbConfig['database'] ?? ''));
        break;

    case 'user:list':
        // "Which account am I supposed to be signing in as?" is unanswerable from
        // the login screen, and reaching for phpMyAdmin to find out is a long way
        // round for a question this small.
        $rows = $container->make(Connection::class)
            ->table('users')
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($rows === []) {
            $out('No user accounts exist yet.');
            $out('Create one: php cron/console.php org:create "Your Business" you@example.com');
            break;
        }

        $out(sprintf('%-4s %-38s %-20s %s', 'ID', 'EMAIL', 'LAST SIGNED IN', 'STATUS'));
        $out(str_repeat('-', 86));

        $nowUtc = gmdate('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $lockedUntil = (string) ($row['locked_until'] ?? '');
            $failures    = (int) ($row['failed_login_count'] ?? 0);

            $status = match (true) {
                ($row['deleted_at'] ?? null) !== null   => 'deleted',
                $lockedUntil !== '' && $lockedUntil > $nowUtc
                    => 'LOCKED until ' . $lockedUntil . ' UTC',
                $failures > 0                            => $failures . ' failed attempt' . ($failures === 1 ? '' : 's'),
                default                                  => 'ok',
            };

            $out(sprintf(
                '%-4s %-38s %-20s %s',
                (string) $row['id'],
                (string) $row['email'],
                (string) (($row['last_login_at'] ?? '') ?: 'never'),
                $status
            ));
        }

        $out('');
        $out('Times are UTC. To reset a password and clear a lock:');
        $out('  php cron/console.php user:password <email>');
        break;

    case 'mail:check':
        // Proves the credentials, the region and the signing all work, without
        // sending anything. Every failure here is one you want to find now
        // rather than halfway through a campaign.
        $provider = $container->make(App\Mail\EmailProviderInterface::class);

        $out('Provider:    ' . $provider->name());

        if ($provider->name() === 'log') {
            // Reporting a quota and "signing works" here would be a lie: the log
            // provider writes to a file and signs nothing. Say what is actually
            // true, which is that no email can leave this server.
            $out('');
            $out('MAIL_PROVIDER is "log". Messages are written to '
                . (string) $container->make(Config::class)->get('mail.providers.log.path')
                . ' and NOTHING is sent.');
            $out('');
            $out('That is the safe default. Set MAIL_PROVIDER=ses in .env when your');
            $out('sending domain is verified and you are ready to send for real.');
            break;
        }

        if (!$provider->isConfigured()) {
            $out('Configured:  NO');
            $out('');
            $fail(
                $provider->name() === 'log'
                    ? 'MAIL_PROVIDER is set to "log", so nothing will ever be sent. Set MAIL_PROVIDER=ses when you are ready.'
                    : 'Set AWS_REGION, AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY in .env.'
            );
        }

        $out('Configured:  yes');
        $out('Region:      ' . (string) $container->make(Config::class)->get('mail.providers.ses.region'));
        $out('From:        ' . (string) $container->make(Config::class)->get('mail.from.address'));
        $out('');
        $out('Asking the provider for your live quota...');

        $quota = $provider->getQuota();

        if ($quota->max24HourSend <= 0.0 && $quota->maxSendRate <= 0.0) {
            // getQuota() fails closed and returns zeros rather than an error, so
            // ask for an identity as well purely to get AWS's own words. Telling
            // someone "check the log" when we could just show them the reason is
            // a wasted round trip for them.
            $fromDomain = substr((string) $container->make(Config::class)->get('mail.from.address'), (int) strrpos(
                (string) $container->make(Config::class)->get('mail.from.address'),
                '@'
            ) + 1);

            $reason = $provider->validateIdentity($fromDomain)->error;

            $out('');

            if ($reason !== null && $reason !== '') {
                $out('Amazon SES said:');
                $out('  ' . $reason);
                $out('');
            }

            $fail(match (true) {
                str_contains((string) $reason, 'security token') || str_contains((string) $reason, 'AccessDenied')
                    => 'The credentials were rejected. Check AWS_ACCESS_KEY_ID and '
                        . 'AWS_SECRET_ACCESS_KEY in .env, and that the IAM user has the '
                        . 'aigrowthhub-ses-send policy attached.',
                str_contains((string) $reason, 'SignatureDoesNotMatch')
                    => 'The secret key is wrong, or the server clock is out by more than '
                        . 'fifteen minutes. Check the secret first, then `date -u`.',
                str_contains((string) $reason, 'Could not reach')
                    => 'Could not reach Amazon SES at all. Check outbound HTTPS is allowed.',
                default
                    => 'The provider returned no quota. Usually the credentials are wrong, '
                        . 'the region is wrong, or SES is not enabled in that region.',
            });
        }

        $out(sprintf('  Max per 24 hours   %s', number_format($quota->max24HourSend)));
        $out(sprintf('  Max per second     %s', rtrim(rtrim(number_format($quota->maxSendRate, 2), '0'), '.')));
        $out(sprintf('  Sent last 24 hours %s', number_format($quota->sentLast24Hours)));
        $out('');

        if ($quota->sandbox) {
            $out('SANDBOX. Your account can only send to addresses you have verified in');
            $out('the SES console, and at a low rate. Everything else works normally.');
            $out('Request production access in SES once your domain is verified.');
        } else {
            $out('Production access is enabled: you can send to anyone.');
        }

        $reputation = $provider->getReputationMetrics();

        if (!$reputation->sendingEnabled) {
            $out('');
            $out('WARNING: sending is currently DISABLED on this account'
                . ($reputation->enforcementStatus !== null ? ' (' . $reputation->enforcementStatus . ')' : '') . '.');
        }

        $out('');
        $out('Credentials, region and request signing all work.');

        // "Verified" means three different things in an AWS account — the signup,
        // the login's MFA, and whether SES will deliver to a given address while
        // sandboxed. Only the third decides whether a test message arrives, and
        // it is the one with no obvious place to look.
        $identity = trim((string) ($args[0] ?? ''));

        if ($identity !== '') {
            $out('');
            $out('Asking SES about ' . $identity . '...');

            $status = $provider->validateIdentity($identity);

            if ($status->error !== null && $status->error !== '') {
                $out('  SES said: ' . $status->error);
                $out('');
                $out('  Not verified. In the AWS console, with the region set to '
                    . (string) $container->make(Config::class)->get('mail.providers.ses.region')
                    . ':');
                $out('  SES → Identities → Create identity → Email address');
                break;
            }

            $out($status->verified
                ? '  Verified. This address can receive mail from your account.'
                : '  Known to SES but not verified yet — check the inbox for the confirmation link.');

            if ($status->dkimStatus !== 'not_checked') {
                $out('  DKIM: ' . $status->dkimStatus);
            }
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
            'mail:check'       => 'Verify provider credentials and quota; add an address to check if SES will deliver to it',
            'key:generate'     => 'Generate APP_KEY into .env',
            'schema:sql'       => 'Print schema DDL for a driver',
            'route:list'       => 'List registered routes',
            'org:create'       => 'Create an organisation with an owner account',
            'user:list'        => 'List accounts, when each last signed in, and whether any are locked',
            'user:password'    => 'Set a new password for a user and clear any lockout',
            'down / up'        => 'Toggle maintenance mode',
        ] as $name => $description) {
            $out(sprintf('  %-18s %s', $name, $description));
        }
        break;
}
