<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * Platform operations: API keys, outbound webhooks, plans, subscriptions, metered
 * usage and AI request accounting.
 */
return new class extends Migration {
    public function up(Schema $schema, Connection $connection): void
    {
        $schema->create('api_keys', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->string('name', 120);
            // Only a prefix (for display) and a hash are stored. The plaintext
            // key is shown once, at creation, and never again.
            $table->string('key_prefix', 16);
            $table->string('key_hash', 255);
            $table->json('scopes');
            $table->integer('rate_limit_per_minute')->default(120);
            $table->string('allowed_ips', 500)->nullable();
            $table->bigInteger('created_by_user_id')->unsigned()->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->integer('request_count')->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('key_hash');
            $table->index(['organisation_id', 'revoked_at']);
            $table->index('key_prefix');
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('webhooks', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->string('name', 120);
            $table->string('url', 500);
            // Payloads are signed HMAC-SHA256. The secret is encrypted at rest.
            $table->string('secret_encrypted', 500);
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->integer('failure_count')->default(0);
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('last_failure_at')->nullable();
            $table->dateTime('disabled_at')->nullable();
            $table->string('disabled_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'is_active']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('webhook_deliveries', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('webhook_id');
            $table->string('event_type', 60);
            $table->uuid('event_uuid');
            $table->json('payload');
            $table->integer('attempt_count')->default(0);
            $table->integer('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->string('error', 500)->nullable();
            $table->enum('status', ['pending', 'delivered', 'failed', 'abandoned'])->default('pending');
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['status', 'next_attempt_at']);
            $table->index(['organisation_id', 'webhook_id', 'created_at']);
            $table->unique('event_uuid');
            $table->foreign('webhook_id', 'webhooks');
        });

        $schema->create('plans', static function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40);
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->json('prices');          // { "USD": 4900, "AUD": 7900 } minor units
            $table->string('interval', 20)->default('month');
            $table->json('limits');
            $table->json('features');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->unique('key');
        });

        $schema->create('subscriptions', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('plan_id');
            $table->enum('status', ['trialing', 'active', 'past_due', 'cancelled', 'paused'])->default('trialing');
            $table->char('currency', 3)->default('USD');
            $table->integer('unit_amount')->default(0);
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('current_period_start')->nullable();
            $table->dateTime('current_period_end')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            // Payment provider is deliberately not integrated yet; these columns
            // exist so adding one later is configuration, not a migration.
            $table->string('provider', 30)->nullable();
            $table->string('provider_subscription_id', 120)->nullable();
            $table->json('limit_overrides')->nullable();
            $table->timestamps();

            $table->unique('organisation_id');
            $table->index('status');
            $table->foreign('organisation_id', 'organisations');
            $table->foreign('plan_id', 'plans', 'id', 'restrict');
        });

        $schema->create('usage_records', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->string('metric', 40);   // contacts | emails_sent | ai_tokens | users | ...
            $table->string('period', 7);    // YYYY-MM
            $table->bigInteger('quantity')->default(0);
            $table->bigInteger('included_quantity')->default(0);
            $table->bigInteger('overage_quantity')->default(0);
            $table->dateTime('recorded_at');
            $table->timestamps();

            $table->unique(['organisation_id', 'metric', 'period'], 'uniq_usage_period');
            $table->index(['organisation_id', 'period']);
            $table->foreign('organisation_id', 'organisations');
        });

        /*
         * Every AI call. Token accounting drives both billing and abuse control,
         * and storing the prompt/response reference makes an AI recommendation
         * auditable after the fact — which matters because AI output is labelled
         * as a recommendation, never as observed data.
         */
        $schema->create('ai_requests', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->uuid();
            $table->string('provider', 30)->default('openai');
            $table->string('model', 80)->nullable();
            $table->enum('feature', [
                'campaign_studio', 'subject_lines', 'content', 'segment_generator',
                'campaign_analysis', 'assistant', 'recommendations', 'insights', 'other',
            ])->default('other');
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->integer('latency_ms')->nullable();
            $table->enum('status', ['success', 'failed', 'rejected', 'capped'])->default('success');
            $table->string('error', 500)->nullable();
            // Hashes rather than full text: enough to correlate and de-duplicate
            // without retaining customer content indefinitely.
            $table->char('prompt_hash', 64)->nullable();
            $table->json('request_summary')->nullable();
            $table->json('response_summary')->nullable();
            $table->string('related_entity_type', 40)->nullable();
            $table->bigInteger('related_entity_id')->unsigned()->nullable();
            $table->dateTime('created_at');

            $table->unique('uuid');
            $table->index(['organisation_id', 'created_at']);
            $table->index(['organisation_id', 'feature']);
            $table->foreign('organisation_id', 'organisations');
        });

        /*
         * AI-generated recommendations surfaced on the dashboard. Persisted so a
         * recommendation can be actioned, dismissed and measured — every one must
         * link to a concrete action.
         */
        $schema->create('ai_recommendations', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->string('recommendation_type', 60);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->enum('impact', ['low', 'medium', 'high'])->default('medium');
            $table->enum('data_basis', ['observed', 'calculated', 'ai_recommendation'])->default('calculated');
            $table->string('action_label', 80)->nullable();
            $table->string('action_url', 255)->nullable();
            $table->json('metrics')->nullable();
            $table->decimal('estimated_value', 16, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->dateTime('dismissed_at')->nullable();
            $table->bigInteger('dismissed_by_user_id')->unsigned()->nullable();
            $table->dateTime('actioned_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'dismissed_at', 'impact']);
            $table->index(['organisation_id', 'recommendation_type']);
            $table->foreign('organisation_id', 'organisations');
        });
    }

    public function down(Schema $schema, Connection $connection): void
    {
        foreach ([
            'ai_recommendations', 'ai_requests', 'usage_records', 'subscriptions',
            'plans', 'webhook_deliveries', 'webhooks', 'api_keys',
        ] as $table) {
            $schema->drop($table);
        }
    }
};
