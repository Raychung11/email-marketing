<?php

declare(strict_types=1);

/**
 * Shared wiring for the MySQL/MariaDB integration checks.
 *
 * These run against a real server because the guarantees under test are the
 * server's: that a conditional UPDATE reports a row count only to the caller
 * that won it, that a widened ENUM accepts the new values, that the indexes the
 * dispatch query depends on are actually there. SQLite agrees with MySQL on
 * almost all of it, and "almost" is the whole reason this file exists — the
 * collation bug that made the first production deploy fail was exactly a case
 * where the test grammar and the deployment grammar disagreed.
 *
 * Connection details come from the environment; nothing is hardcoded and no
 * credential is printed.
 */

use App\Database\Connection;

require __DIR__ . '/../../bootstrap/autoload.php';

function integration_connection(): Connection
{
    return new Connection([
        'driver'    => 'mysql',
        'host'      => getenv('MYSQL_TEST_HOST') ?: '127.0.0.1',
        'port'      => (int) (getenv('MYSQL_TEST_PORT') ?: 3306),
        'database'  => getenv('MYSQL_TEST_DATABASE') ?: 'aigh_test',
        'username'  => getenv('MYSQL_TEST_USERNAME') ?: 'aigh',
        'password'  => getenv('MYSQL_TEST_PASSWORD') ?: '',
        'charset'   => 'utf8mb4',
        // MariaDB has never had utf8mb4_0900_ai_ci; this is the value the
        // deployment documentation tells operators to use.
        'collation' => getenv('MYSQL_TEST_COLLATION') ?: 'utf8mb4_unicode_ci',
    ]);
}
