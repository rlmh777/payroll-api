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

        DB::table('menus')->where('route', '/payroll/clocking-import')->delete();

        $payrollMenuId = DB::table('menus')
            ->where('route', '/payroll')
            ->where('type', 'menu')
            ->value('id');

        if (!$payrollMenuId) {
            return;
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

        $exists = DB::table('menus')->where('route', '/payroll/clocking-import')->exists();
        if (!$exists) {
            DB::table('menus')->insert([
                'id' => (string) Str::uuid(),
                'parent_id' => $payrollMenuId,
                'title' => 'Clocking Import',
                'route' => '/payroll/clocking-import',
                'icon' => 'fas fa-file-import',
                'permission' => 'import-clocking-logs',
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
            '/payroll/clocking-import' => 4,
            '/payroll/pay-employees' => 5,
            '/payroll/taxes-filing' => 6,
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
