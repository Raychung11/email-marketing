<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * Consent, suppression, unsubscribe evidence, versioned compliance rules and
 * the import pipeline (which is where consent provenance is captured).
 */
return new class extends Migration {
    public function up(Schema $schema, Connection $connection): void
    {
        /*
         * APPEND-ONLY consent history. Nothing in the application updates or
         * deletes a row in this table; a change of mind is a new row. Current
         * state is the latest row per (contact_id, channel).
         */
        $schema->create('contact_consents', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('contact_id');

            $table->enum('channel', ['email', 'sms', 'whatsapp'])->default('email');
            $table->enum('status', ['granted', 'denied', 'withdrawn', 'unknown'])->default('unknown');
            $table->enum('consent_type', [
                'express', 'inferred', 'transactional',
                'legitimate_existing_relationship', 'other',
            ])->default('other');

            $table->enum('source', [
                'website_form', 'checkout', 'crm', 'manual', 'api', 'event',
                'phone', 'paper', 'import', 'preference_centre', 'unsubscribe',
            ])->default('manual');

            // Evidence. This is the difference between "we think they opted in"
            // and being able to demonstrate it.
            $table->string('source_reference', 255)->nullable();
            $table->text('consent_text')->nullable();
            $table->string('privacy_policy_version', 30)->nullable();
            $table->string('terms_version', 30)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Topic-level preference, for the preference centre.
            $table->string('topic', 40)->nullable();

            $table->dateTime('consented_at')->nullable();
            $table->dateTime('withdrawn_at')->nullable();
            $table->dateTime('expires_at')->nullable();

            $table->bigInteger('recorded_by_user_id')->unsigned()->nullable();
            $table->bigInteger('campaign_id')->unsigned()->nullable();
            $table->dateTime('created_at');

            // Latest-row-per-channel lookups are the hot path for eligibility.
            $table->index(['organisation_id', 'contact_id', 'channel', 'created_at'], 'idx_consent_lookup');
            $table->index(['organisation_id', 'channel', 'status']);
            $table->foreign('contact_id', 'contacts');
        });

        /*
         * Suppression is organisation-wide and keyed on the normalized address,
         * not on contact_id — so deleting or re-importing a contact cannot
         * resurrect a suppressed address.
         */
        $schema->create('suppressions', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->string('email', 255);
            $table->string('email_normalized', 255);
            // Retained even if the address itself is later anonymised for a
            // deletion request, so the suppression survives.
            $table->char('email_hash', 64);

            $table->enum('reason', [
                'unsubscribe', 'hard_bounce', 'complaint', 'manual', 'legal', 'invalid', 'admin_block',
            ]);
            $table->string('source', 40)->nullable();   // provider | user | api | import | platform
            $table->string('provider', 30)->nullable();
            $table->bigInteger('campaign_id')->unsigned()->nullable();
            $table->bigInteger('email_message_id')->unsigned()->nullable();
            $table->string('detail', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->bigInteger('created_by_user_id')->unsigned()->nullable();

            // Removal is deliberate, audited and rare. Never automatic.
            $table->dateTime('removed_at')->nullable();
            $table->bigInteger('removed_by_user_id')->unsigned()->nullable();
            $table->string('removal_reason', 255)->nullable();

            $table->dateTime('created_at');

            $table->unique(['organisation_id', 'email_normalized'], 'uniq_suppression_org_email');
            $table->index(['organisation_id', 'reason']);
            $table->index(['organisation_id', 'created_at']);
            $table->index('email_hash');
            $table->foreign('organisation_id', 'organisations');
        });

        /*
         * Every unsubscribe, with the evidence required to explain it later.
         * Kept separate from contact_consents because an unsubscribe is an event
         * about a *message*, while consent is a state about a *contact*.
         */
        $schema->create('unsubscribe_events', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('contact_id')->unsigned()->nullable();
            $table->string('email', 255);
            $table->bigInteger('campaign_id')->unsigned()->nullable();
            $table->bigInteger('email_message_id')->unsigned()->nullable();
            $table->enum('scope', ['all_marketing', 'topic'])->default('all_marketing');
            $table->string('topic', 40)->nullable();
            $table->enum('method', ['link', 'one_click', 'preference_centre', 'manual', 'provider'])->default('link');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at');

            $table->index(['organisation_id', 'created_at']);
            $table->index(['organisation_id', 'campaign_id']);
            $table->index(['organisation_id', 'contact_id']);
        });

        /*
         * Versioned, configurable rules. The ComplianceService reads this table,
         * not a constant, because regulation changes and a historical send must
         * be explainable with the rules that were in force at the time.
         */
        $schema->create('compliance_rules', static function (Blueprint $table): void {
            $table->id();
            // NULL = platform default. A row with an organisation_id is that
            // tenant's override.
            $table->bigInteger('organisation_id')->unsigned()->nullable();
            $table->char('country', 2)->nullable();   // NULL / '*' = any country
            $table->string('rule_code', 80);
            $table->integer('version')->default(1);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->json('configuration_json');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organisation_id', 'country', 'rule_code', 'version'], 'uniq_rule_version');
            $table->index(['country', 'rule_code', 'is_active']);
        });

        /*
         * Import pipeline. The consent declaration is captured on the batch, so
         * every contact created by an import can be traced to a stated
         * provenance — and purchased/scraped declarations are refused before any
         * row is written.
         */
        $schema->create('import_batches', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->uuid();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('original_filename', 255);
            $table->string('stored_path', 255)->nullable();
            $table->integer('total_rows')->default(0);

            $table->enum('status', [
                'uploaded', 'mapped', 'validated', 'awaiting_consent',
                'importing', 'completed', 'failed', 'blocked', 'cancelled',
            ])->default('uploaded');

            $table->json('column_map')->nullable();

            // Consent declaration — mandatory before import proceeds.
            $table->string('consent_source', 40)->nullable();
            $table->string('consent_reference', 255)->nullable();
            $table->text('consent_text')->nullable();
            $table->boolean('consent_declared')->default(false);
            $table->dateTime('consent_declared_at')->nullable();
            $table->string('blocked_reason', 80)->nullable();

            $table->enum('duplicate_strategy', ['update_existing', 'skip_existing', 'merge_fill_blanks'])
                ->default('update_existing');

            $table->json('target_list_ids')->nullable();
            $table->json('target_tag_ids')->nullable();

            // Outcome counters.
            $table->integer('imported_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('invalid_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->integer('suppressed_count')->default(0);
            $table->integer('no_consent_count')->default(0);

            $table->text('error_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique('uuid');
            $table->index(['organisation_id', 'status']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('import_rows', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('import_batch_id');
            $table->integer('row_number');
            $table->string('email', 255)->nullable();
            $table->json('raw_data')->nullable();
            $table->enum('outcome', [
                'pending', 'imported', 'updated', 'skipped_duplicate',
                'invalid_email', 'suppressed', 'no_consent', 'error',
            ])->default('pending');
            $table->string('message', 255)->nullable();
            $table->bigInteger('contact_id')->unsigned()->nullable();
            $table->dateTime('created_at');

            $table->index(['import_batch_id', 'outcome']);
            $table->index(['organisation_id', 'import_batch_id']);
            $table->foreign('import_batch_id', 'import_batches');
        });
    }

    public function down(Schema $schema, Connection $connection): void
    {
        foreach ([
            'import_rows', 'import_batches', 'compliance_rules',
            'unsubscribe_events', 'suppressions', 'contact_consents',
        ] as $table) {
            $schema->drop($table);
        }
    }
};
