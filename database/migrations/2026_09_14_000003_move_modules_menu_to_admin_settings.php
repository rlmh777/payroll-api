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

        $modules = DB::table('menus')
            ->where('system_key', 'admin.modules')
            ->orWhere('route', '/payroll/settings/modules')
            ->orWhere('route', '/admin/settings/modules')
            ->orWhere(function ($query) {
                $query->where('title', 'Modules')
                    ->where('permission', 'manage-modules');
            })
            ->first();

        if ($modules) {
            DB::table('menus')->where('id', $modules->id)->update([
                'parent_id' => $adminSettingsId,
                'title' => 'Modules',
                'route' => '/admin/settings/modules',
                'icon' => 'apps',
                'permission' => 'manage-modules',
                'order' => 2,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
                'system_key' => 'admin.modules',
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $adminSettingsId,
            'title' => 'Modules',
            'route' => '/admin/settings/modules',
            'icon' => 'apps',
            'permission' => 'manage-modules',
            'order' => 2,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'admin',
            'source' => 'system',
            'system_key' => 'admin.modules',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $payrollSettingsId = DB::table('menus')->where('system_key', 'payroll.settings')->value('id');

        DB::table('menus')
            ->where('system_key', 'admin.modules')
            ->orWhere('route', '/admin/settings/modules')
            ->update([
                'parent_id' => $payrollSettingsId,
                'route' => '/payroll/settings/modules',
                'module_code' => 'payroll',
                'order' => 11,
                'updated_at' => now(),
            ]);
    }
};
