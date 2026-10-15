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

        Permission::firstOrCreate([
            'name' => 'list-reports',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->where(function ($query) {
                $query
                    ->whereIn('name', ['admin', 'accountant', 'payroll-accountant'])
                    ->orWhereHas('permissions', function ($permissionQuery) {
                        $permissionQuery->whereIn('name', [
                            'manager-tax',
                            'view-taxes',
                            'pay-employees-crud',
                            'view-payroll',
                        ]);
                    });
            })
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($viewReports));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep report access assigned; accountants may rely on it.
    }
};
