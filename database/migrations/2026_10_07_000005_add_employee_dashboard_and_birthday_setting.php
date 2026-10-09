<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Services\ModuleMenuCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_setting') === false) {
            Schema::create('hr_setting', function (Blueprint $table) {
                $table->id();
                $table->string('birthday_visibility', 32)->default('company');
                $table->timestamps();
            });

            DB::table('hr_setting')->insert([
                'birthday_visibility' => 'company',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'employee')->update([
                'default_route' => '/employee',
                'updated_at' => now(),
            ]);
        }

        if (class_exists(Permission::class) && Schema::hasTable('permissions')) {
            $permission = Permission::query()->firstOrCreate([
                'name' => 'view-hr-settings',
                'guard_name' => 'web',
            ]);
            $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
            $admin?->givePermissionTo($permission);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        }

        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $this->ensureMenu([
            'parent_id' => null,
            'title' => 'Dashboard',
            'route' => '/employee',
            'icon' => 'dashboard',
            'permission' => null,
            'order' => 1,
            'type' => 'menu',
            'system_key' => 'employee.dashboard',
            'module_code' => 'employee',
        ]);

        $hrSettingsId = Menu::query()->where('system_key', 'hr.settings')->value('id');
        if ($hrSettingsId) {
            $this->ensureMenu([
                'parent_id' => $hrSettingsId,
                'title' => 'Birthdays',
                'route' => '/hr/settings/birthdays',
                'icon' => 'cake',
                'permission' => ModuleMenuCatalog::HR_SETTINGS_PERMISSION,
                'order' => 13,
                'type' => 'submenu',
                'system_key' => 'hr.settings.birthdays',
                'module_code' => 'hr',
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->whereIn('system_key', ['employee.dashboard', 'hr.settings.birthdays'])->delete();
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'employee')->update([
                'default_route' => '/hr/employees',
                'updated_at' => now(),
            ]);
        }

        Schema::dropIfExists('hr_setting');
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
};
