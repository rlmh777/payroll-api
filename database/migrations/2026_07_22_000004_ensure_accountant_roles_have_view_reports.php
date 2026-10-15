<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $viewReports = Permission::firstOrCreate([
            'name' => 'view-reports',
            'guard_name' => 'web',
        ]);

        foreach (['accountant', 'payroll-accountant'] as $roleName) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);
            $role->givePermissionTo($viewReports);
        }

        $admin = Role::query()->where('name', 'admin')->first();
        if ($admin) {
            $admin->givePermissionTo($viewReports);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep roles; they may already be assigned to users.
    }
};
