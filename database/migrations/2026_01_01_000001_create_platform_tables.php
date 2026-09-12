<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * Platform foundation: organisations (the tenancy + compliance boundary),
 * workspaces (brands), users, memberships, RBAC and the two log tables.
 */
return new class extends Migration {
    public function up(Schema $schema, Connection $connection): void
    {
        $schema->create('organisations', static function (Blueprint $table): void {
            $table->id();
            $table->uuid();
            $table->string('name', 200);
            $table->string('slug', 120);
            $table->string('industry', 60)->nullable();
            $table->string('website', 255)->nullable();

            // Compliance and display context. country drives the rule set;
            // timezone drives every rendered timestamp; currency is never
            // assumed in a calculation.
            $table->char('country', 2)->default('US');
            $table->string('timezone', 64)->default('UTC');
            $table->char('currency', 3)->default('USD');

            // Physical postal address — a hard requirement for US commercial
            // marketing email, and rendered in every marketing footer.
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('address_city', 120)->nullable();
            $table->string('address_state', 120)->nullable();
            $table->string('address_postcode', 30)->nullable();
            $table->char('address_country', 2)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email', 255)->nullable();

            // Brand profile — also the context handed to the AI provider.
            $table->string('logo_path', 255)->nullable();
            $table->string('primary_colour', 9)->nullable();
            $table->text('brand_voice')->nullable();
            $table->text('target_customer')->nullable();
            $table->text('products')->nullable();
            $table->text('services')->nullable();
            $table->text('unique_selling_proposition')->nullable();

            $table->string('default_sender_name', 120)->nullable();
            $table->string('default_sender_email', 255)->nullable();
            $table->string('reply_to_email', 255)->nullable();

            // Governance
            $table->boolean('require_campaign_approval')->default(true);
            $table->enum('trust_level', ['new', 'verified', 'trusted'])->default('new');
            $table->integer('daily_send_limit')->default(500);
            $table->boolean('sending_paused')->default(false);
            $table->string('sending_paused_reason', 255)->nullable();
            $table->integer('attribution_window_days')->default(30);
            $table->enum('attribution_model', ['last_click', 'first_click', 'influenced'])->default('last_click');

            $table->enum('status', ['active', 'trialing', 'suspended', 'cancelled'])->default('trialing');
            $table->dateTime('onboarding_completed_at')->nullable();
            $table->integer('onboarding_step')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('uuid');
            $table->unique('slug');
            $table->index('status');
            $table->index('country');
        });

        /*
         * Workspaces / brands. One default row per organisation in V1, but the
         * column exists on brand-scoped tables from day one so an agency with
         * several brands never needs a data migration (§55).
         */
        $schema->create('workspaces', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->uuid();
            $table->string('name', 200);
            $table->string('slug', 120);
            $table->boolean('is_default')->default(false);
            $table->string('logo_path', 255)->nullable();
            $table->string('primary_colour', 9)->nullable();
            $table->string('default_sender_name', 120)->nullable();
            $table->string('default_sender_email', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'slug']);
            $table->index(['organisation_id', 'is_default']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->uuid();
            $table->string('email', 255);
            $table->string('email_normalized', 255);
            $table->string('password_hash', 255);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 10)->default('en');
            $table->string('avatar_path', 255)->nullable();

            // Platform-level role. Organisation roles live on the membership.
            $table->boolean('is_super_admin')->default(false);

            $table->dateTime('email_verified_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->integer('failed_login_count')->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('password_changed_at')->nullable();
            $table->boolean('mfa_enabled')->default(false);
            $table->string('mfa_secret', 255)->nullable();

            $table->enum('status', ['active', 'invited', 'disabled'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('email_normalized');
            $table->unique('uuid');
            $table->index('status');
        });

        $schema->create('roles', static function (Blueprint $table): void {
            $table->id();
            // NULL organisation_id = a platform-provided role shared by all
            // tenants. A non-null value is a tenant's own custom role.
            $table->bigInteger('organisation_id')->unsigned()->nullable();
            $table->string('key', 60);
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(true);
            $table->integer('rank')->default(0);
            $table->timestamps();

            $table->unique(['organisation_id', 'key']);
            $table->index('key');
        });

        $schema->create('permissions', static function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);
            $table->string('name', 160);
            $table->string('group_key', 60)->nullable();
            $table->timestamps();

            $table->unique('key');
        });

        $schema->create('role_permissions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id');
            $table->foreignId('permission_id');

            $table->unique(['role_id', 'permission_id']);
            $table->index('permission_id');
            $table->foreign('role_id', 'roles');
            $table->foreign('permission_id', 'permissions');
        });

        /*
         * Membership is the ONLY authority on which organisations a user may
         * act on. TenantMiddleware resolves the active organisation through
         * this table on every request.
         */
        $schema->create('organisation_users', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            $table->bigInteger('invited_by_user_id')->unsigned()->nullable();
            $table->string('invite_token_hash', 255)->nullable();
            $table->dateTime('invite_expires_at')->nullable();
            $table->dateTime('invite_accepted_at')->nullable();
            $table->enum('status', ['active', 'invited', 'suspended'])->default('active');
            $table->boolean('is_default')->default(false);
            $table->dateTime('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index('invite_token_hash');
            $table->foreign('organisation_id', 'organisations');
            $table->foreign('user_id', 'users');
            $table->foreign('role_id', 'roles', 'id', 'restrict');
        });

        $schema->create('password_resets', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            // Only a hash is stored: a stolen database cannot be used to reset
            // a password.
            $table->string('token_hash', 255);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->string('requested_user_agent', 255)->nullable();
            $table->timestamps();

            $table->unique('token_hash');
            $table->index(['user_id', 'expires_at']);
            $table->foreign('user_id', 'users');
        });

        /*
         * Security audit trail. Append-only: nothing in the application updates
         * or deletes a row here.
         */
        $schema->create('audit_logs', static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('organisation_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('actor_type', 30)->default('user'); // user | system | api | ai
            $table->string('action', 80);
            $table->string('entity_type', 60)->nullable();
            $table->bigInteger('entity_id')->unsigned()->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('correlation_id', 40)->nullable();
            $table->dateTime('created_at');

            $table->index(['organisation_id', 'created_at']);
            $table->index(['organisation_id', 'action']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('user_id');
        });

        /*
         * Operational activity, kept separate from the security audit trail so
         * that high-volume engagement events never dilute or rotate out the
         * records that matter for compliance and forensics.
         */
        $schema->create('activity_logs', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('contact_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('activity_type', 60);
            $table->string('subject_type', 60)->nullable();
            $table->bigInteger('subject_id')->unsigned()->nullable();
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');

            $table->index(['organisation_id', 'contact_id', 'occurred_at']);
            $table->index(['organisation_id', 'activity_type', 'occurred_at']);
        });

        $schema->create('notifications', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('type', 60);
            $table->enum('severity', ['info', 'success', 'warning', 'error'])->default('info');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('action_url', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'user_id', 'read_at']);
            $table->index(['organisation_id', 'type']);
            $table->foreign('organisation_id', 'organisations');
        });

        // Named advisory locks so a cron minute that overlaps the previous run
        // cannot double-execute a job.
        $schema->create('scheduler_locks', static function (Blueprint $table): void {
            $table->id();
            $table->string('lock_key', 120);
            $table->string('owner', 120);
            $table->dateTime('acquired_at');
            $table->dateTime('expires_at');

            $table->unique('lock_key');
            $table->index('expires_at');
        });
    }

    public function down(Schema $schema, Connection $connection): void
    {
        foreach ([
            'scheduler_locks', 'notifications', 'activity_logs', 'audit_logs',
            'password_resets', 'organisation_users', 'role_permissions',
            'permissions', 'roles', 'users', 'workspaces', 'organisations',
        ] as $table) {
            $schema->drop($table);
        }
    }
};
