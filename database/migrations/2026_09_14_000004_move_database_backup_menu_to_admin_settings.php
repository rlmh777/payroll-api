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

        $backup = DB::table('menus')
            ->where('system_key', 'admin.database_backup')
            ->orWhere('route', '/payroll/settings/database-backup')
            ->orWhere('route', '/admin/settings/database-backup')
            ->orWhere('route', '/settings/database-backup')
            ->orWhere(function ($query) {
                $query->where('title', 'Database Backup')
                    ->where('permission', 'view-database-backup');
            })
            ->first();

        if ($backup) {
            DB::table('menus')->where('id', $backup->id)->update([
                'parent_id' => $adminSettingsId,
                'title' => 'Database Backup',
                'route' => '/admin/settings/database-backup',
                'icon' => 'fas fa-database',
                'permission' => 'view-database-backup',
                'order' => 3,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
                'system_key' => 'admin.database_backup',
                'is_active' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $adminSettingsId,
            'title' => 'Database Backup',
            'route' => '/admin/settings/database-backup',
            'icon' => 'fas fa-database',
            'permission' => 'view-database-backup',
            'order' => 3,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'admin',
            'source' => 'system',
            'system_key' => 'admin.database_backup',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $payrollSettingsId = DB::table('menus')->where('system_key', 'payroll.settings')->value('id');

        DB::table('menus')
            ->where('system_key', 'admin.database_backup')
            ->orWhere('route', '/admin/settings/database-backup')
            ->update([
                'parent_id' => $payrollSettingsId,
                'route' => '/payroll/settings/database-backup',
                'module_code' => 'payroll',
                'order' => 20,
                'updated_at' => now(),
            ]);
    }
};
