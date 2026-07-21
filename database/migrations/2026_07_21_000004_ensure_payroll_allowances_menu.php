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
        if (Schema::hasTable('permissions')) {
            foreach (['view-payroll-allowances', 'payroll-allowances-crud'] as $permissionName) {
                Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
            }

            // Grant to any role that already manages payroll or employees.
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
                ->each(fn (Role $role) => $role->givePermissionTo([
                    'view-payroll-allowances',
                    'payroll-allowances-crud',
                ]));
        }

        if (! Schema::hasTable('menus')) {
            return;
        }

        $payrollMenuId = DB::table('menus')
            ->where('route', '/payroll')
            ->where('type', 'menu')
            ->value('id');

        if (! $payrollMenuId) {
            return;
        }

        $existing = DB::table('menus')->where('route', '/payroll/allowances')->first();
        $payload = [
            'parent_id' => $payrollMenuId,
            'title' => 'Allowances',
            'icon' => 'fas fa-hand-holding-usd',
            'permission' => 'view-payroll-allowances',
            'order' => 4,
            'type' => 'submenu',
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('menus')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('menus')->insert([
                'id' => (string) Str::uuid(),
                ...$payload,
                'route' => '/payroll/allowances',
                'created_at' => now(),
            ]);
        }

        DB::table('menus')
            ->where('parent_id', $payrollMenuId)
            ->where('route', '/payroll/generate-payslip')
            ->update(['order' => 5]);

        DB::table('menus')
            ->where('parent_id', $payrollMenuId)
            ->where('route', '/payroll/taxes-filing')
            ->update(['order' => 6]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')->where('route', '/payroll/allowances')->delete();

        $payrollMenuId = DB::table('menus')
            ->where('route', '/payroll')
            ->where('type', 'menu')
            ->value('id');

        if (! $payrollMenuId) {
            return;
        }

        DB::table('menus')
            ->where('parent_id', $payrollMenuId)
            ->where('route', '/payroll/generate-payslip')
            ->update(['order' => 4]);

        DB::table('menus')
            ->where('parent_id', $payrollMenuId)
            ->where('route', '/payroll/taxes-filing')
            ->update(['order' => 5]);
    }
};
