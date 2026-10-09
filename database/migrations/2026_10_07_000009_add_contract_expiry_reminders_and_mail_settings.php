<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Hr\Models\HrTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_setting')) {
            Schema::table('hr_setting', function (Blueprint $table) {
                if (! Schema::hasColumn('hr_setting', 'contract_expiry_enabled')) {
                    $table->boolean('contract_expiry_enabled')->default(true);
                }
                if (! Schema::hasColumn('hr_setting', 'contract_expiry_offsets')) {
                    $table->json('contract_expiry_offsets')->nullable();
                }
            });
        }

        if (! Schema::hasTable('contract_expiry_reminder_sends')) {
            Schema::create('contract_expiry_reminder_sends', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('employment_detail_id')->constrained('employment_detail')->cascadeOnDelete();
                $table->unsignedSmallInteger('offset_value');
                $table->string('offset_unit', 16);
                $table->date('sent_on');
                $table->timestamps();
                $table->unique(
                    ['employment_detail_id', 'offset_value', 'offset_unit'],
                    'contract_expiry_reminder_unique',
                );
            });
        }

        if (! Schema::hasTable('mail_settings')) {
            Schema::create('mail_settings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->boolean('enabled')->default(true);
                $table->string('mailer', 20)->default('smtp');
                $table->string('host', 255)->nullable();
                $table->unsignedInteger('port')->nullable();
                $table->string('username', 255)->nullable();
                $table->text('password')->nullable();
                $table->string('encryption', 16)->nullable();
                $table->string('from_address', 255)->nullable();
                $table->string('from_name', 255)->nullable();
                $table->timestamps();
            });
        }

        $this->seedContractTemplate();
        $this->seedPermissionsAndMenu();
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('system_key', 'admin.email')->delete();
        }

        Permission::query()->whereIn('name', ['view-email-settings', 'email-settings-crud'])->delete();

        HrTemplate::query()->where('system_key', 'email.contract_expiring')->delete();

        Schema::dropIfExists('contract_expiry_reminder_sends');
        Schema::dropIfExists('mail_settings');

        if (Schema::hasTable('hr_setting')) {
            Schema::table('hr_setting', function (Blueprint $table) {
                if (Schema::hasColumn('hr_setting', 'contract_expiry_enabled')) {
                    $table->dropColumn('contract_expiry_enabled');
                }
                if (Schema::hasColumn('hr_setting', 'contract_expiry_offsets')) {
                    $table->dropColumn('contract_expiry_offsets');
                }
            });
        }
    }

    private function seedContractTemplate(): void
    {
        if (! Schema::hasTable('hr_templates')) {
            return;
        }

        $existing = HrTemplate::query()->where('system_key', 'email.contract_expiring')->first();
        $payload = [
            'channel' => 'email',
            'category' => 'contracts',
            'system_key' => 'email.contract_expiring',
            'name' => 'Contract expiring',
            'subject' => 'Contract expiring in {{reminder_window}}: {{employee_name}}',
            'body' => '<p>Hello {{recipient_name}},</p>'
                .'<p>This is a reminder that the employment contract for <strong>{{employee_name}}</strong> ({{employee_code}}) expires on <strong>{{contract_end_date}}</strong> ({{days_until_expiry}} day(s)).</p>'
                .'<p>Job title: {{job_title}}<br>Department: {{department}}<br>Contract type: {{contract_type}}</p>'
                .'<p>You are receiving this as {{recipient_role}}. Please follow up with the employee and Human Resources as needed.</p>'
                .'<p>{{company_name}}</p>',
            'is_system' => true,
            'is_active' => true,
        ];

        if ($existing) {
            return;
        }

        HrTemplate::query()->create(array_merge($payload, [
            'id' => (string) Str::uuid(),
        ]));
    }

    private function seedPermissionsAndMenu(): void
    {
        $view = Permission::firstOrCreate([
            'name' => 'view-email-settings',
            'guard_name' => 'web',
        ]);
        $crud = Permission::firstOrCreate([
            'name' => 'email-settings-crud',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereIn('name', ['admin'])
            ->get()
            ->each(function (Role $role) use ($view, $crud) {
                $role->givePermissionTo([$view, $crud]);
                $role->users()->get()->each(
                    fn ($user) => $user->givePermissionTo([$view, $crud]),
                );
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (! Schema::hasTable('menus')) {
            return;
        }

        $adminSettingsId = Menu::query()->where('system_key', 'admin.settings')->value('id');
        if (! $adminSettingsId) {
            return;
        }

        $existing = Menu::query()->where('system_key', 'admin.email')->first();
        $payload = [
            'parent_id' => $adminSettingsId,
            'title' => 'Email',
            'route' => '/admin/settings/email',
            'icon' => 'email',
            'permission' => 'view-email-settings',
            'order' => 11,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'admin',
            'source' => 'system',
        ];

        if ($existing) {
            return;
        }

        Menu::query()->create(array_merge($payload, [
            'id' => (string) Str::uuid(),
            'system_key' => 'admin.email',
        ]));
    }
};
