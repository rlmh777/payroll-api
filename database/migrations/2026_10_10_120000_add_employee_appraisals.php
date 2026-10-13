<?php

use App\Enums\PipelineAssigneeType;
use App\Models\HrSetting;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\PipelineTemplate;
use App\Models\Role;
use App\Modules\Hr\Models\FormTemplate;
use App\Modules\Hr\Models\HrTemplate;
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
        if (Schema::hasTable('hr_setting')) {
            Schema::table('hr_setting', function (Blueprint $table) {
                if (! Schema::hasColumn('hr_setting', 'appraisal_reminder_enabled')) {
                    $table->boolean('appraisal_reminder_enabled')->default(true);
                }
                if (! Schema::hasColumn('hr_setting', 'appraisal_reminder_interval_value')) {
                    $table->unsignedTinyInteger('appraisal_reminder_interval_value')->default(2);
                }
                if (! Schema::hasColumn('hr_setting', 'appraisal_reminder_interval_unit')) {
                    $table->string('appraisal_reminder_interval_unit', 16)->default('days');
                }
            });
        }

        if (! Schema::hasTable('employee_appraisals')) {
            Schema::create('employee_appraisals', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('employee_id');
                $table->uuid('form_template_id')->nullable();
                $table->uuid('form_response_id')->nullable();
                $table->uuid('pipeline_template_id')->nullable();
                $table->uuid('initiated_by_user_id')->nullable();
                $table->string('period_label');
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->string('status', 32)->default('pending');
                $table->boolean('hr_renew_contract')->nullable();
                $table->boolean('hr_increment_approved')->nullable();
                $table->string('hr_increment_type', 16)->nullable();
                $table->decimal('hr_increment_value', 12, 2)->nullable();
                $table->text('hr_notes')->nullable();
                $table->text('accounts_notes')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('last_notified_at')->nullable();
                $table->timestamp('last_reminded_at')->nullable();
                $table->timestamps();

                $table->foreign('employee_id')->references('id')->on('employee')->restrictOnDelete();
                $table->foreign('form_template_id')->references('id')->on('form_templates')->nullOnDelete();
                $table->foreign('form_response_id')->references('id')->on('form_responses')->nullOnDelete();
                $table->foreign('pipeline_template_id')->references('id')->on('pipeline_templates')->nullOnDelete();
                $table->foreign('initiated_by_user_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['status', 'created_at']);
                $table->index('employee_id');
            });
        }

        $this->seedPermissions();
        $this->seedMenus();
        $this->seedEmailTemplates();
        $this->seedAppraisalForm();
        $this->seedAppraisalPipeline();

        HrSetting::clearCurrentCache();
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->whereIn('system_key', [
                'hr.appraisals',
                'employee.appraisals',
                'hr.settings.appraisals',
            ])->delete();
        }

        Schema::dropIfExists('employee_appraisals');

        if (Schema::hasTable('hr_setting')) {
            Schema::table('hr_setting', function (Blueprint $table) {
                foreach (['appraisal_reminder_enabled', 'appraisal_reminder_interval_value', 'appraisal_reminder_interval_unit'] as $column) {
                    if (Schema::hasColumn('hr_setting', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function seedPermissions(): void
    {
        if (! class_exists(Permission::class) || ! Schema::hasTable('permissions')) {
            return;
        }

        $names = ['view-appraisals', 'appraisals-crud'];
        $permissions = [];
        foreach ($names as $name) {
            $permissions[] = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin) {
            $admin->givePermissionTo($permissions);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function seedMenus(): void
    {
        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $this->ensureMenu([
            'parent_id' => null,
            'title' => 'Appraisals',
            'route' => '/hr/appraisals',
            'icon' => 'rate_review',
            'permission' => 'view-appraisals|employees-crud|appraisals-crud',
            'order' => 4,
            'type' => 'menu',
            'system_key' => 'hr.appraisals',
            'module_code' => 'hr',
        ]);

        $this->ensureMenu([
            'parent_id' => null,
            'title' => 'Appraisals',
            'route' => '/appraisals',
            'icon' => 'rate_review',
            'permission' => null,
            'order' => 3,
            'type' => 'menu',
            'system_key' => 'employee.appraisals',
            'module_code' => 'employee',
        ]);

        $hrSettingsId = Menu::query()->where('system_key', 'hr.settings')->value('id');
        if ($hrSettingsId) {
            $this->ensureMenu([
                'parent_id' => $hrSettingsId,
                'title' => 'Appraisals',
                'route' => '/hr/settings/appraisals',
                'icon' => 'notifications_active',
                'permission' => ModuleMenuCatalog::HR_SETTINGS_PERMISSION,
                'order' => 17,
                'type' => 'submenu',
                'system_key' => 'hr.settings.appraisals',
                'module_code' => 'hr',
            ]);
        }
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
            'id' => (string) Str::uuid(),
            'source' => 'system',
            'is_active' => true,
        ], $attributes));
    }

    private function seedEmailTemplates(): void
    {
        if (! class_exists(HrTemplate::class) || ! Schema::hasTable('hr_templates')) {
            return;
        }

        $now = now();
        foreach ($this->emailTemplates() as $template) {
            if (HrTemplate::query()->where('system_key', $template['system_key'])->exists()) {
                continue;
            }

            HrTemplate::query()->create(array_merge($template, [
                'id' => (string) Str::uuid(),
                'channel' => 'email',
                'category' => 'appraisal',
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function emailTemplates(): array
    {
        return [
            [
                'system_key' => 'email.appraisal_pending',
                'name' => 'Appraisal action required',
                'subject' => 'Action needed: appraisal for {{employee_name}}',
                'body' => '<p>Hello {{recipient_name}},</p><p>The appraisal for <strong>{{employee_name}}</strong> ({{period_label}}) is waiting at <strong>{{step_label}}</strong>.</p><p>Open it here: {{action_url}}</p><p>{{company_name}} Human Resources</p>',
            ],
            [
                'system_key' => 'email.appraisal_reminder',
                'name' => 'Appraisal reminder',
                'subject' => 'Reminder: appraisal for {{employee_name}} still needs your action',
                'body' => '<p>Hello {{recipient_name}},</p><p>This is a reminder that the appraisal for <strong>{{employee_name}}</strong> ({{period_label}}) is still waiting at <strong>{{step_label}}</strong>.</p><p>Open it here: {{action_url}}</p><p>{{company_name}} Human Resources</p>',
            ],
            [
                'system_key' => 'email.appraisal_completed',
                'name' => 'Appraisal completed',
                'subject' => 'Appraisal completed for {{employee_name}}',
                'body' => '<p>Hello {{recipient_name}},</p><p>The appraisal for <strong>{{employee_name}}</strong> ({{period_label}}) has been completed.</p><p>{{decision_summary}}</p><p>{{hr_recommendations}}</p><p>{{company_name}} Human Resources</p>',
            ],
            [
                'system_key' => 'email.appraisal_rejected',
                'name' => 'Appraisal declined',
                'subject' => 'Appraisal declined for {{employee_name}}',
                'body' => '<p>Hello {{recipient_name}},</p><p>The appraisal for <strong>{{employee_name}}</strong> ({{period_label}}) was not approved at <strong>{{step_label}}</strong>.</p><p>{{decision_summary}}</p><p>{{company_name}} Human Resources</p>',
            ],
        ];
    }

    private function seedAppraisalForm(): void
    {
        if (! class_exists(FormTemplate::class) || ! Schema::hasTable('form_templates')) {
            return;
        }

        if (FormTemplate::query()->where('purpose', FormTemplate::PURPOSE_APPRAISAL)->exists()) {
            return;
        }

        FormTemplate::query()->create([
            'name' => 'Annual employee appraisal',
            'description' => 'Default review form for performance, goals, and development.',
            'purpose' => FormTemplate::PURPOSE_APPRAISAL,
            'is_active' => true,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'overall_performance',
                        'type' => 'score',
                        'label' => 'Overall performance',
                        'required' => true,
                        'min' => 1,
                        'max' => 5,
                        'weight' => 1,
                    ],
                    [
                        'id' => 'strengths',
                        'type' => 'textarea',
                        'label' => 'Key strengths',
                        'required' => true,
                    ],
                    [
                        'id' => 'development',
                        'type' => 'textarea',
                        'label' => 'Development areas',
                        'required' => false,
                    ],
                    [
                        'id' => 'goals',
                        'type' => 'textarea',
                        'label' => 'Goals for the next period',
                        'required' => false,
                    ],
                ],
            ],
        ]);
    }

    private function seedAppraisalPipeline(): void
    {
        if (! class_exists(PipelineTemplate::class) || ! Schema::hasTable('pipeline_templates')) {
            return;
        }

        if (PipelineTemplate::query()->where('key', 'employee_appraisal')->exists()) {
            return;
        }

        $template = PipelineTemplate::query()->create([
            'key' => 'employee_appraisal',
            'name' => 'Employee appraisal',
            'subject_type' => PipelineEngine::SUBJECT_APPRAISAL,
            'description' => 'Supervisor, department head, HR (renew/increment), then accounts.',
            'completion_status' => 'approved',
            'rejection_status' => 'rejected',
            'cancellation_status' => 'cancelled',
            'is_active' => true,
        ]);

        $template->steps()->createMany([
            [
                'key' => 'supervisor',
                'label' => 'Supervisor review',
                'sort_order' => 1,
                'assignee_type' => PipelineAssigneeType::Supervisor->value,
                'domain_status' => 'pending_supervisor',
                'actions' => ['approve', 'reject', 'return'],
                'ui_config' => ['mode' => 'review'],
            ],
            [
                'key' => 'department_head',
                'label' => 'Head of department',
                'sort_order' => 2,
                'assignee_type' => PipelineAssigneeType::DepartmentHead->value,
                'domain_status' => 'pending_department_head',
                'actions' => ['approve', 'reject', 'return'],
                'ui_config' => ['mode' => 'review'],
            ],
            [
                'key' => 'hr',
                'label' => 'HR decision',
                'sort_order' => 3,
                'assignee_type' => PipelineAssigneeType::Admin->value,
                'domain_status' => 'pending_hr',
                'actions' => ['approve', 'reject', 'return'],
                'ui_config' => [
                    'mode' => 'hr_decision',
                    'fields' => ['renew_contract', 'increment'],
                ],
            ],
            [
                'key' => 'accounts',
                'label' => 'Accounts confirmation',
                'sort_order' => 4,
                'assignee_type' => PipelineAssigneeType::Role->value,
                'assignee_role' => 'accountant',
                'domain_status' => 'pending_accounts',
                'actions' => ['approve', 'return'],
                'ui_config' => ['mode' => 'accounts_confirmation'],
            ],
        ]);
    }
};
