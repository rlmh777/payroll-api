<?php

use App\Enums\PipelineAssigneeType;
use App\Models\Menu;
use App\Models\PipelineTemplate;
use App\Modules\Workflow\Services\PipelineEngine;
use App\Services\ModuleMenuCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_setting') && ! Schema::hasColumn('hr_setting', 'profile_change_approval')) {
            Schema::table('hr_setting', function (Blueprint $table) {
                $table->string('profile_change_approval', 32)->default('hr');
            });
        }

        $this->seedSupervisorPipeline();
        $this->seedMenu();
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('system_key', 'hr.settings.profile_changes')->delete();
        }

        if (Schema::hasTable('pipeline_templates')) {
            PipelineTemplate::query()->where('key', 'employee_profile_change_supervisor')->delete();
        }

        if (Schema::hasTable('hr_setting') && Schema::hasColumn('hr_setting', 'profile_change_approval')) {
            Schema::table('hr_setting', function (Blueprint $table) {
                $table->dropColumn('profile_change_approval');
            });
        }
    }

    private function seedSupervisorPipeline(): void
    {
        if (! Schema::hasTable('pipeline_templates') || ! class_exists(PipelineTemplate::class)) {
            return;
        }

        $template = PipelineTemplate::query()->firstOrCreate(
            ['key' => 'employee_profile_change_supervisor'],
            [
                'name' => 'Employee profile change (supervisor)',
                'subject_type' => PipelineEngine::SUBJECT_PROFILE_CHANGE,
                'description' => 'The employee supervisor approves address, phone, and email updates. Approval applies the change.',
                'completion_status' => 'approved',
                'rejection_status' => 'rejected',
                'cancellation_status' => 'cancelled',
                'is_active' => true,
            ],
        );

        if ($template->steps()->exists()) {
            return;
        }

        $template->steps()->create([
            'key' => 'supervisor',
            'label' => 'Supervisor approval',
            'sort_order' => 1,
            'assignee_type' => PipelineAssigneeType::Supervisor->value,
            'domain_status' => 'pending_supervisor',
            'actions' => ['approve', 'reject', 'return', 'cancel'],
            'ui_config' => ['mode' => 'review'],
        ]);
    }

    private function seedMenu(): void
    {
        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        if (Menu::query()->where('system_key', 'hr.settings.profile_changes')->exists()) {
            return;
        }

        $hrSettingsId = Menu::query()->where('system_key', 'hr.settings')->value('id');
        if (! $hrSettingsId) {
            return;
        }

        Menu::query()->create([
            'id' => (string) Str::uuid(),
            'parent_id' => $hrSettingsId,
            'title' => 'Profile changes',
            'route' => '/hr/settings/profile-changes',
            'icon' => 'edit_location_alt',
            'permission' => ModuleMenuCatalog::HR_SETTINGS_PERMISSION,
            'order' => 19,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'hr',
            'source' => 'system',
            'system_key' => 'hr.settings.profile_changes',
        ]);
    }
};
