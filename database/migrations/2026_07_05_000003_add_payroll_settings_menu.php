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

        $exists = DB::table('menus')->where('route', '/settings/payroll-settings')->exists();

        if ($exists) {
            return;
        }

        $settingsMenuId = DB::table('menus')
            ->where('route', '/settings')
            ->where('type', 'menu')
            ->value('id');

        if (!$settingsMenuId) {
            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $settingsMenuId,
            'title' => 'Payroll Settings',
            'route' => '/settings/payroll-settings',
            'icon' => 'fa-solid fa-percent',
            'permission' => 'manager-tax',
            'order' => 9,
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

        DB::table('menus')->where('route', '/settings/payroll-settings')->delete();
    }
};
