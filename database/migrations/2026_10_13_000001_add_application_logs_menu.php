<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $view = Permission::firstOrCreate([
            'name' => 'view-application-logs',
            'guard_name' => 'web',
        ]);
        $crud = Permission::firstOrCreate([
            'name' => 'application-logs-crud',
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

        Menu::query()->updateOrCreate(
            ['system_key' => 'admin.application_logs'],
            [
                'parent_id' => $adminSettingsId,
                'title' => 'Application Logs',
                'route' => '/admin/settings/application-logs',
                'icon' => 'terminal',
                'permission' => 'view-application-logs',
                'order' => 13,
                'is_active' => true,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('system_key', 'admin.application_logs')->delete();
        }

        Permission::query()->whereIn('name', [
            'view-application-logs',
            'application-logs-crud',
        ])->delete();
    }
};
