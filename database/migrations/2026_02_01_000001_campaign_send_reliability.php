<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Schema;

/**
 * Make a campaign recipient's send state tell the truth.
 *
 * WHAT WAS WRONG.
 *
 * `claimForSending()` set `send_status = 'sent'` before the provider had been
 * called at all — before rendering, before link rewriting, before the message
 * row existed. Anything that threw in between left a recipient that said "sent"
 * about an email nobody had tried to send, and because the claim only accepts
 * `pending` or `queued`, the redelivered job could never claim it again. The
 * send was lost silently and the campaign reported success.
 *
 * WHAT THE NEW STATES MEAN.
 *
 *   pending          snapshot says eligible, nothing enqueued yet
 *   queued           a job exists for it
 *   sending          a worker holds the lease and is working on it NOW
 *   sent             the provider ACCEPTED it — not that it was delivered
 *   failed           permanently rejected, or out of retries
 *   skipped          compliance, cancellation, or no longer sendable
 *   outcome_unknown  the request left this process and the answer never came
 *                    back. It may or may not have been sent. Nothing automatic
 *                    may resend it; a human reconciles it against the provider.
 *
 * `sent` is the provider accepting the handoff. Delivery is a separate fact that
 * arrives later by webhook, and no column here should be read as proof of it.
 *
 * WHY send_status STOPS BEING AN ENUM ON MYSQL.
 *
 * Every new state would otherwise be another ALTER on a table that gets large,
 * and the application validates these values anyway. VARCHAR(24) costs nothing
 * and the next state is a code change rather than a migration. SQLite keeps a
 * CHECK, because there the column has to be rebuilt regardless and the
 * constraint is free.
 *
 * WHY SQLITE REBUILDS THE TABLE.
 *
 * The grammar renders an enum as TEXT with a CHECK constraint, and SQLite cannot
 * alter one. The twelve-step rebuild is the documented way, and it is done here
 * rather than left to a hand-written fix later.
 *
 * NOTHING HERE REWRITES HISTORY. Existing `sent` rows are left exactly as they
 * are. Some of them are the bug's output and were never really sent; this
 * migration will not guess which, and must not resend them. The diagnostic
 * command reports the candidates and a human reconciles them.
 */
return new class extends Migration {
    /** The full set after this migration. */
    private const STATUSES = [
        'pending', 'queued', 'sending', 'sent', 'failed', 'skipped', 'outcome_unknown',
    ];

    /** Added to campaign_recipients: name => [mysql type, sqlite type]. */
    private const COLUMNS = [
        // Who holds the lease. A worker may only move a row it still owns, so a
        // process that stalls past its lease cannot overwrite the state of the
        // worker that legitimately took the work over.
        'lock_token'       => ['VARCHAR(64) NULL',  'TEXT NULL'],
        // When the lease was taken, and when it lapses. Both, because "how long
        // has this been running" and "may somebody else take it" are different
        // questions and a single column answers neither well.
        'claimed_at'       => ['DATETIME NULL',     'TEXT NULL'],
        'lease_expires_at' => ['DATETIME NULL',     'TEXT NULL'],
        // The real last attempt, so attempt_count has something to agree with.
        'last_attempt_at'  => ['DATETIME NULL',     'TEXT NULL'],
        /*
         * Stamped immediately BEFORE the provider is called, and the single
         * fact that makes a lapsed lease answerable.
         *
         * A worker that dies holding a lease leaves a row nobody can interpret:
         * it might have died rendering the email, or three milliseconds after
         * the provider accepted it. Returning both to the queue duplicates
         * mail; sending both to manual reconciliation buries a real problem in
         * noise. With this column the two are different rows — null means the
         * request provably never left, anything else means it may have.
         *
         * One extra single-row UPDATE per send, by primary key. That is the
         * price of never guessing about a message that may already be in
         * somebody's inbox.
         */
        'provider_attempted_at' => ['DATETIME NULL', 'TEXT NULL'],
    ];

    public function up(Schema $schema, Connection $connection): void
    {
        $sqlite = $connection->driver() === 'sqlite';

        /*
         * No hasColumn() guard, and that is not an oversight.
         *
         * SchemaDumper renders every migration through a MySQL grammar over an
         * empty SQLite handle, capturing statements and never executing them. A
         * guard is a QUERY, not a statement, so it escapes that capture and hits
         * a database that has no schema in it — which is exactly how this
         * migration broke the collation test the first time it was written.
         * Migrations here run once, tracked in the migrations table; the
         * framework offers no other idempotency and does not need one.
         */
        foreach (self::COLUMNS as $name => [$mysql, $sqliteType]) {
            $connection->raw(sprintf(
                'ALTER TABLE %s ADD COLUMN %s %s',
                $this->wrap('campaign_recipients', $sqlite),
                $this->wrap($name, $sqlite),
                $sqlite ? $sqliteType : $mysql
            ));
        }

        $sqlite
            ? $this->widenStatusSqlite($connection)
            : $this->widenStatusMysql($connection);

        /*
         * The reaper's index. Finding leases that have lapsed is the one query
         * that runs on a timer against the whole table, and without this it is a
         * scan of every recipient the platform has ever had.
         */
        $this->addIndex(
            $connection,
            'idx_campaign_recipients_lease',
            'campaign_recipients',
            ['send_status', 'lease_expires_at'],
            $sqlite
        );
    }

    public function down(Schema $schema, Connection $connection): void
    {
        /*
         * Deliberately narrow. Dropping the columns is safe; narrowing the status
         * column back is not, because rows may legitimately be `sending` or
         * `outcome_unknown` and there is no honest value to put them in. A
         * rollback that rewrote those to `pending` would re-queue mail that may
         * already have been delivered, which is the one outcome worse than the
         * bug this migration fixes.
         *
         * So the states stay wide. Leaving a column able to hold values nothing
         * writes costs nothing; guessing costs somebody a duplicate send.
         */
        $sqlite = $connection->driver() === 'sqlite';

        // MySQL names the table when dropping an index; SQLite does not.
        $connection->raw($sqlite
            ? 'DROP INDEX "idx_campaign_recipients_lease"'
            : 'DROP INDEX `idx_campaign_recipients_lease` ON `campaign_recipients`');

        foreach (array_keys(self::COLUMNS) as $name) {
            $connection->raw(sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->wrap('campaign_recipients', $sqlite),
                $this->wrap($name, $sqlite)
            ));
        }
    }

    // ----------------------------------------------------------- internals

    private function widenStatusMysql(Connection $connection): void
    {
        $connection->raw(
            'ALTER TABLE `campaign_recipients` '
            . "MODIFY COLUMN `send_status` VARCHAR(24) NOT NULL DEFAULT 'pending'"
        );
    }

    /**
     * The twelve-step rebuild, because a CHECK constraint cannot be altered.
     *
     * Foreign keys are suspended for the swap: the pragma is a no-op inside a
     * transaction, so this runs outside one and the migrator's own wrapper is
     * what would otherwise defeat it.
     */
    private function widenStatusSqlite(Connection $connection): void
    {
        $check = implode(', ', array_map(
            static fn (string $s): string => "'" . $s . "'",
            self::STATUSES
        ));

        $extra = '';

        foreach (self::COLUMNS as $name => [, $sqliteType]) {
            $extra .= ',' . PHP_EOL . '  "' . $name . '" ' . $sqliteType;
        }

        $connection->raw('PRAGMA foreign_keys = OFF');

        $connection->raw(
            'CREATE TABLE "campaign_recipients__new" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "organisation_id" INTEGER NOT NULL,
  "campaign_id" INTEGER NOT NULL,
  "contact_id" INTEGER NOT NULL,
  "email" TEXT NOT NULL,
  "email_normalized" TEXT NOT NULL,
  "eligibility_status" TEXT NOT NULL DEFAULT \'eligible\' CHECK ("eligibility_status" IN (\'eligible\', \'suppressed\', \'no_consent\', \'invalid\', \'duplicate\', \'blocked\')),
  "eligibility_reason" TEXT NULL,
  "send_status" TEXT NOT NULL DEFAULT \'pending\' CHECK ("send_status" IN (' . $check . ')),
  "email_message_id" INTEGER NULL,
  "ab_variant" TEXT NULL,
  "attempt_count" INTEGER NOT NULL DEFAULT 0,
  "last_error" TEXT NULL,
  "queued_at" TEXT NULL,
  "sent_at" TEXT NULL,
  "created_at" TEXT NOT NULL' . $extra . ',
  FOREIGN KEY ("campaign_id") REFERENCES "campaigns" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY ("contact_id") REFERENCES "contacts" ("id") ON DELETE CASCADE ON UPDATE CASCADE
)'
        );

        // Derived from COLUMNS rather than written out twice: a column added
        // above and forgotten here would be silently dropped by the rebuild.
        $columns = '"id", "organisation_id", "campaign_id", "contact_id", "email", "email_normalized", '
                 . '"eligibility_status", "eligibility_reason", "send_status", "email_message_id", '
                 . '"ab_variant", "attempt_count", "last_error", "queued_at", "sent_at", "created_at"';

        foreach (array_keys(self::COLUMNS) as $added) {
            $columns .= ', "' . $added . '"';
        }

        $connection->raw(
            'INSERT INTO "campaign_recipients__new" (' . $columns . ') '
            . 'SELECT ' . $columns . ' FROM "campaign_recipients"'
        );

        $connection->raw('DROP TABLE "campaign_recipients"');
        $connection->raw('ALTER TABLE "campaign_recipients__new" RENAME TO "campaign_recipients"');

        foreach ([
            'CREATE UNIQUE INDEX "uniq_campaign_recipients_campaign_id_contact_id" ON "campaign_recipients" ("campaign_id", "contact_id")',
            'CREATE INDEX "idx_campaign_recipients_campaign_id_send_status" ON "campaign_recipients" ("campaign_id", "send_status")',
            'CREATE INDEX "idx_campaign_recipients_campaign_id_eligibility_status" ON "campaign_recipients" ("campaign_id", "eligibility_status")',
            'CREATE INDEX "idx_campaign_recipients_organisation_id_campaign_id" ON "campaign_recipients" ("organisation_id", "campaign_id")',
        ] as $index) {
            $connection->raw($index);
        }

        $connection->raw('PRAGMA foreign_keys = ON');
    }

    /** @param array<int,string> $columns */
    private function addIndex(
        Connection $connection,
        string $name,
        string $table,
        array $columns,
        bool $sqlite
    ): void {
        $wrapped = implode(', ', array_map(fn (string $c): string => $this->wrap($c, $sqlite), $columns));

        $connection->raw(sprintf(
            'CREATE INDEX %s ON %s (%s)',
            $this->wrap($name, $sqlite),
            $this->wrap($table, $sqlite),
            $wrapped
        ));
    }

    private function wrap(string $identifier, bool $sqlite): string
    {
        return $sqlite ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }
};
