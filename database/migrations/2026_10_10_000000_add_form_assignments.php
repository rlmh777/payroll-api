<?php

use App\Models\PipelineTemplate;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('form_template_id');
            $table->string('name')->nullable();
            $table->string('audience_type', 32)->default('company');
            $table->boolean('show_on_login')->default(true);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->uuid('pipeline_template_id')->nullable();
            $table->timestamps();

            $table->foreign('form_template_id')->references('id')->on('form_templates')->cascadeOnDelete();
            $table->foreign('pipeline_template_id')->references('id')->on('pipeline_templates')->nullOnDelete();
            $table->index(['is_active', 'show_on_login', 'is_required']);
        });

        Schema::create('form_assignment_department', function (Blueprint $table) {
            $table->uuid('form_assignment_id');
            $table->unsignedBigInteger('department_id');
            $table->timestamps();

            $table->primary(['form_assignment_id', 'department_id']);
            $table->foreign('form_assignment_id')->references('id')->on('form_assignments')->cascadeOnDelete();
            $table->foreign('department_id')->references('id')->on('department')->cascadeOnDelete();
        });

        Schema::create('form_assignment_employee_group', function (Blueprint $table) {
            $table->uuid('form_assignment_id');
            $table->uuid('employee_group_id');
            $table->timestamps();

            $table->primary(['form_assignment_id', 'employee_group_id']);
            $table->foreign('form_assignment_id')->references('id')->on('form_assignments')->cascadeOnDelete();
            $table->foreign('employee_group_id')->references('id')->on('employee_group')->cascadeOnDelete();
        });

        Schema::create('form_assignment_employee', function (Blueprint $table) {
            $table->uuid('form_assignment_id');
            $table->uuid('employee_id');
            $table->timestamps();

            $table->primary(['form_assignment_id', 'employee_id']);
            $table->foreign('form_assignment_id')->references('id')->on('form_assignments')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employee')->cascadeOnDelete();
        });

        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropUnique(['vacancy_application_id', 'user_id']);
            $table->uuid('form_assignment_id')->nullable()->after('form_template_id');
            $table->foreign('form_assignment_id')->references('id')->on('form_assignments')->cascadeOnDelete();
        });

        $this->makeVacancyColumnsNullable();

        Schema::table('form_responses', function (Blueprint $table) {
            $table->unique(['vacancy_application_id', 'user_id']);
            $table->unique(['form_assignment_id', 'user_id']);
        });

        $this->seedFormReviewPipeline();
    }

    public function down(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropUnique(['form_assignment_id', 'user_id']);
            $table->dropUnique(['vacancy_application_id', 'user_id']);
            $table->dropForeign(['form_assignment_id']);
            $table->dropColumn('form_assignment_id');
            $table->unique(['vacancy_application_id', 'user_id']);
        });

        Schema::dropIfExists('form_assignment_employee');
        Schema::dropIfExists('form_assignment_employee_group');
        Schema::dropIfExists('form_assignment_department');
        Schema::dropIfExists('form_assignments');

        PipelineTemplate::query()->where('key', 'form_review')->delete();
    }

    private function makeVacancyColumnsNullable(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE form_responses ALTER COLUMN vacancy_id DROP NOT NULL');
            DB::statement('ALTER TABLE form_responses ALTER COLUMN vacancy_application_id DROP NOT NULL');

            return;
        }

        Schema::table('form_responses', function (Blueprint $table) {
            $table->uuid('vacancy_id')->nullable()->change();
            $table->uuid('vacancy_application_id')->nullable()->change();
        });
    }

    private function seedFormReviewPipeline(): void
    {
        if (PipelineTemplate::query()->where('key', 'form_review')->exists()) {
            return;
        }

        $template = PipelineTemplate::query()->create([
            'key' => 'form_review',
            'name' => 'Form review',
            'subject_type' => PipelineEngine::SUBJECT_FORM_RESPONSE,
            'description' => 'Supervisor reviews a submitted form, then approves or rejects it.',
            'completion_status' => 'approved',
            'rejection_status' => 'rejected',
            'cancellation_status' => 'cancelled',
            'is_active' => true,
        ]);

        $template->steps()->create([
            'key' => 'supervisor',
            'label' => 'Supervisor review',
            'sort_order' => 1,
            'assignee_type' => 'supervisor',
            'assignee_role' => null,
            'domain_status' => 'pending_review',
            'actions' => ['approve', 'reject'],
            'ui_config' => ['mode' => 'review'],
        ]);
    }
};
