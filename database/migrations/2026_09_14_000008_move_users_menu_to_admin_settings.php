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

        $users = DB::table('menus')
            ->where('system_key', 'admin.users')
            ->orWhere('route', '/payroll/settings/users')
            ->orWhere('route', '/admin/settings/users')
            ->orWhere('route', '/settings/users')
            ->orWhere(function ($query) {
                $query->where('title', 'Users')
                    ->where('permission', 'manager-users');
            })
            ->first();

        if ($users) {
            DB::table('menus')->where('id', $users->id)->update([
                'parent_id' => $adminSettingsId,
                'title' => 'Users',
                'route' => '/admin/settings/users',
                'icon' => 'fa-solid fa-users',
                'permission' => 'manager-users',
                'order' => 7,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
                'system_key' => 'admin.users',
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $adminSettingsId,
            'title' => 'Users',
            'route' => '/admin/settings/users',
            'icon' => 'fa-solid fa-users',
            'permission' => 'manager-users',
            'order' => 7,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'admin',
            'source' => 'system',
            'system_key' => 'admin.users',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $payrollSettingsId = DB::table('menus')->where('system_key', 'payroll.settings')->value('id');

        DB::table('menus')
            ->where('system_key', 'admin.users')
            ->orWhere('route', '/admin/settings/users')
            ->update([
                'parent_id' => $payrollSettingsId,
                'route' => '/payroll/settings/users',
                'module_code' => 'payroll',
                'order' => 12,
                'updated_at' => now(),
            ]);
    }
};
