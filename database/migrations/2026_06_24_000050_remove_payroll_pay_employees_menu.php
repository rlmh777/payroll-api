<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')->where('route', '/payroll/pay-employees')->delete();

        $payrollMenuId = DB::table('menus')
            ->where('route', '/payroll')
            ->where('type', 'menu')
            ->value('id');

        if (!$payrollMenuId) {
            return;
        }

        DB::table('menus')
            ->where('parent_id', $payrollMenuId)
            ->where('route', '/payroll/timesheets')
            ->update([
                'title' => 'Payroll Run',
                'route' => '/payroll/payroll-run',
                'icon' => 'fas fa-money-check-alt',
                'permission' => 'view-payroll',
                'updated_at' => now(),
            ]);

        $payrollSubmenus = [
            '/payroll/overview' => 1,
            '/payroll/pay-period' => 2,
            '/payroll/payroll-run' => 3,
            '/payroll/taxes-filing' => 4,
        ];

        foreach ($payrollSubmenus as $route => $order) {
            DB::table('menus')
                ->where('parent_id', $payrollMenuId)
                ->where('route', $route)
                ->update([
                    'order' => $order,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        $payrollMenuId = DB::table('menus')
            ->where('route', '/payroll')
            ->where('type', 'menu')
            ->value('id');

        if (!$payrollMenuId) {
            return;
        }

        DB::table('menus')
            ->where('parent_id', $payrollMenuId)
            ->where('route', '/payroll/payroll-run')
            ->update([
                'title' => 'Timesheets',
                'route' => '/payroll/timesheets',
                'icon' => 'fas fa-clock',
                'permission' => 'view-timesheets',
                'updated_at' => now(),
            ]);

        $exists = DB::table('menus')->where('route', '/payroll/pay-employees')->exists();
        if (!$exists) {
            DB::table('menus')->insert([
                'id' => (string) Str::uuid(),
                'parent_id' => $payrollMenuId,
                'title' => 'Pay Employees',
                'route' => '/payroll/pay-employees',
                'icon' => 'fas fa-money-bill',
                'permission' => 'view-pay-employees',
                'order' => 4,
                'type' => 'submenu',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $payrollSubmenus = [
            '/payroll/overview' => 1,
            '/payroll/pay-period' => 2,
            '/payroll/timesheets' => 3,
            '/payroll/pay-employees' => 4,
            '/payroll/taxes-filing' => 5,
        ];

        foreach ($payrollSubmenus as $route => $order) {
            DB::table('menus')
                ->where('parent_id', $payrollMenuId)
                ->where('route', $route)
                ->update([
                    'order' => $order,
                    'updated_at' => now(),
                ]);
        }
    }
};
