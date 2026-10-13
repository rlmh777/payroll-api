<?php

use App\Enums\PipelineAssigneeType;
use App\Models\Menu;
use App\Models\PipelineTemplate;
use App\Modules\Workflow\Services\PipelineEngine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_profile_change_requests')) {
            Schema::create('employee_profile_change_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('employee_id');
                $table->uuid('submitted_by_user_id')->nullable();
                $table->uuid('pipeline_template_id')->nullable();
                $table->string('status', 32)->default('pending');
                $table->json('current_values')->nullable();
                $table->json('proposed_values')->nullable();
                $table->text('reviewer_notes')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();

                $table->foreign('employee_id')->references('id')->on('employee')->restrictOnDelete();
                $table->foreign('submitted_by_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('pipeline_template_id')->references('id')->on('pipeline_templates')->nullOnDelete();
                $table->index(['status', 'created_at']);
                $table->index('employee_id');
            });
        }

        $this->seedPipeline();
        $this->seedMenus();
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->whereIn('system_key', [
                'employee.profile_changes',
                'hr.profile_changes',
            ])->delete();
        }

        Schema::dropIfExists('employee_profile_change_requests');
    }

    private function seedPipeline(): void
    {
        if (! class_exists(PipelineTemplate::class) || ! Schema::hasTable('pipeline_templates')) {
            return;
        }

        if (PipelineTemplate::query()->where('key', 'employee_profile_change')->exists()) {
            return;
        }

        $template = PipelineTemplate::query()->create([
            'key' => 'employee_profile_change',
            'name' => 'Employee profile change',
            'subject_type' => PipelineEngine::SUBJECT_PROFILE_CHANGE,
            'description' => 'HR reviews address, phone, and email updates. Add supervisor or other steps in Admin → Pipelines if your company needs them first.',
            'completion_status' => 'approved',
            'rejection_status' => 'rejected',
            'cancellation_status' => 'cancelled',
            'is_active' => true,
        ]);

        $template->steps()->create([
            'key' => 'hr',
            'label' => 'HR approval',
            'sort_order' => 1,
            'assignee_type' => PipelineAssigneeType::Admin->value,
            'domain_status' => 'pending_hr',
            'actions' => ['approve', 'reject', 'return', 'cancel'],
            'ui_config' => ['mode' => 'review'],
        ]);
    }

    private function seedMenus(): void
    {
        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $this->ensureMenu([
            'system_key' => 'employee.profile_changes',
            'module_code' => 'employee',
            'title' => 'Profile changes',
            'route' => '/employee/profile-changes',
            'icon' => 'edit_location_alt',
            'permission' => null,
            'order' => 3,
            'type' => 'menu',
        ]);

        $this->ensureMenu([
            'system_key' => 'hr.profile_changes',
            'module_code' => 'hr',
            'title' => 'Profile changes',
            'route' => '/hr/profile-changes',
            'icon' => 'edit_location_alt',
            'permission' => 'view-employees|employees-crud',
            'order' => 2,
            'type' => 'menu',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ensureMenu(array $attributes): void
    {
        $existing = Menu::query()->where('system_key', $attributes['system_key'])->first();
        if ($existing) {
            return;
        }

        Menu::query()->create(array_merge([
            'parent_id' => null,
            'source' => 'system',
            'is_active' => true,
        ], $attributes));
    }
};
