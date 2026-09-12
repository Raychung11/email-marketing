<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * Sending infrastructure: domains, templates, campaigns, the recipient snapshot,
 * per-message records, provider events, link tracking and the database queue.
 */
return new class extends Migration {
    public function up(Schema $schema, Connection $connection): void
    {
        $schema->create('sending_domains', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->string('domain', 190);
            $table->enum('status', ['pending', 'verified', 'failed', 'disabled'])->default('pending');
            $table->enum('dkim_status', ['pending', 'verified', 'failed'])->default('pending');
            $table->enum('spf_status', ['pending', 'verified', 'failed', 'not_checked'])->default('pending');
            $table->enum('dmarc_status', ['pending', 'verified', 'failed', 'not_checked'])->default('pending');
            $table->json('dns_records')->nullable();
            $table->string('tracking_domain', 190)->nullable();
            $table->enum('tracking_domain_status', ['pending', 'verified', 'failed', 'not_configured'])
                ->default('not_configured');
            $table->string('provider', 30)->default('ses');
            $table->string('provider_identity_arn', 255)->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'domain']);
            $table->index(['organisation_id', 'status']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('templates', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->uuid();
            $table->string('name', 160);
            $table->string('description', 255)->nullable();
            $table->enum('category', [
                'newsletter', 'promotion', 'reactivation', 'announcement', 'event',
                'lead_followup', 'customer_followup', 'review_request', 'seasonal',
                'product', 'service', 'transactional', 'blank',
            ])->default('blank');
            // Block-based document. Rendered to HTML by TemplateRenderer; the
            // stored HTML is a cache, the blocks are the source of truth.
            $table->json('blocks')->nullable();
            $table->longText('html_cache')->nullable();
            $table->longText('text_cache')->nullable();
            $table->string('thumbnail_path', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->bigInteger('created_by_user_id')->unsigned()->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'uuid']);
            $table->index(['organisation_id', 'category']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('campaigns', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->uuid();
            $table->string('name', 200);
            $table->string('subject', 255)->nullable();
            $table->string('preview_text', 255)->nullable();
            $table->string('from_name', 120)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->string('reply_to', 255)->nullable();

            $table->enum('campaign_type', [
                'newsletter', 'promotion', 'reactivation', 'announcement', 'event',
                'lead_followup', 'customer_followup', 'review_request', 'seasonal',
                'product', 'service',
            ])->default('newsletter');

            // Structural marketing/transactional split. Derived from the
            // campaign type, not settable by a user on a promotional blast.
            $table->enum('message_class', ['marketing', 'transactional'])->default('marketing');

            $table->bigInteger('template_id')->unsigned()->nullable();
            $table->longText('html_content')->nullable();
            $table->longText('text_content')->nullable();

            // Audience: a segment, a list, or both.
            $table->bigInteger('segment_id')->unsigned()->nullable();
            $table->bigInteger('list_id')->unsigned()->nullable();

            $table->dateTime('scheduled_at')->nullable();
            $table->string('timezone', 64)->nullable();

            $table->enum('status', [
                'draft', 'pending_review', 'approved', 'scheduled', 'sending',
                'paused', 'completed', 'cancelled', 'failed',
            ])->default('draft');

            // UTM defaults appended to tracked links.
            $table->string('utm_source', 80)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('utm_content', 120)->nullable();
            $table->string('utm_term', 120)->nullable();

            $table->json('validation_findings')->nullable();
            $table->dateTime('validated_at')->nullable();
            $table->integer('risk_score')->default(0);
            $table->string('pause_reason', 255)->nullable();

            // Recipient snapshot counters, written when sending begins.
            $table->integer('recipient_count')->default(0);
            $table->integer('eligible_count')->default(0);
            $table->integer('suppressed_count')->default(0);
            $table->integer('no_consent_count')->default(0);

            // Rollups. Maintained from email_events, never authoritative.
            $table->integer('sent_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('open_count')->default(0);
            $table->integer('unique_open_count')->default(0);
            $table->integer('click_count')->default(0);
            $table->integer('unique_click_count')->default(0);
            $table->integer('bounce_count')->default(0);
            $table->integer('soft_bounce_count')->default(0);
            $table->integer('complaint_count')->default(0);
            $table->integer('unsubscribe_count')->default(0);
            $table->integer('conversion_count')->default(0);
            $table->decimal('attributed_revenue', 16, 2)->default(0);
            $table->char('currency', 3)->nullable();

            $table->bigInteger('created_by_user_id')->unsigned()->nullable();
            $table->bigInteger('approved_by_user_id')->unsigned()->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('send_started_at')->nullable();
            $table->dateTime('send_completed_at')->nullable();
            $table->enum('created_via', ['manual', 'ai', 'automation', 'template'])->default('manual');
            $table->bigInteger('ab_test_id')->unsigned()->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'uuid']);
            $table->index(['organisation_id', 'status']);
            $table->index(['organisation_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);   // scheduler scan
            $table->index(['organisation_id', 'campaign_type']);
            $table->foreign('organisation_id', 'organisations');
        });

        /*
         * RECIPIENT SNAPSHOT.
         *
         * Written once when a campaign starts sending. The segment is NOT
         * re-evaluated while a campaign is in flight — otherwise a contact added
         * mid-send could be skipped or double-sent, and the reported audience
         * would not match what actually went out.
         */
        $schema->create('campaign_recipients', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('campaign_id');
            $table->foreignId('contact_id');
            $table->string('email', 255);
            $table->string('email_normalized', 255);

            $table->enum('eligibility_status', [
                'eligible', 'suppressed', 'no_consent', 'invalid', 'duplicate', 'blocked',
            ])->default('eligible');
            // Stable reason code from ComplianceService, e.g. AU_CONSENT_UNKNOWN.
            $table->string('eligibility_reason', 80)->nullable();

            $table->enum('send_status', ['pending', 'queued', 'sent', 'failed', 'skipped'])->default('pending');
            $table->bigInteger('email_message_id')->unsigned()->nullable();
            $table->string('ab_variant', 10)->nullable();
            $table->integer('attempt_count')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('created_at');

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'send_status']);
            $table->index(['campaign_id', 'eligibility_status']);
            $table->index(['organisation_id', 'campaign_id']);
            $table->foreign('campaign_id', 'campaigns');
            $table->foreign('contact_id', 'contacts');
        });

        $schema->create('campaign_links', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('campaign_id');
            $table->char('link_hash', 40);
            $table->text('original_url');
            $table->string('label', 160)->nullable();
            $table->integer('click_count')->default(0);
            $table->integer('unique_click_count')->default(0);
            $table->dateTime('created_at');

            $table->unique(['campaign_id', 'link_hash']);
            $table->index(['organisation_id', 'campaign_id']);
            $table->foreign('campaign_id', 'campaigns');
        });

        $schema->create('campaign_ab_tests', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('campaign_id');
            $table->enum('test_type', ['subject', 'sender_name', 'content', 'cta']);
            $table->json('variants');
            $table->integer('sample_percentage')->default(20);
            // Click rate is the default winner metric: opens are unreliable.
            $table->enum('winner_metric', ['click_rate', 'open_rate', 'conversion_rate'])->default('click_rate');
            $table->string('winning_variant', 10)->nullable();
            $table->dateTime('evaluate_after')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->json('results')->nullable();
            $table->enum('status', ['pending', 'running', 'decided', 'cancelled'])->default('pending');
            $table->timestamps();

            $table->index(['organisation_id', 'campaign_id']);
            $table->foreign('campaign_id', 'campaigns');
        });

        /*
         * One row per message handed to a provider. Deliberately stores
         * addressing and status only — not the rendered body — so the table does
         * not become a long-lived store of personal message content.
         */
        $schema->create('email_messages', static function (Blueprint $table): void {
            $table->id();
            $table->uuid();
            $table->organisationId();
            $table->bigInteger('campaign_id')->unsigned()->nullable();
            $table->bigInteger('automation_id')->unsigned()->nullable();
            $table->bigInteger('automation_run_id')->unsigned()->nullable();
            $table->bigInteger('contact_id')->unsigned()->nullable();

            $table->enum('message_class', ['marketing', 'transactional'])->default('marketing');
            $table->string('provider', 30)->default('ses');
            $table->string('provider_message_id', 255)->nullable();

            $table->string('email', 255);
            $table->string('email_normalized', 255);
            $table->string('subject', 255)->nullable();
            $table->string('from_email', 255)->nullable();

            $table->enum('status', [
                'queued', 'sending', 'sent', 'delivered', 'bounced',
                'soft_bounced', 'complained', 'rejected', 'failed', 'suppressed',
            ])->default('queued');

            $table->dateTime('queued_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('clicked_at')->nullable();
            $table->dateTime('bounced_at')->nullable();
            $table->dateTime('complained_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->integer('open_count')->default(0);
            $table->integer('click_count')->default(0);
            $table->string('ab_variant', 10)->nullable();
            $table->dateTime('created_at');

            $table->unique('uuid');
            $table->index(['organisation_id', 'contact_id', 'created_at']);
            $table->index(['organisation_id', 'campaign_id', 'status']);
            $table->index('provider_message_id');
            $table->index(['organisation_id', 'status', 'created_at']);
            $table->index(['organisation_id', 'email_normalized']);
        });

        /*
         * Provider event stream. Append-only and idempotent: the unique index on
         * (provider, provider_event_id) is what makes a redelivered SNS
         * notification a no-op rather than a double count.
         */
        $schema->create('email_events', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('email_message_id')->unsigned()->nullable();
            $table->bigInteger('campaign_id')->unsigned()->nullable();
            $table->bigInteger('contact_id')->unsigned()->nullable();

            $table->enum('event_type', [
                'send', 'delivery', 'open', 'click', 'bounce', 'complaint',
                'reject', 'rendering_failure', 'delivery_delay', 'subscription',
            ]);
            $table->string('provider', 30)->default('ses');
            $table->string('provider_event_id', 190)->nullable();

            $table->string('bounce_type', 30)->nullable();     // Permanent | Transient | Undetermined
            $table->string('bounce_subtype', 40)->nullable();
            $table->string('complaint_type', 40)->nullable();
            $table->text('clicked_url')->nullable();
            $table->bigInteger('campaign_link_id')->unsigned()->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_type', 30)->nullable();
            $table->char('country', 2)->nullable();

            $table->json('metadata_json')->nullable();
            $table->dateTime('event_at');
            $table->dateTime('created_at');

            $table->unique(['provider', 'provider_event_id'], 'uniq_provider_event');
            $table->index(['organisation_id', 'event_type', 'event_at']);
            $table->index(['organisation_id', 'campaign_id', 'event_type']);
            $table->index(['email_message_id', 'event_type']);
            $table->index(['organisation_id', 'contact_id', 'event_at']);
        });

        // Database queue driver (fallback when Redis is unavailable) plus the
        // dead-letter table. Redis remains the production default.
        $schema->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue', 60);
            $table->bigInteger('organisation_id')->unsigned()->nullable();
            $table->string('job_class', 160);
            $table->json('payload');
            $table->integer('attempt_count')->default(0);
            $table->integer('priority')->default(0);
            $table->dateTime('available_at');
            $table->dateTime('reserved_at')->nullable();
            $table->string('reserved_by', 120)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->dateTime('created_at');

            $table->index(['queue', 'reserved_at', 'available_at']);
            $table->index('organisation_id');
        });

        $schema->create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue', 60);
            $table->bigInteger('organisation_id')->unsigned()->nullable();
            $table->string('job_class', 160);
            $table->json('payload');
            $table->integer('attempt_count')->default(0);
            $table->longText('exception')->nullable();
            $table->boolean('permanent')->default(false);
            $table->dateTime('failed_at');

            $table->index(['queue', 'failed_at']);
        });

        // Deliverability and reputation alerting.
        $schema->create('reputation_alerts', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('campaign_id')->unsigned()->nullable();
            $table->enum('alert_type', [
                'high_bounce_rate', 'high_complaint_rate', 'volume_spike',
                'domain_configuration', 'quota_approaching', 'sending_paused',
                'high_soft_bounce_rate',
            ]);
            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning');
            $table->string('message', 255);
            $table->decimal('metric_value', 12, 6)->nullable();
            $table->decimal('threshold_value', 12, 6)->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->bigInteger('acknowledged_by_user_id')->unsigned()->nullable();
            $table->dateTime('created_at');

            $table->index(['organisation_id', 'alert_type', 'created_at']);
            $table->index(['organisation_id', 'acknowledged_at']);
        });
    }

    public function down(Schema $schema, Connection $connection): void
    {
        foreach ([
            'reputation_alerts', 'failed_jobs', 'jobs', 'email_events',
            'email_messages', 'campaign_ab_tests', 'campaign_links',
            'campaign_recipients', 'campaigns', 'templates', 'sending_domains',
        ] as $table) {
            $schema->drop($table);
        }
    }
};
