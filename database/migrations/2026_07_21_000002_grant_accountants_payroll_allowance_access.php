<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grant payroll-allowance permissions to any role that already has related
     * payroll or employee management permissions (no hard-coded role names).
     */
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $permissions = collect([
            'view-payroll-allowances',
            'payroll-allowances-crud',
        ])->map(fn (string $name) => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]));

        Role::query()
            ->whereHas('permissions', function ($query) {
                $query->whereIn('name', [
                    'pay-employees-crud',
                    'employees-crud',
                    'view-payroll',
                    'timesheets-crud',
                    'view-timesheets',
                ]);
            })
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));
    }

    public function down(): void
    {
        // Keep permissions assigned because they may be in active use.
    }
};
