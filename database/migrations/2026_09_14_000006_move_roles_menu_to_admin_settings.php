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

        $roles = DB::table('menus')
            ->where('system_key', 'admin.roles')
            ->orWhere('route', '/payroll/settings/roles')
            ->orWhere('route', '/admin/settings/roles')
            ->orWhere('route', '/settings/roles')
            ->orWhere(function ($query) {
                $query->where('title', 'Roles')
                    ->where('permission', 'view-roles');
            })
            ->first();

        if ($roles) {
            DB::table('menus')->where('id', $roles->id)->update([
                'parent_id' => $adminSettingsId,
                'title' => 'Roles',
                'route' => '/admin/settings/roles',
                'icon' => 'fas fa-user-shield',
                'permission' => 'view-roles',
                'order' => 5,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
                'system_key' => 'admin.roles',
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $adminSettingsId,
            'title' => 'Roles',
            'route' => '/admin/settings/roles',
            'icon' => 'fas fa-user-shield',
            'permission' => 'view-roles',
            'order' => 5,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'admin',
            'source' => 'system',
            'system_key' => 'admin.roles',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $payrollSettingsId = DB::table('menus')->where('system_key', 'payroll.settings')->value('id');

        DB::table('menus')
            ->where('system_key', 'admin.roles')
            ->orWhere('route', '/admin/settings/roles')
            ->update([
                'parent_id' => $payrollSettingsId,
                'route' => '/payroll/settings/roles',
                'module_code' => 'payroll',
                'order' => 9,
                'updated_at' => now(),
            ]);
    }
};
