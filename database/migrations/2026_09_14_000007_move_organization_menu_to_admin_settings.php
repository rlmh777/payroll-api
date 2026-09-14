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

        $organization = DB::table('menus')
            ->where('system_key', 'admin.organization')
            ->orWhere('route', '/payroll/settings/organization')
            ->orWhere('route', '/admin/settings/organization')
            ->orWhere('route', '/settings/organization')
            ->orWhere(function ($query) {
                $query->where('title', 'Organization')
                    ->where('permission', 'view-organization');
            })
            ->first();

        if ($organization) {
            DB::table('menus')->where('id', $organization->id)->update([
                'parent_id' => $adminSettingsId,
                'title' => 'Organization',
                'route' => '/admin/settings/organization',
                'icon' => 'fas fa-building',
                'permission' => 'view-organization',
                'order' => 6,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
                'system_key' => 'admin.organization',
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $adminSettingsId,
            'title' => 'Organization',
            'route' => '/admin/settings/organization',
            'icon' => 'fas fa-building',
            'permission' => 'view-organization',
            'order' => 6,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'admin',
            'source' => 'system',
            'system_key' => 'admin.organization',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $payrollSettingsId = DB::table('menus')->where('system_key', 'payroll.settings')->value('id');

        DB::table('menus')
            ->where('system_key', 'admin.organization')
            ->orWhere('route', '/admin/settings/organization')
            ->update([
                'parent_id' => $payrollSettingsId,
                'route' => '/payroll/settings/organization',
                'module_code' => 'payroll',
                'order' => 2,
                'updated_at' => now(),
            ]);
    }
};
