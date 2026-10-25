<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * Send reliability, persistent rate limiting and session revocation.
 *
 * Four separate problems, one migration, because they all have to land before
 * the code that depends on them and splitting them would only widen the window
 * in which the schema and the application disagree.
 *
 *  1. campaign_recipients gains a real claim lifecycle. The old table could only
 *     say pending / queued / sent / failed / skipped, so the moment a job
 *     claimed a recipient it had to call it "sent" — before the provider had
 *     been asked. `sending` and `outcome_unknown` are what let the row tell the
 *     truth between those two points.
 *
 *  2. users gains auth_version, so a password change can invalidate sessions on
 *     devices this request has never seen.
 *
 *  3. rate_limits gives the limiter somewhere to count that outlives one PHP
 *     process. Without it, a deployment on the database queue driver has login
 *     throttling that resets on every request.
 *
 *  4. send_reservations makes the daily send budget a reservation rather than a
 *     retrospective count, so two scheduler ticks cannot both spend it.
 *
 * ON WIDENING AN ENUM. MySQL does it in place with MODIFY. SQLite renders enums
 * as TEXT with a CHECK constraint and cannot alter one, so there the table is
 * rebuilt — created from the same Blueprint the original migration used, copied,
 * swapped. The rebuild is written out rather than hand-rolled as DDL so the new
 * table cannot drift from what the schema builder would produce.
 */
return new class extends Migration {
    /** The send_status vocabulary after this migration. */
    private const SEND_STATUSES = [
        'pending', 'queued', 'sending', 'sent', 'failed', 'skipped', 'outcome_unknown',
    ];

    public function up(Schema $schema, Connection $connection): void
    {
        $this->upgradeRecipients($schema, $connection);

        // Session revocation. Every session carries the version it was created
        // under; a password change bumps the column and every other session
        // fails its next request.
        $this->addColumn($connection, 'users', 'auth_version', 'INTEGER NOT NULL DEFAULT 1');

        /*
         * Fixed-window counters for login, API key and public-endpoint
         * throttling.
         *
         * The key is hashed by the store before it gets here, so this table
         * never holds an email address, an API key or an IP in the clear: a
         * rate-limit table is not a reason to build a second log of who tried
         * to sign in.
         */
        $schema->create('rate_limits', static function (Blueprint $table): void {
            $table->id();
            $table->string('bucket', 64);
            $table->integer('hits')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('created_at');

            $table->unique('bucket');
            // The pruning sweep reads this and nothing else.
            $table->index('expires_at');
        });

        /*
         * Daily send reservations.
         *
         * One row per organisation per calendar day *in that organisation's own
         * timezone*, which is the day their plan and their UI talk about. The
         * counter is incremented when work is enqueued, not when it is sent, so
         * a scheduler tick cannot hand out an allowance that a previous tick has
         * already queued up and not yet processed.
         */
        $schema->create('send_reservations', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            // YYYY-MM-DD in the organisation's timezone.
            $table->string('day_key', 10);
            $table->integer('reserved')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['organisation_id', 'day_key']);
            $table->index('day_key');
            $table->foreign('organisation_id', 'organisations');
        });
    }

    public function down(Schema $schema, Connection $connection): void
    {
        // Deliberately asymmetric. Dropping the new columns is safe; narrowing
        // send_status back is not, because a row sitting at 'sending' or
        // 'outcome_unknown' would have to be rewritten to some older value, and
        // every available choice is a lie that could put an already-delivered
        // email back on the queue. An operator rolling back keeps the wider
        // column; it costs nothing and the old code ignores the extra values.
        $schema->drop('send_reservations');
        $schema->drop('rate_limits');
    }

    // ----------------------------------------------------------- internals

    private function upgradeRecipients(Schema $schema, Connection $connection): void
    {
        $columns = [
            // Who holds the claim. A worker may only advance a row it still owns.
            'claim_token'           => 'VARCHAR(64) NULL',
            'claimed_at'            => 'DATETIME NULL',
            // When the claim lapses. Past this, another worker may take the row
            // over — but what it may do with it depends on provider_attempted_at.
            'claim_expires_at'      => 'DATETIME NULL',
            // Stamped immediately before the provider call and never cleared.
            // NULL means we know for certain nothing was sent, which is the only
            // state from which an automatic retry is safe.
            'provider_attempted_at' => 'DATETIME NULL',
            // Backoff. A released recipient is not re-dispatched before this, so
            // the scheduler cannot route around the queue's retry delay.
            'available_at'          => 'DATETIME NULL',
            // The day whose budget this recipient already holds, so a retry
            // spends the allowance once rather than once per attempt.
            'budget_day'            => 'VARCHAR(10) NULL',
        ];

        if ($connection->driver() === 'mysql') {
            foreach ($columns as $name => $definition) {
                $this->addColumn($connection, 'campaign_recipients', $name, $definition);
            }

            $connection->raw(
                'ALTER TABLE `campaign_recipients` MODIFY `send_status` '
                . "ENUM('" . implode("', '", self::SEND_STATUSES) . "') NOT NULL DEFAULT 'pending'"
            );

            $connection->raw(
                'CREATE INDEX `campaign_recipients_dispatch_idx` ON `campaign_recipients` '
                . '(`campaign_id`, `send_status`, `available_at`)'
            );
            $connection->raw(
                'CREATE INDEX `campaign_recipients_claim_idx` ON `campaign_recipients` (`claim_expires_at`)'
            );

            return;
        }

        // SQLite: rebuild. CHECK constraints cannot be altered in place.
        $schema->create('campaign_recipients_rebuild', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('campaign_id');
            $table->foreignId('contact_id');
            $table->string('email', 255);
            $table->string('email_normalized', 255);

            $table->enum('eligibility_status', [
                'eligible', 'suppressed', 'no_consent', 'invalid', 'duplicate', 'blocked',
            ])->default('eligible');
            $table->string('eligibility_reason', 80)->nullable();

            $table->enum('send_status', self::SEND_STATUSES)->default('pending');
            $table->bigInteger('email_message_id')->unsigned()->nullable();
            $table->string('ab_variant', 10)->nullable();
            $table->integer('attempt_count')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('claim_token', 64)->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('claim_expires_at')->nullable();
            $table->dateTime('provider_attempted_at')->nullable();
            $table->dateTime('available_at')->nullable();
            $table->string('budget_day', 10)->nullable();
            $table->dateTime('created_at');

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'send_status']);
            $table->index(['campaign_id', 'eligibility_status']);
            $table->index(['organisation_id', 'campaign_id']);
            $table->index(['campaign_id', 'send_status', 'available_at']);
            $table->index('claim_expires_at');
            $table->foreign('campaign_id', 'campaigns');
            $table->foreign('contact_id', 'contacts');
        });

        $carried = 'id, organisation_id, campaign_id, contact_id, email, email_normalized,
                    eligibility_status, eligibility_reason, send_status, email_message_id,
                    ab_variant, attempt_count, last_error, queued_at, sent_at, created_at';

        $connection->raw(
            'INSERT INTO campaign_recipients_rebuild (' . $carried . ')
             SELECT ' . $carried . ' FROM campaign_recipients'
        );

        $connection->raw('DROP TABLE campaign_recipients');
        $connection->raw('ALTER TABLE campaign_recipients_rebuild RENAME TO campaign_recipients');
    }

    /** Plain ADD COLUMN, spelled the same way for both grammars. */
    private function addColumn(Connection $connection, string $table, string $column, string $definition): void
    {
        $connection->raw(
            'ALTER TABLE ' . $this->wrap($connection, $table)
            . ' ADD COLUMN ' . $this->wrap($connection, $column) . ' ' . $definition
        );
    }

    private function wrap(Connection $connection, string $identifier): string
    {
        $quote = $connection->driver() === 'mysql' ? '`' : '"';

        return $quote . $identifier . $quote;
    }
};
