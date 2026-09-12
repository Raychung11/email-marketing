<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * Customer Data Hub: companies, contacts, custom fields, tags, lists and the
 * dynamic segmentation engine.
 */
return new class extends Migration {
    public function up(Schema $schema, Connection $connection): void
    {
        $schema->create('companies', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->uuid();
            $table->string('name', 200);
            $table->string('domain', 190)->nullable();
            $table->string('industry', 60)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website', 255)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('postcode', 30)->nullable();
            $table->integer('employee_count')->nullable();
            $table->decimal('annual_revenue', 16, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'uuid']);
            $table->index(['organisation_id', 'name']);
            $table->index(['organisation_id', 'domain']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('contacts', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->uuid();

            $table->string('email', 255);
            // Canonical form for deduplication and suppression matching.
            $table->string('email_normalized', 255);

            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_normalized', 40)->nullable();
            $table->bigInteger('company_id')->unsigned()->nullable();
            $table->string('company', 200)->nullable();
            $table->string('job_title', 120)->nullable();

            $table->char('country', 2)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('postcode', 30)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('preferred_language', 10)->nullable();

            $table->string('source', 40)->nullable();
            $table->string('source_detail', 255)->nullable();

            $table->enum('customer_status', [
                'lead', 'prospect', 'customer', 'repeat_customer', 'vip', 'inactive', 'lost',
            ])->default('lead');

            $table->enum('lead_status', [
                'new', 'contacted', 'qualified', 'quotation', 'negotiation', 'won', 'lost',
            ])->nullable();

            $table->enum('lifecycle_stage', [
                'subscriber', 'lead', 'marketing_qualified', 'sales_qualified',
                'opportunity', 'customer', 'repeat_customer', 'inactive',
            ])->default('subscriber');

            $table->integer('lead_score')->default(0);

            // Money is DECIMAL with an explicit currency. Never a float, never
            // a hardcoded symbol.
            $table->decimal('customer_value', 16, 2)->default(0);
            $table->decimal('total_revenue', 16, 2)->default(0);
            $table->char('currency', 3)->nullable();
            $table->integer('purchase_count')->default(0);

            $table->dateTime('first_purchase_at')->nullable();
            $table->dateTime('last_purchase_at')->nullable();
            $table->dateTime('last_contact_at')->nullable();
            $table->dateTime('last_engagement_at')->nullable();
            $table->dateTime('last_email_sent_at')->nullable();
            $table->dateTime('last_email_open_at')->nullable();
            $table->dateTime('last_email_click_at')->nullable();

            // Denormalised mirrors of the authoritative tables, maintained by
            // ConsentService/SuppressionService purely so list screens and
            // segment previews do not need a subquery per row. The send path
            // NEVER reads these: it re-derives from contact_consents and
            // suppressions at queue time.
            $table->boolean('marketing_consent_cache')->default(false);
            $table->boolean('is_suppressed_cache')->default(false);

            $table->bigInteger('owner_user_id')->unsigned()->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Deduplication guarantee: one contact per email per organisation.
            $table->unique(['organisation_id', 'email_normalized']);
            $table->unique(['organisation_id', 'uuid']);
            $table->index(['organisation_id', 'customer_status']);
            $table->index(['organisation_id', 'lifecycle_stage']);
            $table->index(['organisation_id', 'country']);
            $table->index(['organisation_id', 'last_purchase_at']);
            $table->index(['organisation_id', 'last_engagement_at']);
            $table->index(['organisation_id', 'created_at']);
            $table->index(['organisation_id', 'lead_score']);
            $table->index(['organisation_id', 'company_id']);
            $table->index(['organisation_id', 'owner_user_id']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('custom_field_definitions', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->string('entity_type', 30)->default('contact'); // contact | company | lead
            $table->string('key', 60);
            $table->string('label', 120);
            $table->enum('type', ['text', 'number', 'date', 'boolean', 'select', 'multi_select']);
            $table->json('options')->nullable();
            $table->string('help_text', 255)->nullable();
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['organisation_id', 'entity_type', 'key']);
            $table->index(['organisation_id', 'entity_type']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('contact_custom_fields', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('contact_id');
            $table->foreignId('custom_field_definition_id');

            // Typed columns rather than one stringly-typed value, so numeric
            // and date segment rules compare correctly instead of
            // lexicographically.
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->dateTime('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['contact_id', 'custom_field_definition_id'], 'uniq_contact_custom_field');
            $table->index(['organisation_id', 'custom_field_definition_id'], 'idx_ccf_org_definition');
            $table->foreign('contact_id', 'contacts');
            $table->foreign('custom_field_definition_id', 'custom_field_definitions');
        });

        $schema->create('tags', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->string('name', 80);
            $table->string('slug', 80);
            $table->string('colour', 9)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->integer('contact_count')->default(0);
            $table->timestamps();

            $table->unique(['organisation_id', 'slug']);
            $table->index(['organisation_id', 'name']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('contact_tags', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('contact_id');
            $table->foreignId('tag_id');
            $table->bigInteger('added_by_user_id')->unsigned()->nullable();
            $table->dateTime('created_at');

            $table->unique(['contact_id', 'tag_id']);
            $table->index(['organisation_id', 'tag_id']);
            $table->foreign('contact_id', 'contacts');
            $table->foreign('tag_id', 'tags');
        });

        $schema->create('lists', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->uuid();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->string('description', 255)->nullable();
            $table->integer('contact_count')->default(0);
            $table->bigInteger('created_by_user_id')->unsigned()->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'slug']);
            $table->index(['organisation_id', 'name']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('list_contacts', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('list_id');
            $table->foreignId('contact_id');
            $table->string('added_via', 40)->default('manual'); // manual | import | form | api | automation
            $table->dateTime('created_at');

            $table->unique(['list_id', 'contact_id']);
            $table->index(['organisation_id', 'contact_id']);
            $table->foreign('list_id', 'lists');
            $table->foreign('contact_id', 'contacts');
        });

        /*
         * Dynamic segments. The rule tree is stored as validated JSON on the
         * segment and, denormalised, as rows in segment_rules for reporting and
         * for the UI builder. Neither form is ever concatenated into SQL: the
         * compiler maps whitelisted field keys to columns and binds values.
         */
        $schema->create('segments', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->uuid();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->string('description', 255)->nullable();
            $table->enum('match_type', ['all', 'any'])->default('all');
            $table->json('definition');
            $table->string('template_key', 60)->nullable();
            $table->boolean('is_system')->default(false);

            // Counts are cached: recomputing a segment over a million contacts
            // on every page render is not acceptable.
            $table->integer('cached_count')->default(0);
            $table->integer('cached_eligible_count')->default(0);
            $table->dateTime('counts_refreshed_at')->nullable();

            $table->bigInteger('created_by_user_id')->unsigned()->nullable();
            $table->enum('created_via', ['manual', 'ai', 'template'])->default('manual');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'slug']);
            $table->index(['organisation_id', 'name']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('segment_rules', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('segment_id');
            $table->bigInteger('parent_rule_id')->unsigned()->nullable();
            $table->enum('node_type', ['group', 'condition'])->default('condition');
            $table->enum('boolean_operator', ['and', 'or'])->default('and');
            $table->string('field_key', 80)->nullable();
            $table->string('operator', 30)->nullable();
            $table->text('value')->nullable();
            $table->text('value_secondary')->nullable();
            $table->integer('depth')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['organisation_id', 'segment_id']);
            $table->index('parent_rule_id');
            $table->foreign('segment_id', 'segments');
        });
    }

    public function down(Schema $schema, Connection $connection): void
    {
        foreach ([
            'segment_rules', 'segments', 'list_contacts', 'lists',
            'contact_tags', 'tags', 'contact_custom_fields',
            'custom_field_definitions', 'contacts', 'companies',
        ] as $table) {
            $schema->drop($table);
        }
    }
};
