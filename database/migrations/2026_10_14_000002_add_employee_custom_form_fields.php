<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Modules\Core\Services\FormAccessCatalog;
use App\Services\ModuleMenuCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('form_access_fields', 'input_type')) {
            Schema::table('form_access_fields', function (Blueprint $table) {
                $table->string('input_type', 32)->default('text')->after('label');
                $table->boolean('is_required')->default(false)->after('input_type');
                $table->json('options')->nullable()->after('is_required');
                $table->string('help_text')->nullable()->after('options');
            });
        }

        if (! Schema::hasColumn('employee', 'customAttributes')) {
            Schema::table('employee', function (Blueprint $table) {
                $table->json('customAttributes')->nullable()->after('internalId2');
            });
        }

        Permission::query()->firstOrCreate(
            ['name' => 'view-hr-settings', 'guard_name' => 'web'],
            ['id' => (string) Str::uuid()],
        );

        $hrSettingsId = Menu::query()->where('system_key', 'hr.settings')->value('id');
        if ($hrSettingsId && ! Menu::query()->where('system_key', 'hr.settings.employee_fields')->exists()) {
            Menu::query()->create([
                'id' => (string) Str::uuid(),
                'parent_id' => $hrSettingsId,
                'title' => 'Employee fields',
                'route' => '/hr/settings/employee-fields',
                'icon' => 'tune',
                'permission' => ModuleMenuCatalog::HR_SETTINGS_PERMISSION,
                'order' => 18,
                'is_active' => true,
                'type' => 'submenu',
                'module_code' => 'hr',
                'source' => 'system',
                'system_key' => 'hr.settings.employee_fields',
            ]);
        }

        app(FormAccessCatalog::class)->forget();
        app(FormAccessCatalog::class)->syncEmployee();
    }

    public function down(): void
    {
        Menu::query()->where('system_key', 'hr.settings.employee_fields')->delete();

        Schema::table('employee', function (Blueprint $table) {
            $table->dropColumn('customAttributes');
        });

        Schema::table('form_access_fields', function (Blueprint $table) {
            $table->dropColumn(['input_type', 'is_required', 'options', 'help_text']);
        });
    }
};
