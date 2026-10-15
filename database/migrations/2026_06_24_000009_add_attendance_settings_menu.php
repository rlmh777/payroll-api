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

        $exists = DB::table('menus')->where('route', '/settings/attendance')->exists();
        if ($exists) {
            return;
        }

        $generalMenuId = DB::table('menus')
            ->where('route', '/settings')
            ->where('type', 'submenu')
            ->where('title', 'General')
            ->value('id');

        if (!$generalMenuId) {
            return;
        }

        $maxOrder = (int) DB::table('menus')
            ->where('parent_id', $generalMenuId)
            ->max('order');

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $generalMenuId,
            'title' => 'Attendance',
            'route' => '/settings/attendance',
            'icon' => 'schedule',
            'permission' => 'view-attendance-settings',
            'order' => $maxOrder + 1,
            'type' => 'submenu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')->where('route', '/settings/attendance')->delete();
    }
};
