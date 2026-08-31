<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'view-employee-groups', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'employee-groups-crud', 'guard_name' => 'web']);

        $adminRole = Role::query()->where('name', 'admin')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo(['view-employee-groups', 'employee-groups-crud']);
        }

        Role::query()->each(function (Role $role) use ($adminRole) {
            if ($adminRole && $role->id === $adminRole->id) {
                return;
            }

            if ($role->hasPermissionTo('view-calendars')) {
                $role->givePermissionTo('view-employee-groups');
            }
        });

        if (! Schema::hasTable('menus')) {
            return;
        }

        if (DB::table('menus')->where('route', '/settings/employee-groups')->exists()) {
            DB::table('menus')
                ->where('route', '/settings/employee-groups')
                ->update([
                    'permission' => 'view-employee-groups',
                    'updated_at' => now(),
                ]);

            return;
        }

        $generalMenuId = DB::table('menus')
            ->where('route', '/settings')
            ->where('title', 'General')
            ->value('id');

        if (! $generalMenuId) {
            $generalMenuId = DB::table('menus')
                ->where('title', 'General')
                ->where('type', 'menu')
                ->value('id');
        }

        if (! $generalMenuId) {
            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $generalMenuId,
            'title' => 'Employee Groups',
            'route' => '/settings/employee-groups',
            'icon' => 'groups',
            'permission' => 'view-employee-groups',
            'order' => 15,
            'type' => 'submenu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')->where('route', '/settings/employee-groups')->delete();
    }
};
