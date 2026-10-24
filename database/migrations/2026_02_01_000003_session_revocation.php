<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Schema;

/**
 * Give a session something to be revoked by.
 *
 * WHAT WAS WRONG.
 *
 * `completePasswordReset()` finished by calling `$this->auth->logout()`. That
 * destroys the session of the browser making the request — which, on the reset
 * flow, is an anonymous visitor who was never signed in. It does nothing to the
 * sessions that matter.
 *
 * So the whole point of a password reset failed. Somebody who had stolen a
 * session cookie kept it: the victim changes their password, is told their
 * account is secure, and the attacker stays signed in until the cookie ages out
 * a day later. `changePassword()` revoked nothing at all — not even a comment
 * claiming it did.
 *
 * WHY A VERSION COLUMN AND NOT A SESSION TABLE.
 *
 * Sessions here are PHP's own, files on disk. There is no way to enumerate one
 * user's sessions and delete them: the files are keyed by session id, and the
 * user id is inside the serialised payload. Scanning the session directory of a
 * shared host — where it may be shared with other accounts and is not readable
 * anyway — is not something to build a security control on.
 *
 * A moving version is the portable form of the same thing. The session records
 * the version it was opened under, the users row holds the current one, and
 * every authenticated request compares them. Bumping the number invalidates
 * every session in existence for that account at once, with no list to walk and
 * no cleanup to schedule. The cost is one integer compare against a row that
 * every authenticated request already loads.
 *
 * Moving the whole platform to database-backed sessions would also solve it, and
 * would be a bigger change than this brief is allowed to make.
 *
 * ONE EFFECT AT DEPLOY TIME: sessions opened before this migration carry no
 * recorded version, so they cannot be verified and are treated as stale. Every
 * signed-in user signs in once more. That is the fail-closed direction and it is
 * in the deploy checklist.
 */
return new class extends Migration {
    public function up(Schema $schema, Connection $connection): void
    {
        $sqlite = $connection->driver() === 'sqlite';
        $quote  = static fn (string $i): string => $sqlite ? '"' . $i . '"' : '`' . $i . '`';

        /*
         * Starts at 1 rather than 0 so that "has a version" and "has version
         * zero" can never be confused by a loose comparison, and so a row
         * created before this column existed reads as a real version rather
         * than as an unset one.
         *
         * No hasColumn() guard: a guard is a query, and SchemaDumper renders
         * migrations through a MySQL grammar over an empty handle that captures
         * statements only. See 2026_02_01_000001 for the long version.
         */
        $connection->raw(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $quote('users'),
            $quote('session_version'),
            $sqlite ? 'INTEGER NOT NULL DEFAULT 1' : 'INT UNSIGNED NOT NULL DEFAULT 1'
        ));

        /*
         * Not required by the check — it is for the person answering "did my
         * password reset actually sign the other device out?". Without it the
         * only record is an audit line, and audit lines get rotated.
         */
        $connection->raw(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $quote('users'),
            $quote('sessions_revoked_at'),
            $sqlite ? 'TEXT NULL' : 'DATETIME NULL'
        ));
    }

    public function down(Schema $schema, Connection $connection): void
    {
        $sqlite = $connection->driver() === 'sqlite';
        $quote  = static fn (string $i): string => $sqlite ? '"' . $i . '"' : '`' . $i . '`';

        foreach (['sessions_revoked_at', 'session_version'] as $column) {
            $connection->raw(sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $quote('users'),
                $quote($column)
            ));
        }
    }
};
