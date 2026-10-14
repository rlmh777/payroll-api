<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'employee-leave-crud',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereIn('name', ['employee', 'supervisor', 'admin', 'super-admin', 'hr-admin', 'hr'])
            ->get()
            ->each(function (Role $role) use ($permission) {
                $role->givePermissionTo($permission);
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::query()
            ->where('name', 'employee-leave-crud')
            ->where('guard_name', 'web')
            ->first();

        if ($permission) {
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
