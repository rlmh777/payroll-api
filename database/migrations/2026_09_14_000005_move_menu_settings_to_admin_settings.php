<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $adminSettingsId = DB::table('menus')->where('system_key', 'admin.settings')->value('id');
        if (! $adminSettingsId) {
            $adminSettingsId = (string) Str::uuid();
            DB::table('menus')->insert([
                'id' => $adminSettingsId,
                'parent_id' => null,
                'title' => 'Settings',
                'route' => '/admin/settings',
                'icon' => 'settings',
                'permission' => 'view-settings',
                'order' => 1,
                'is_active' => true,
                'type' => 'menu',
                'module_code' => 'admin',
                'source' => 'system',
                'system_key' => 'admin.settings',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $menu = DB::table('menus')
            ->where('system_key', 'admin.menu')
            ->orWhere('route', '/payroll/settings/menu')
            ->orWhere('route', '/admin/settings/menu')
            ->orWhere('route', '/settings/menu')
            ->orWhere(function ($query) {
                $query->where('title', 'Menu')
                    ->where('permission', 'view-menu');
            })
            ->first();

        if ($menu) {
            DB::table('menus')->where('id', $menu->id)->update([
                'parent_id' => $adminSettingsId,
                'title' => 'Menu',
                'route' => '/admin/settings/menu',
                'icon' => 'fas fa-bars',
                'permission' => 'view-menu',
                'order' => 4,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
                'system_key' => 'admin.menu',
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $adminSettingsId,
            'title' => 'Menu',
            'route' => '/admin/settings/menu',
            'icon' => 'fas fa-bars',
            'permission' => 'view-menu',
            'order' => 4,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'admin',
            'source' => 'system',
            'system_key' => 'admin.menu',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $payrollSettingsId = DB::table('menus')->where('system_key', 'payroll.settings')->value('id');

        DB::table('menus')
            ->where('system_key', 'admin.menu')
            ->orWhere('route', '/admin/settings/menu')
            ->update([
                'parent_id' => $payrollSettingsId,
                'route' => '/payroll/settings/menu',
                'module_code' => 'payroll',
                'order' => 10,
                'updated_at' => now(),
            ]);
    }
};
