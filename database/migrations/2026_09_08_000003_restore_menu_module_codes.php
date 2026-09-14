<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menus') || ! Schema::hasColumn('menus', 'module_code')) {
            return;
        }

        $rootMap = [
            'core.dashboard' => 'payroll',
            'core.reports' => 'payroll',
            'hr.employees' => 'payroll',
            'hr.scheduler' => 'payroll',
            'hr.timesheet' => 'payroll',
            'hr.leaves' => 'payroll',
            'payroll.root' => 'payroll',
            'payroll.settings' => 'payroll',
            'admin.settings' => 'admin',
            'admin.modules' => 'payroll',
            'admin.pipelines' => 'admin',
            'settings.pipelines' => 'admin',
        ];

        foreach ($rootMap as $systemKey => $moduleCode) {
            DB::table('menus')
                ->where('system_key', $systemKey)
                ->update(['module_code' => $moduleCode]);
        }

        // Cascade parent module codes to descendants (breadth-first).
        $changed = true;
        while ($changed) {
            $changed = false;
            $parents = DB::table('menus')
                ->whereNotNull('module_code')
                ->get(['id', 'module_code']);

            foreach ($parents as $parent) {
                $updated = DB::table('menus')
                    ->where('parent_id', $parent->id)
                    ->where(function ($query) use ($parent) {
                        $query->whereNull('module_code')
                            ->orWhere('module_code', '!=', $parent->module_code);
                    })
                    ->update(['module_code' => $parent->module_code]);

                if ($updated > 0) {
                    $changed = true;
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus') || ! Schema::hasColumn('menus', 'module_code')) {
            return;
        }

        DB::table('menus')->update(['module_code' => 'payroll']);
    }
};
