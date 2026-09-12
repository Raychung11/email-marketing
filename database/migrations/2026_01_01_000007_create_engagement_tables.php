<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * Capture and attribution: forms, landing pages, website events, conversions and
 * the content library the AI draws on.
 */
return new class extends Migration {
    public function up(Schema $schema, Connection $connection): void
    {
        $schema->create('forms', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->uuid();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->enum('form_type', ['hosted', 'embedded', 'popup'])->default('hosted');
            $table->string('heading', 200)->nullable();
            $table->text('intro')->nullable();
            $table->string('submit_label', 60)->default('Submit');
            $table->text('success_message')->nullable();
            $table->string('redirect_url', 255)->nullable();

            /*
             * Consent wording is stored WITH the form and versioned, because the
             * exact text shown at the moment of opt-in is the evidence. A
             * pre-ticked consent box is not consent, so the default is false and
             * the checkbox is never rendered pre-selected.
             */
            $table->boolean('consent_checkbox_enabled')->default(true);
            $table->boolean('consent_checkbox_required')->default(false);
            $table->text('consent_text')->nullable();
            $table->string('consent_version', 30)->default('1');
            $table->enum('consent_type_granted', [
                'express', 'inferred', 'legitimate_existing_relationship',
            ])->default('express');
            $table->string('privacy_policy_url', 255)->nullable();

            $table->json('target_list_ids')->nullable();
            $table->json('target_tag_ids')->nullable();
            $table->bigInteger('automation_id')->unsigned()->nullable();
            $table->bigInteger('pipeline_id')->unsigned()->nullable();
            $table->boolean('create_lead')->default(false);

            $table->boolean('honeypot_enabled')->default(true);
            $table->integer('submission_count')->default(0);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'slug']);
            $table->index(['organisation_id', 'status']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('form_fields', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('form_id');
            $table->string('field_key', 60);
            $table->string('label', 120);
            $table->enum('field_type', [
                'text', 'email', 'phone', 'textarea', 'select', 'multi_select',
                'checkbox', 'radio', 'number', 'date', 'hidden',
            ])->default('text');
            $table->string('placeholder', 160)->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->string('maps_to_contact_field', 60)->nullable();
            $table->bigInteger('custom_field_definition_id')->unsigned()->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['form_id', 'field_key']);
            $table->index(['organisation_id', 'form_id']);
            $table->foreign('form_id', 'forms');
        });

        $schema->create('form_submissions', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('form_id');
            $table->bigInteger('contact_id')->unsigned()->nullable();
            $table->bigInteger('lead_id')->unsigned()->nullable();
            $table->json('payload');
            $table->boolean('consent_given')->default(false);
            $table->text('consent_text_shown')->nullable();
            $table->string('consent_version', 30)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('utm_source', 80)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->boolean('is_spam')->default(false);
            $table->dateTime('created_at');

            $table->index(['organisation_id', 'form_id', 'created_at']);
            $table->index(['organisation_id', 'contact_id']);
            $table->foreign('form_id', 'forms');
        });

        $schema->create('landing_pages', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->uuid();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->string('title', 200)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->json('blocks')->nullable();
            $table->longText('html_cache')->nullable();
            $table->bigInteger('form_id')->unsigned()->nullable();
            $table->string('custom_domain', 190)->nullable();
            $table->integer('view_count')->default(0);
            $table->integer('conversion_count')->default(0);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'slug']);
            $table->index(['organisation_id', 'status']);
            $table->foreign('organisation_id', 'organisations');
        });

        /*
         * Raw website/app behaviour. High volume and append-only; partition-ready
         * on occurred_at.
         */
        $schema->create('tracking_events', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('contact_id')->unsigned()->nullable();
            $table->string('anonymous_id', 64)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('event_name', 60);
            $table->string('page_url', 500)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->json('properties')->nullable();
            $table->string('utm_source', 80)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('utm_content', 120)->nullable();
            $table->string('utm_term', 120)->nullable();
            $table->bigInteger('campaign_id')->unsigned()->nullable();
            $table->bigInteger('email_message_id')->unsigned()->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_type', 30)->nullable();
            $table->char('country', 2)->nullable();
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');

            $table->index(['organisation_id', 'contact_id', 'occurred_at']);
            $table->index(['organisation_id', 'event_name', 'occurred_at']);
            $table->index(['organisation_id', 'session_id']);
            $table->index(['organisation_id', 'anonymous_id']);
        });

        /*
         * Conversions with their attribution decision recorded at write time.
         * Storing the attributed campaign (rather than recomputing on every
         * report) keeps revenue reporting stable and explainable even after the
         * attribution window or model is changed.
         */
        $schema->create('conversions', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->uuid();
            $table->bigInteger('contact_id')->unsigned()->nullable();

            $table->enum('conversion_type', [
                'lead', 'booking', 'purchase', 'quote_request',
                'appointment', 'form_submission', 'custom',
            ])->default('purchase');
            $table->string('event_name', 60)->nullable();

            $table->decimal('value', 16, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('external_id', 120)->nullable();   // order id, booking ref

            // Attribution
            $table->bigInteger('attributed_campaign_id')->unsigned()->nullable();
            $table->bigInteger('attributed_email_message_id')->unsigned()->nullable();
            $table->bigInteger('attributed_automation_id')->unsigned()->nullable();
            $table->enum('attribution_model', ['last_click', 'first_click', 'influenced', 'none'])->default('none');
            $table->integer('attribution_window_days')->nullable();
            $table->dateTime('attribution_touch_at')->nullable();

            $table->string('utm_source', 80)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->bigInteger('lead_id')->unsigned()->nullable();
            $table->json('properties')->nullable();
            $table->string('source', 30)->default('api');   // api | js | manual | integration
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');

            // Idempotency for retried conversion posts.
            $table->unique(['organisation_id', 'external_id', 'conversion_type'], 'uniq_conversion_external');
            $table->index(['organisation_id', 'occurred_at']);
            $table->index(['organisation_id', 'attributed_campaign_id']);
            $table->index(['organisation_id', 'contact_id', 'occurred_at']);
            $table->index(['organisation_id', 'conversion_type']);
        });

        /*
         * Brand knowledge the AI is allowed to draw on. This is how generated
         * copy stays factual: the model is given approved claims rather than
         * being trusted to invent them.
         */
        $schema->create('content_library', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->enum('item_type', [
                'brand_voice', 'product', 'service', 'offer', 'faq',
                'case_study', 'previous_campaign', 'approved_claim', 'testimonial',
            ]);
            $table->string('title', 200);
            $table->longText('body')->nullable();
            $table->json('metadata')->nullable();
            $table->decimal('price', 16, 2)->nullable();
            $table->char('currency', 3)->nullable();
            // Only approved items are given to the AI as context.
            $table->boolean('is_approved')->default(false);
            $table->bigInteger('approved_by_user_id')->unsigned()->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->boolean('ai_usable')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organisation_id', 'item_type']);
            $table->index(['organisation_id', 'is_approved', 'ai_usable']);
            $table->foreign('organisation_id', 'organisations');
        });
    }

    public function down(Schema $schema, Connection $connection): void
    {
        foreach ([
            'content_library', 'conversions', 'tracking_events', 'landing_pages',
            'form_submissions', 'form_fields', 'forms',
        ] as $table) {
            $schema->drop($table);
        }
    }
};
