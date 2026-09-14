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
            ->where('system_key', 'core.dashboard')
            ->update([
                'module_code' => 'payroll',
                'updated_at' => $now,
            ]);

        DB::table('menus')
            ->where('system_key', 'core.reports')
            ->update([
                'module_code' => 'payroll',
                'route' => '/payroll/reports',
                'updated_at' => $now,
            ]);

        // Re-prefix any leftover /core/reports* routes.
        $menus = DB::table('menus')
            ->where('route', '/core/reports')
            ->orWhere('route', 'like', '/core/reports/%')
            ->get(['id', 'route']);

        foreach ($menus as $menu) {
            if (! is_string($menu->route) || $menu->route === '') {
                continue;
            }

            $next = '/payroll/reports'.substr($menu->route, strlen('/core/reports'));
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
            ->where('system_key', 'core.dashboard')
            ->update([
                'module_code' => 'core',
                'updated_at' => $now,
            ]);

        DB::table('menus')
            ->where('system_key', 'core.reports')
            ->update([
                'module_code' => 'core',
                'route' => '/core/reports',
                'updated_at' => $now,
            ]);

        $menus = DB::table('menus')
            ->where('route', '/payroll/reports')
            ->orWhere('route', 'like', '/payroll/reports/%')
            ->get(['id', 'route']);

        foreach ($menus as $menu) {
            if (! is_string($menu->route) || $menu->route === '') {
                continue;
            }

            $next = '/core/reports'.substr($menu->route, strlen('/payroll/reports'));
            DB::table('menus')->where('id', $menu->id)->update([
                'route' => $next,
                'module_code' => 'core',
                'updated_at' => $now,
            ]);
        }
    }
};
