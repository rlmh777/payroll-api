<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        DB::table('menus')
            ->where('route', '/settings/employee-groups')
            ->update([
                'permission' => 'view-employee-groups',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')
            ->where('route', '/settings/employee-groups')
            ->update([
                'permission' => 'view-calendars',
                'updated_at' => now(),
            ]);
    }
};
