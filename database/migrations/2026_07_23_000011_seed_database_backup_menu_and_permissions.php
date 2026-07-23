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
        $viewPermission = Permission::firstOrCreate([
            'name' => 'view-database-backup',
            'guard_name' => 'web',
        ]);
        $crudPermission = Permission::firstOrCreate([
            'name' => 'database-backup-crud',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereIn('name', ['admin'])
            ->get()
            ->each(function (Role $role) use ($viewPermission, $crudPermission) {
                $role->givePermissionTo([$viewPermission, $crudPermission]);
            });

        if (Schema::hasTable('menus')) {
            $settingsId = Menu::query()->where('title', 'Settings')->whereNull('parent_id')->value('id');
            if ($settingsId) {
                Menu::query()->updateOrCreate(
                    ['route' => '/settings/database-backup'],
                    [
                        'title' => 'Database Backup',
                        'icon' => 'fas fa-database',
                        'permission' => 'view-database-backup',
                        'parent_id' => $settingsId,
                        'order' => 20,
                        'is_active' => true,
                    ],
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('route', '/settings/database-backup')->delete();
        }
    }
};
