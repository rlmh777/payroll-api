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

        $now = now();

        DB::table('menus')
            ->where('system_key', 'hr.employees')
            ->update([
                'module_code' => 'payroll',
                'route' => '/payroll/employees',
                'updated_at' => $now,
            ]);

        $menus = DB::table('menus')
            ->where('route', '/hr/employees')
            ->orWhere('route', 'like', '/hr/employees/%')
            ->get(['id', 'route']);

        foreach ($menus as $menu) {
            if (! is_string($menu->route) || $menu->route === '') {
                continue;
            }

            $next = '/payroll/employees'.substr($menu->route, strlen('/hr/employees'));
            DB::table('menus')->where('id', $menu->id)->update([
                'route' => $next,
                'module_code' => 'payroll',
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'payroll')->update([
                'default_route' => '/payroll/overview',
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus') || ! Schema::hasColumn('menus', 'module_code')) {
            return;
        }

        $now = now();

        DB::table('menus')
            ->where('system_key', 'hr.employees')
            ->update([
                'module_code' => 'hr',
                'route' => '/hr/employees',
                'updated_at' => $now,
            ]);

        $menus = DB::table('menus')
            ->where('route', '/payroll/employees')
            ->orWhere('route', 'like', '/payroll/employees/%')
            ->get(['id', 'route']);

        foreach ($menus as $menu) {
            if (! is_string($menu->route) || $menu->route === '') {
                continue;
            }

            $next = '/hr/employees'.substr($menu->route, strlen('/payroll/employees'));
            DB::table('menus')->where('id', $menu->id)->update([
                'route' => $next,
                'module_code' => 'hr',
                'updated_at' => $now,
            ]);
        }
    }
};
