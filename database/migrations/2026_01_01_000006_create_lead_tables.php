<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * Lead pipeline, stage history, tasks and notes — the sales side of the loop
 * that turns a captured enquiry into attributed revenue.
 */
return new class extends Migration {
    public function up(Schema $schema, Connection $connection): void
    {
        $schema->create('pipelines', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'slug']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('pipeline_stages', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('pipeline_id');
            $table->string('key', 40);
            $table->string('label', 80);
            $table->integer('sort_order')->default(0);
            $table->integer('probability')->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            // Drives the "lead has had no follow-up" recommendation and the lead
            // recovery automation.
            $table->integer('stale_after_hours')->nullable();
            $table->timestamps();

            $table->unique(['pipeline_id', 'key']);
            $table->index(['organisation_id', 'pipeline_id']);
            $table->foreign('pipeline_id', 'pipelines');
        });

        $schema->create('leads', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->uuid();
            $table->foreignId('contact_id');
            $table->foreignId('pipeline_id');
            $table->foreignId('pipeline_stage_id');

            $table->string('title', 200)->nullable();
            $table->text('enquiry')->nullable();
            $table->string('service_type', 120)->nullable();

            $table->decimal('estimated_value', 16, 2)->nullable();
            $table->decimal('actual_value', 16, 2)->nullable();
            $table->char('currency', 3)->nullable();

            $table->string('source', 40)->nullable();
            $table->string('source_detail', 255)->nullable();
            $table->bigInteger('campaign_id')->unsigned()->nullable();
            $table->string('utm_source', 80)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 120)->nullable();

            $table->bigInteger('assigned_user_id')->unsigned()->nullable();
            $table->integer('lead_score')->default(0);
            $table->enum('status', ['open', 'won', 'lost', 'archived'])->default('open');
            $table->string('lost_reason', 255)->nullable();

            $table->dateTime('next_followup_at')->nullable();
            $table->dateTime('first_response_at')->nullable();
            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('won_at')->nullable();
            $table->dateTime('lost_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'uuid']);
            $table->index(['organisation_id', 'pipeline_stage_id', 'status']);
            $table->index(['organisation_id', 'assigned_user_id', 'status']);
            $table->index(['organisation_id', 'next_followup_at']);
            $table->index(['organisation_id', 'contact_id']);
            $table->index(['organisation_id', 'created_at']);
            $table->foreign('contact_id', 'contacts');
            $table->foreign('pipeline_id', 'pipelines');
            $table->foreign('pipeline_stage_id', 'pipeline_stages');
        });

        $schema->create('lead_stage_history', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('lead_id');
            $table->bigInteger('from_stage_id')->unsigned()->nullable();
            $table->foreignId('to_stage_id');
            $table->bigInteger('changed_by_user_id')->unsigned()->nullable();
            $table->string('changed_via', 30)->default('manual'); // manual | automation | api
            $table->integer('duration_seconds')->nullable();
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at');

            $table->index(['organisation_id', 'lead_id', 'created_at']);
            $table->foreign('lead_id', 'leads');
        });

        $schema->create('lead_tasks', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('lead_id')->unsigned()->nullable();
            $table->bigInteger('contact_id')->unsigned()->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->enum('task_type', ['call', 'email', 'meeting', 'quote', 'followup', 'other'])->default('followup');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->bigInteger('assigned_user_id')->unsigned()->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->bigInteger('completed_by_user_id')->unsigned()->nullable();
            $table->enum('status', ['open', 'completed', 'cancelled'])->default('open');
            $table->string('created_via', 30)->default('manual');
            $table->bigInteger('automation_id')->unsigned()->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'assigned_user_id', 'status']);
            $table->index(['organisation_id', 'due_at', 'status']);
            $table->index(['organisation_id', 'lead_id']);
        });

        $schema->create('lead_notes', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('lead_id')->unsigned()->nullable();
            $table->bigInteger('contact_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['organisation_id', 'lead_id', 'created_at']);
            $table->index(['organisation_id', 'contact_id', 'created_at']);
        });
    }

    public function down(Schema $schema, Connection $connection): void
    {
        foreach ([
            'lead_notes', 'lead_tasks', 'lead_stage_history', 'leads',
            'pipeline_stages', 'pipelines',
        ] as $table) {
            $schema->drop($table);
        }
    }
};
