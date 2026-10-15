<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        $now = now();

        // Payroll settings was historically keyed as admin.settings.
        DB::table('menus')
            ->where('system_key', 'admin.settings')
            ->where('route', '/payroll/settings')
            ->update([
                'system_key' => 'payroll.settings',
                'module_code' => 'payroll',
                'updated_at' => $now,
            ]);

        DB::table('menus')
            ->where('system_key', 'admin.modules')
            ->update([
                'module_code' => 'payroll',
                'updated_at' => $now,
            ]);

        $adminSettingsId = DB::table('menus')
            ->where('system_key', 'admin.settings')
            ->value('id');

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
        } else {
            DB::table('menus')->where('id', $adminSettingsId)->update([
                'title' => 'Settings',
                'route' => '/admin/settings',
                'icon' => 'settings',
                'permission' => 'view-settings',
                'order' => 1,
                'is_active' => true,
                'type' => 'menu',
                'module_code' => 'admin',
                'source' => 'system',
                'parent_id' => null,
                'updated_at' => $now,
            ]);
        }

        // Move pipelines under Administration Settings.
        $pipeline = DB::table('menus')
            ->where('system_key', 'settings.pipelines')
            ->orWhere('system_key', 'admin.pipelines')
            ->orWhere('route', '/payroll/settings/pipelines')
            ->orWhere('route', '/admin/settings/pipelines')
            ->first();

        if ($pipeline) {
            DB::table('menus')->where('id', $pipeline->id)->update([
                'parent_id' => $adminSettingsId,
                'title' => 'Pipelines',
                'route' => '/admin/settings/pipelines',
                'icon' => 'account_tree',
                'permission' => 'view-pipeline-templates',
                'order' => 1,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
                'system_key' => 'admin.pipelines',
                'updated_at' => $now,
            ]);
        } else {
            DB::table('menus')->insert([
                'id' => (string) Str::uuid(),
                'parent_id' => $adminSettingsId,
                'title' => 'Pipelines',
                'route' => '/admin/settings/pipelines',
                'icon' => 'account_tree',
                'permission' => 'view-pipeline-templates',
                'order' => 1,
                'is_active' => true,
                'type' => 'submenu',
                'module_code' => 'admin',
                'source' => 'system',
                'system_key' => 'admin.pipelines',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Prefix HR employee menus with /hr.
        $this->reprefixRoutes('/payroll/employees', '/hr/employees');

        // Prefix Core reports with /core.
        $this->reprefixRoutes('/payroll/reports', '/core/reports');

        // Ensure employee/report roots keep correct module codes.
        DB::table('menus')->where('system_key', 'hr.employees')->update(['module_code' => 'hr']);
        DB::table('menus')->where('system_key', 'core.reports')->update(['module_code' => 'core']);

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('code', 'hr')->update([
                'default_route' => '/hr/employees',
                'updated_at' => $now,
            ]);
            DB::table('modules')->where('code', 'admin')->update([
                'default_route' => '/admin/settings',
                'updated_at' => $now,
            ]);
            DB::table('modules')->where('code', 'core')->update([
                'default_route' => '/',
                'updated_at' => $now,
            ]);
        }
    }

    private function reprefixRoutes(string $fromPrefix, string $toPrefix): void
    {
        $menus = DB::table('menus')
            ->where('route', $fromPrefix)
            ->orWhere('route', 'like', $fromPrefix.'/%')
            ->get(['id', 'route']);

        foreach ($menus as $menu) {
            if (! is_string($menu->route) || $menu->route === '') {
                continue;
            }

            $next = $toPrefix.substr($menu->route, strlen($fromPrefix));
            DB::table('menus')->where('id', $menu->id)->update([
                'route' => $next,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        $now = now();
        $payrollSettingsId = DB::table('menus')->where('system_key', 'payroll.settings')->value('id');
        $generalId = DB::table('menus')
            ->where('parent_id', $payrollSettingsId)
            ->where('title', 'General')
            ->value('id');

        DB::table('menus')
            ->where('system_key', 'admin.pipelines')
            ->update([
                'parent_id' => $generalId,
                'route' => '/payroll/settings/pipelines',
                'system_key' => 'settings.pipelines',
                'module_code' => 'payroll',
                'updated_at' => $now,
            ]);

        DB::table('menus')->where('system_key', 'admin.settings')->delete();

        DB::table('menus')
            ->where('system_key', 'payroll.settings')
            ->update([
                'system_key' => 'admin.settings',
                'updated_at' => $now,
            ]);

        $this->reprefixRoutes('/hr/employees', '/payroll/employees');
        $this->reprefixRoutes('/core/reports', '/payroll/reports');
    }
};
