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

        if (DB::table('menus')->where('route', '/payroll/generate-payslip')->exists()) {
            return;
        }

        $payrollMenuId = DB::table('menus')
            ->where('route', '/payroll')
            ->where('type', 'menu')
            ->value('id');

        if (!$payrollMenuId) {
            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $payrollMenuId,
            'title' => 'Generate Payslip',
            'route' => '/payroll/generate-payslip',
            'icon' => 'fas fa-file-invoice-dollar',
            'permission' => 'view-payroll',
            'order' => 4,
            'type' => 'submenu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('menus')
            ->where('parent_id', $payrollMenuId)
            ->where('route', '/payroll/taxes-filing')
            ->update(['order' => 5]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')->where('route', '/payroll/generate-payslip')->delete();

        $payrollMenuId = DB::table('menus')
            ->where('route', '/payroll')
            ->where('type', 'menu')
            ->value('id');

        if ($payrollMenuId) {
            DB::table('menus')
                ->where('parent_id', $payrollMenuId)
                ->where('route', '/payroll/taxes-filing')
                ->update(['order' => 4]);
        }
    }
};
