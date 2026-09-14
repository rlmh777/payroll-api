<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('subject_type', 64);
            $table->text('description')->nullable();
            $table->string('completion_status', 64)->nullable();
            $table->string('rejection_status', 64)->nullable();
            $table->string('cancellation_status', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['subject_type', 'is_active']);
        });

        Schema::create('pipeline_template_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_template_id');
            $table->string('key', 64);
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('assignee_type', 64);
            $table->string('assignee_role')->nullable();
            $table->string('domain_status', 64)->nullable();
            $table->json('actions')->nullable();
            $table->json('ui_config')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_template_id')
                ->references('id')
                ->on('pipeline_templates')
                ->cascadeOnDelete();
            $table->unique(['pipeline_template_id', 'key']);
            $table->index(['pipeline_template_id', 'sort_order']);
        });

        Schema::create('pipeline_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_template_id');
            $table->uuid('current_step_id')->nullable();
            $table->string('subject_type', 64);
            $table->uuid('subject_id');
            $table->string('status', 32)->default('in_progress');
            $table->uuid('started_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_template_id')
                ->references('id')
                ->on('pipeline_templates')
                ->restrictOnDelete();
            $table->foreign('current_step_id')
                ->references('id')
                ->on('pipeline_template_steps')
                ->nullOnDelete();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'current_step_id']);
        });

        Schema::create('pipeline_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pipeline_instance_id');
            $table->uuid('from_step_id')->nullable();
            $table->uuid('to_step_id')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->string('action', 32);
            $table->text('note')->nullable();
            $table->string('resulting_status', 64)->nullable();
            $table->timestamps();

            $table->foreign('pipeline_instance_id')
                ->references('id')
                ->on('pipeline_instances')
                ->cascadeOnDelete();
            $table->index(['pipeline_instance_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_actions');
        Schema::dropIfExists('pipeline_instances');
        Schema::dropIfExists('pipeline_template_steps');
        Schema::dropIfExists('pipeline_templates');
    }
};
