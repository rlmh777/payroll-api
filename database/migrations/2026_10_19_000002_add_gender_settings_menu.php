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
            'name' => 'view-gender',
            'guard_name' => 'web',
        ]);
        $crud = Permission::firstOrCreate([
            'name' => 'gender-crud',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereHas('permissions', function ($query) {
                $query->whereIn('name', [
                    'view-relationship',
                    'relationship-crud',
                    'view-degree',
                    'degree-crud',
                    'employees-crud',
                    'view-employees',
                ]);
            })
            ->orWhereIn('name', ['admin', 'super-admin', 'superadmin'])
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

        $hrSettingsId = Menu::query()->where('system_key', 'hr.settings')->value('id');
        if (! $hrSettingsId) {
            return;
        }

        Menu::query()->updateOrCreate(
            ['system_key' => 'hr.settings.gender'],
            [
                'parent_id' => $hrSettingsId,
                'title' => 'Gender',
                'route' => '/hr/settings/gender',
                'icon' => 'wc',
                'permission' => 'view-gender',
                'order' => 8,
                'is_active' => true,
                'type' => 'submenu',
                'module_code' => 'hr',
                'source' => 'system',
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('system_key', 'hr.settings.gender')->delete();
            Menu::query()->where('route', '/hr/settings/gender')->delete();
        }

        Permission::query()->where('name', 'view-gender')->delete();
    }
};
