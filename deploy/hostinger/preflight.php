<?php

declare(strict_types=1);

/**
 * Deployment preflight for shared hosting.
 *
 *   php deploy/hostinger/preflight.php
 *
 * Run it over SSH after every deploy. It answers the questions you cannot
 * answer by looking at the site: does the database accept the collation the
 * schema uses, is the .env file reachable from the internet, has cron actually
 * run, is debug mode still on.
 *
 * It deliberately does not boot the application container. The moment you most
 * need this script is the moment the application will not boot, so it reads
 * .env and talks to PDO directly and reports what it finds.
 *
 * Exit code 0 = safe to use. 1 = at least one FAIL. Warnings do not fail.
 */

require __DIR__ . '/../../bootstrap/autoload.php';

use App\Core\Env;

$root = dirname(__DIR__, 2);

$results = [];
$fails   = 0;
$warns   = 0;

/**
 * @param 'pass'|'warn'|'fail' $status
 */
function check(string $name, string $status, string $detail = '', string $fix = ''): void
{
    global $results, $fails, $warns;

    $results[] = compact('name', 'status', 'detail', 'fix');

    if ($status === 'fail') {
        $fails++;
    } elseif ($status === 'warn') {
        $warns++;
    }
}

// ---------------------------------------------------------------- PHP runtime

check(
    'PHP version (command line)',
    PHP_VERSION_ID >= 80300 ? 'pass' : 'fail',
    PHP_VERSION,
    'hPanel → Advanced → PHP Configuration → select 8.3 or newer. Note that the '
    . 'command-line version and the website version are set separately on some plans.'
);

foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json', 'curl'] as $extension) {
    check(
        "PHP extension: {$extension}",
        extension_loaded($extension) ? 'pass' : 'fail',
        '',
        'hPanel → Advanced → PHP Configuration → PHP extensions.'
    );
}

// -------------------------------------------------------------------- the env

$envPath = $root . '/.env';

if (!is_file($envPath)) {
    check('.env exists', 'fail', $envPath, 'cp deploy/hostinger/env.production.example .env');
    render($results, $fails, $warns);
    exit(1);
}

check('.env exists', 'pass', $envPath);

$mode = substr(sprintf('%o', fileperms($envPath)), -3);
check(
    '.env is not world-readable',
    in_array($mode, ['600', '640', '660'], true) ? 'pass' : 'warn',
    'mode ' . $mode,
    'chmod 600 .env'
);

Env::load($envPath);

$env = static function (string $key, string $default = ''): string {
    $value = Env::get($key, $default);

    // Env casts true/false to real booleans, which would otherwise render as
    // "1" and "" in this report — the two least readable ways to show a flag.
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    return (string) ($value ?? $default);
};

check(
    'APP_KEY is set',
    $env('APP_KEY') !== '' ? 'pass' : 'fail',
    '',
    'php cron/console.php key:generate — and never change it afterwards, or every '
    . 'stored API key becomes undecryptable.'
);

check(
    'APP_ENV is production',
    $env('APP_ENV') === 'production' ? 'pass' : 'warn',
    $env('APP_ENV'),
    'Set APP_ENV=production in .env.'
);

$debug = strtolower($env('APP_DEBUG', 'false'));
check(
    'APP_DEBUG is off',
    in_array($debug, ['false', '0'], true) ? 'pass' : 'fail',
    $debug,
    'Set APP_DEBUG=false. With it on, a stack trace containing your database '
    . 'password can be shown to anyone who triggers an error.'
);

$appUrl = rtrim($env('APP_URL'), '/');
check(
    'APP_URL is https',
    str_starts_with($appUrl, 'https://') ? 'pass' : 'fail',
    $appUrl,
    'Set APP_URL to the https address of the site. Links in emails are built from it.'
);

foreach (['SESSION_SECURE', 'FORCE_HTTPS'] as $flag) {
    check(
        "{$flag} is on",
        strtolower($env($flag, 'false')) === 'true' ? 'pass' : 'warn',
        $env($flag),
        "Set {$flag}=true once the site has a certificate."
    );
}

check(
    'Queue driver suits shared hosting',
    $env('QUEUE_DRIVER') === 'database' ? 'pass' : 'warn',
    $env('QUEUE_DRIVER'),
    'Shared hosting has no Redis. Set QUEUE_DRIVER=database.'
);

// -------------------------------------------------------------- the database

$database  = $env('DB_DATABASE');
$collation = $env('DB_COLLATION', 'utf8mb4_unicode_ci');
$pdo       = null;

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $env('DB_HOST', 'localhost'),
            (int) $env('DB_PORT', '3306'),
            $database,
            $env('DB_CHARSET', 'utf8mb4')
        ),
        $env('DB_USERNAME'),
        $env('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    check('Database connects', 'pass', $database);
} catch (Throwable $e) {
    check(
        'Database connects',
        'fail',
        $e->getMessage(),
        'Check DB_USERNAME and DB_PASSWORD in .env against hPanel → Databases. The '
        . 'username is the u… prefixed one, not your hPanel login, and DB_HOST is localhost.'
    );
}

if ($pdo instanceof PDO) {
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $maria   = stripos($version, 'mariadb') !== false;

    check('Database server', 'pass', $version . ($maria ? '  (MariaDB)' : '  (MySQL)'));

    // The check this whole script exists for. The schema builder stamps
    // DB_COLLATION on every CREATE TABLE; if the server has never heard of it,
    // migrate fails on the first table and leaves a half-built database.
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ?'
    );
    $statement->execute([$collation]);

    check(
        'Server supports DB_COLLATION',
        ((int) $statement->fetchColumn()) > 0 ? 'pass' : 'fail',
        $collation,
        $maria
            ? 'MariaDB does not have utf8mb4_0900_ai_ci. Set DB_COLLATION=utf8mb4_unicode_ci.'
            : 'Pick a collation this server lists in information_schema.COLLATIONS.'
    );

    $tables = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    )->fetchColumn();

    check(
        'Schema is migrated',
        $tables >= 60 ? 'pass' : ($tables === 0 ? 'fail' : 'warn'),
        $tables . ' tables',
        'php cron/console.php migrate && php cron/console.php db:seed'
    );

    // Mixed collations are what turn a JOIN into "Illegal mix of collations"
    // months later, long after the deploy that caused it.
    $mixed = $pdo->query(
        'SELECT COUNT(DISTINCT TABLE_COLLATION) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION IS NOT NULL'
    )->fetchColumn();

    if ($tables > 0) {
        check(
            'All tables share one collation',
            ((int) $mixed) <= 1 ? 'pass' : 'fail',
            $mixed . ' distinct collations in use',
            'The database was migrated under more than one DB_COLLATION. Drop the '
            . 'database, set DB_COLLATION once, and migrate again before you hold real data.'
        );
    }
}

// ---------------------------------------------------------------- filesystem

foreach (['storage', 'storage/logs', 'storage/uploads', 'storage/framework/cache', 'storage/tmp'] as $path) {
    $full     = $root . '/' . $path;
    $writable = is_dir($full) && is_writable($full);

    check(
        "Writable: {$path}",
        $writable ? 'pass' : 'fail',
        $writable ? '' : (is_dir($full) ? 'directory is not writable' : 'directory is missing'),
        "mkdir -p {$path} && chmod -R 755 storage"
    );
}

// ------------------------------------------------------------------ web root

// If the document root is the repository rather than repository/public, then
// .env, the logs and the whole source tree are one URL away.
$documentRoot = null;

foreach ([$root . '/../public_html', dirname($root) . '/public_html'] as $candidate) {
    if (is_link($candidate) || is_dir($candidate)) {
        $documentRoot = realpath($candidate) ?: $candidate;
        break;
    }
}

if ($documentRoot !== null) {
    check(
        'public_html points at the public/ directory',
        $documentRoot === realpath($root . '/public') ? 'pass' : 'warn',
        $documentRoot,
        'Either symlink public_html to the application public/ directory, or if the '
        . 'repository has to live inside public_html, copy '
        . 'deploy/hostinger/public_html.htaccess over public_html/.htaccess.'
    );
}

// The authoritative test: ask the live site for the files that must never be served.
if ($appUrl !== '' && function_exists('curl_init')) {
    foreach (['/.env', '/composer.json', '/storage/logs/app.log', '/config/database.php'] as $path) {
        $handle = curl_init($appUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body   = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($status === 0) {
            check("Not web-reachable: {$path}", 'warn', 'could not reach the site to check');
            continue;
        }

        check(
            "Not web-reachable: {$path}",
            $status === 200 ? 'fail' : 'pass',
            'HTTP ' . $status . ($status === 200 ? ' — ' . strlen($body) . ' bytes served' : ''),
            'The document root is exposing the application source. Fix the document '
            . 'root before this site holds a single real contact.'
        );
    }

    $handle = curl_init($appUrl . '/login');
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    check(
        'The site answers',
        in_array($status, [200, 302], true) ? 'pass' : 'warn',
        'HTTP ' . $status . ' from ' . $appUrl . '/login'
    );
}

// ----------------------------------------------------------------- scheduler

// Both processes write a heartbeat every run. Do NOT go back to reading a log's
// modification time for this: a scheduler tick with nothing to do writes no
// output at all, and a production LOG_LEVEL discards what it does write, so the
// log looks untouched on a perfectly healthy server and this check reports a
// dead cron that is running fine every minute.
$heartbeat = new \App\Support\Heartbeat($root . '/storage/framework');

$expectations = [
    'scheduler' => [
        'label' => 'Cron has run the scheduler',
        'stale' => 900,
        'fix'   => 'Without the scheduler nothing sends, no journey advances and no '
            . 'report refreshes. Add the jobs from deploy/hostinger/cron.txt in '
            . 'hPanel → Advanced → Cron Jobs, choosing Custom rather than PHP.',
    ],
    'worker' => [
        'label' => 'Cron has run the queue worker',
        'stale' => 900,
        'fix'   => 'Without the worker, campaigns are queued and then sit there. Same '
            . 'place: hPanel → Advanced → Cron Jobs.',
    ],
];

foreach ($expectations as $name => $expectation) {
    $age = $heartbeat->secondsSince($name);

    if ($age === null) {
        check(
            $expectation['label'],
            'warn',
            'has never run',
            $expectation['fix']
        );

        continue;
    }

    check(
        $expectation['label'],
        $age < $expectation['stale'] ? 'pass' : 'fail',
        $age < 60 ? 'last run just now' : 'last run ' . (int) round($age / 60) . ' minutes ago',
        $expectation['fix']
    );
}

render($results, $fails, $warns);

exit($fails > 0 ? 1 : 0);

/**
 * @param array<int,array{name:string,status:string,detail:string,fix:string}> $results
 */
function render(array $results, int $fails, int $warns): void
{
    $symbols = ['pass' => '  ok  ', 'warn' => ' warn ', 'fail' => ' FAIL '];

    echo PHP_EOL . 'Deployment preflight' . PHP_EOL;
    echo str_repeat('-', 72) . PHP_EOL;

    foreach ($results as $result) {
        echo '[' . $symbols[$result['status']] . '] ' . $result['name'];
        echo $result['detail'] !== '' ? '  —  ' . $result['detail'] : '';
        echo PHP_EOL;

        if ($result['status'] !== 'pass' && $result['fix'] !== '') {
            echo '           ' . wordwrap($result['fix'], 60, PHP_EOL . '           ') . PHP_EOL;
        }
    }

    echo str_repeat('-', 72) . PHP_EOL;
    echo sprintf(
        '%d checks, %d failed, %d warnings%s',
        count($results),
        $fails,
        $warns,
        $fails === 0 ? '  —  safe to use' : '  —  fix the failures first'
    ) . PHP_EOL . PHP_EOL;
}
