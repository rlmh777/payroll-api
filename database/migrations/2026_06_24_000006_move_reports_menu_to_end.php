<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

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

    public function down(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        $topLevelMenus = [
            '/dashboard' => 1,
            '/employees' => 2,
            '/reports' => 3,
            '/settings' => 5,
            '/scheduler' => 6,
            '/payroll' => 7,
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
