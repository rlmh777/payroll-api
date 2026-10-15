<?php

use App\Models\Menu;
use App\Modules\Hr\Models\HrTemplate;
use App\Services\ModuleMenuCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_templates')) {
            Schema::create('hr_templates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('channel', 16);
                $table->string('category', 32);
                $table->string('system_key')->nullable()->unique();
                $table->string('name');
                $table->string('subject')->nullable();
                $table->longText('body');
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['channel', 'category']);
            });
        }

        $this->seedTemplates();
        $this->seedMenus();
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->whereIn('system_key', [
                'hr.settings.letter_templates',
                'hr.settings.email_templates',
            ])->delete();
        }

        Schema::dropIfExists('hr_templates');
    }

    private function seedTemplates(): void
    {
        $now = now();

        foreach ($this->templates() as $template) {
            $existing = HrTemplate::query()->where('system_key', $template['system_key'])->first();
            $payload = array_merge($template, [
                'is_system' => true,
                'is_active' => true,
                'updated_at' => $now,
            ]);

            if ($existing) {
                continue;
            }

            HrTemplate::query()->create(array_merge($payload, [
                'id' => (string) Str::uuid(),
                'created_at' => $now,
            ]));
        }
    }

    private function seedMenus(): void
    {
        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $hrSettingsId = Menu::query()->where('system_key', 'hr.settings')->value('id');
        if (! $hrSettingsId) {
            return;
        }

        $permission = ModuleMenuCatalog::HR_SETTINGS_PERMISSION;

        $this->ensureMenu([
            'parent_id' => $hrSettingsId,
            'title' => 'Letter templates',
            'route' => '/hr/settings/letter-templates',
            'icon' => 'description',
            'permission' => $permission,
            'order' => 14,
            'type' => 'submenu',
            'system_key' => 'hr.settings.letter_templates',
            'module_code' => 'hr',
        ]);

        $this->ensureMenu([
            'parent_id' => $hrSettingsId,
            'title' => 'Email templates',
            'route' => '/hr/settings/email-templates',
            'icon' => 'email',
            'permission' => $permission,
            'order' => 15,
            'type' => 'submenu',
            'system_key' => 'hr.settings.email_templates',
            'module_code' => 'hr',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ensureMenu(array $attributes): void
    {
        $existing = Menu::query()->where('system_key', $attributes['system_key'])->first();
        $payload = array_merge([
            'source' => 'system',
            'is_active' => true,
        ], $attributes);

        if ($existing) {
            return;
        }

        Menu::query()->create(array_merge($payload, [
            'id' => (string) Str::uuid(),
        ]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            [
                'channel' => 'letter',
                'category' => 'bank',
                'system_key' => 'letter.bank',
                'name' => 'Letter of employment (bank)',
                'subject' => null,
                'body' => '<p>{{current_date}}</p><p>To whom it may concern,</p><p>This letter confirms that <strong>{{employee_name}}</strong> ({{employee_code}}) is employed by {{company_name}} as <strong>{{job_title}}</strong> in the {{department}} department.</p><p>This confirmation is provided at the employee’s request for {{purpose}}.</p><p>Sincerely,<br>{{company_name}} Human Resources</p>',
            ],
            [
                'channel' => 'letter',
                'category' => 'embassy',
                'system_key' => 'letter.embassy',
                'name' => 'Letter of employment (embassy)',
                'subject' => null,
                'body' => '<p>{{current_date}}</p><p>To the Consular Officer,</p><p>This letter confirms that <strong>{{employee_name}}</strong> is employed by {{company_name}} as <strong>{{job_title}}</strong>, based at {{worksite}}, and has been employed since {{hire_date}}.</p><p>This letter is issued to support a visa or travel application for {{purpose}}.</p><p>Sincerely,<br>{{company_name}} Human Resources</p>',
            ],
            [
                'channel' => 'letter',
                'category' => 'other',
                'system_key' => 'letter.other',
                'name' => 'Letter of employment (general)',
                'subject' => null,
                'body' => '<p>{{current_date}}</p><p>To {{recipient_name}},</p><p>This letter confirms that <strong>{{employee_name}}</strong> is employed by {{company_name}} as <strong>{{job_title}}</strong> in {{department}}.</p><p>Please contact Human Resources if you need further verification.</p><p>Sincerely,<br>{{company_name}} Human Resources</p>',
            ],
            [
                'channel' => 'email',
                'category' => 'leave',
                'system_key' => 'email.leave_requested',
                'name' => 'Leave request received',
                'subject' => 'Your {{leave_type}} request was received',
                'body' => '<p>Hello {{employee_name}},</p><p>We received your <strong>{{leave_type}}</strong> request for {{leave_dates}}. Current status: {{leave_status}}.</p><p>You will be notified when it is reviewed.</p><p>{{company_name}} Human Resources</p>',
            ],
            [
                'channel' => 'email',
                'category' => 'leave',
                'system_key' => 'email.leave_approved',
                'name' => 'Leave approved',
                'subject' => 'Your {{leave_type}} request was approved',
                'body' => '<p>Hello {{employee_name}},</p><p>Your <strong>{{leave_type}}</strong> request for {{leave_dates}} has been <strong>approved</strong>.</p><p>{{company_name}} Human Resources</p>',
            ],
            [
                'channel' => 'email',
                'category' => 'leave',
                'system_key' => 'email.leave_rejected',
                'name' => 'Leave declined',
                'subject' => 'Update on your {{leave_type}} request',
                'body' => '<p>Hello {{employee_name}},</p><p>Your <strong>{{leave_type}}</strong> request for {{leave_dates}} was not approved. Status: {{leave_status}}.</p><p>Please speak with your supervisor if you have questions.</p><p>{{company_name}} Human Resources</p>',
            ],
            [
                'channel' => 'email',
                'category' => 'security',
                'system_key' => 'email.password_changed',
                'name' => 'Password changed',
                'subject' => 'Your {{company_name}} password was changed',
                'body' => '<p>Hello {{employee_name}},</p><p>Your account password was changed. If you did not make this change, contact Human Resources immediately.</p><p>{{company_name}}</p>',
            ],
            [
                'channel' => 'email',
                'category' => 'security',
                'system_key' => 'email.password_reset',
                'name' => 'Password reset',
                'subject' => 'Reset your {{company_name}} password',
                'body' => '<p>Hello {{employee_name}},</p><p>Use the link below to reset your password:</p><p>{{reset_link}}</p><p>If you did not request this, you can ignore this email.</p><p>{{company_name}}</p>',
            ],
            [
                'channel' => 'email',
                'category' => 'recruiting',
                'system_key' => 'email.job_application_received',
                'name' => 'Job application received',
                'subject' => 'We received your application for {{vacancy_title}}',
                'body' => '<p>Hello {{employee_name}},</p><p>Thank you for applying for <strong>{{vacancy_title}}</strong>. Current status: {{application_status}}.</p><p>We will contact you if there is an update.</p><p>{{company_name}} Recruiting</p>',
            ],
            [
                'channel' => 'email',
                'category' => 'recruiting',
                'system_key' => 'email.job_application_update',
                'name' => 'Job application update',
                'subject' => 'Update on your {{vacancy_title}} application',
                'body' => '<p>Hello {{employee_name}},</p><p>There is an update on your application for <strong>{{vacancy_title}}</strong>. Status: {{application_status}}.</p><p>{{company_name}} Recruiting</p>',
            ],
            [
                'channel' => 'email',
                'category' => 'other',
                'system_key' => 'email.other',
                'name' => 'General employee notice',
                'subject' => 'A message from {{company_name}}',
                'body' => '<p>Hello {{employee_name}},</p><p>Please see this notice from {{company_name}} Human Resources.</p>',
            ],
        ];
    }
};
