<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        $replacements = [
            '/timesheet' => '/payroll/timesheet',
            '/scheduler' => '/payroll/scheduler',
            '/employees' => '/payroll/employees',
            '/leaves' => '/payroll/leaves',
            '/reports' => '/payroll/reports',
            '/settings' => '/payroll/settings',
        ];

        foreach ($replacements as $from => $to) {
            DB::table('menus')->where('route', $from)->update(['route' => $to]);

            DB::table('menus')
                ->where('route', 'like', $from.'/%')
                ->orderBy('id')
                ->chunkById(100, function ($menus) use ($from, $to) {
                    foreach ($menus as $menu) {
                        DB::table('menus')
                            ->where('id', $menu->id)
                            ->update([
                                'route' => preg_replace('#^'.preg_quote($from, '#').'#', $to, $menu->route),
                            ]);
                    }
                }, 'id');
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')
                ->where('code', 'hr')
                ->update(['default_route' => '/payroll/employees', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        $replacements = [
            '/payroll/timesheet' => '/timesheet',
            '/payroll/scheduler' => '/scheduler',
            '/payroll/employees' => '/employees',
            '/payroll/leaves' => '/leaves',
            '/payroll/reports' => '/reports',
            '/payroll/settings' => '/settings',
        ];

        foreach ($replacements as $from => $to) {
            DB::table('menus')->where('route', $from)->update(['route' => $to]);

            DB::table('menus')
                ->where('route', 'like', $from.'/%')
                ->orderBy('id')
                ->chunkById(100, function ($menus) use ($from, $to) {
                    foreach ($menus as $menu) {
                        DB::table('menus')
                            ->where('id', $menu->id)
                            ->update([
                                'route' => preg_replace('#^'.preg_quote($from, '#').'#', $to, $menu->route),
                            ]);
                    }
                }, 'id');
        }
    }
};
