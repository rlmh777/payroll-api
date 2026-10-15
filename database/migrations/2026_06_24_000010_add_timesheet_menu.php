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

        $exists = DB::table('menus')->where('route', '/timesheet')->where('type', 'menu')->whereNull('parent_id')->exists();
        if (!$exists) {
            DB::table('menus')->insert([
                'id' => (string) Str::uuid(),
                'parent_id' => null,
                'title' => 'Timesheet',
                'route' => '/timesheet',
                'icon' => 'fas fa-clock',
                'permission' => 'view-timesheets',
                'order' => 5,
                'type' => 'menu',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $topLevelMenus = [
            '/dashboard' => 1,
            '/employees' => 2,
            '/settings' => 3,
            '/scheduler' => 4,
            '/timesheet' => 5,
            '/payroll' => 6,
            '/reports' => 7,
        ];

        foreach ($topLevelMenus as $route => $order) {
            DB::table('menus')
                ->where('route', $route)
                ->where('type', 'menu')
                ->whereNull('parent_id')
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

        DB::table('menus')->where('route', '/timesheet')->delete();

        $topLevelMenus = [
            '/dashboard' => 1,
            '/employees' => 2,
            '/settings' => 3,
            '/scheduler' => 4,
            '/payroll' => 5,
            '/reports' => 6,
        ];

        foreach ($topLevelMenus as $route => $order) {
            DB::table('menus')
                ->where('route', $route)
                ->where('type', 'menu')
                ->whereNull('parent_id')
                ->update([
                    'order' => $order,
                    'updated_at' => now(),
                ]);
        }
    }
};
