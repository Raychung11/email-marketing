<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * Automation journey engine: TRIGGER → CONDITION → ACTION → WAIT → …
 *
 * Nodes and connections are stored as a graph rather than a linear list so a
 * condition can branch (yes/no) without a second table shape.
 */
return new class extends Migration {
    public function up(Schema $schema, Connection $connection): void
    {
        $schema->create('automations', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->bigInteger('workspace_id')->unsigned()->nullable();
            $table->uuid();
            $table->string('name', 200);
            $table->string('description', 255)->nullable();

            $table->enum('trigger_type', [
                'contact_created', 'lead_created', 'form_submitted', 'tag_added',
                'tag_removed', 'list_joined', 'email_opened', 'email_clicked',
                'purchase', 'booking', 'customer_inactive', 'date_reached',
                'birthday', 'anniversary', 'api_event', 'webhook',
                'lead_stage_changed', 'conversion',
            ]);
            $table->json('trigger_config')->nullable();

            $table->enum('status', ['draft', 'active', 'paused', 'archived'])->default('draft');
            $table->string('template_key', 60)->nullable();

            // Re-entry control: without this, a contact who keeps triggering an
            // automation gets mailed repeatedly.
            $table->boolean('allow_reentry')->default(false);
            $table->integer('reentry_cooldown_hours')->default(720);
            $table->integer('max_runs_per_contact')->default(1);

            $table->integer('entered_count')->default(0);
            $table->integer('completed_count')->default(0);
            $table->integer('active_count')->default(0);

            $table->bigInteger('created_by_user_id')->unsigned()->nullable();
            $table->bigInteger('activated_by_user_id')->unsigned()->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organisation_id', 'uuid']);
            $table->index(['organisation_id', 'status']);
            $table->index(['trigger_type', 'status']);
            $table->foreign('organisation_id', 'organisations');
        });

        $schema->create('automation_nodes', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('automation_id');
            $table->string('node_key', 40);
            $table->enum('node_type', ['trigger', 'condition', 'action', 'wait', 'split', 'exit']);

            // For action nodes: send_email, add_tag, remove_tag, add_to_list,
            // remove_from_list, update_contact, create_lead, create_task,
            // assign_user, webhook, send_sms (phase 7), send_whatsapp (phase 7).
            $table->string('action_type', 40)->nullable();
            $table->json('config')->nullable();

            // For wait nodes.
            $table->integer('wait_minutes')->nullable();
            $table->string('wait_until_field', 60)->nullable();

            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);
            $table->timestamps();

            $table->unique(['automation_id', 'node_key']);
            $table->index(['organisation_id', 'automation_id']);
            $table->foreign('automation_id', 'automations');
        });

        $schema->create('automation_connections', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('automation_id');
            $table->foreignId('from_node_id');
            $table->foreignId('to_node_id');
            // 'yes' / 'no' for condition branches, 'default' otherwise.
            $table->string('branch', 20)->default('default');
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at');

            $table->unique(['from_node_id', 'to_node_id', 'branch'], 'uniq_automation_edge');
            $table->index(['organisation_id', 'automation_id']);
            $table->foreign('automation_id', 'automations');
            $table->foreign('from_node_id', 'automation_nodes');
            $table->foreign('to_node_id', 'automation_nodes');
        });

        $schema->create('automation_runs', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('automation_id');
            $table->foreignId('contact_id');
            $table->bigInteger('current_node_id')->unsigned()->nullable();
            $table->enum('status', ['active', 'waiting', 'completed', 'failed', 'cancelled', 'exited'])
                ->default('active');
            // The scheduler wakes runs whose timer has elapsed; indexed with
            // status so that scan stays cheap as run history grows.
            $table->dateTime('resume_at')->nullable();
            $table->json('context')->nullable();
            $table->string('trigger_reference', 120)->nullable();
            $table->integer('step_count')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'resume_at']);
            $table->index(['organisation_id', 'automation_id', 'status']);
            $table->index(['organisation_id', 'contact_id']);
            $table->foreign('automation_id', 'automations');
            $table->foreign('contact_id', 'contacts');
        });

        $schema->create('automation_run_logs', static function (Blueprint $table): void {
            $table->id();
            $table->organisationId();
            $table->foreignId('automation_run_id');
            $table->bigInteger('automation_node_id')->unsigned()->nullable();
            $table->string('node_type', 30)->nullable();
            $table->string('action_type', 40)->nullable();
            $table->enum('outcome', ['entered', 'passed', 'failed', 'skipped', 'waited', 'error', 'blocked'])
                ->default('entered');
            // e.g. a compliance block recorded here rather than silently dropped.
            $table->string('reason_code', 80)->nullable();
            $table->string('message', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');

            $table->index(['automation_run_id', 'created_at']);
            $table->index(['organisation_id', 'created_at']);
            $table->foreign('automation_run_id', 'automation_runs');
        });
    }

    public function down(Schema $schema, Connection $connection): void
    {
        foreach ([
            'automation_run_logs', 'automation_runs', 'automation_connections',
            'automation_nodes', 'automations',
        ] as $table) {
            $schema->drop($table);
        }
    }
};
