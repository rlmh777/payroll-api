<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'view-scheduler-metrics',
        'scheduler-metrics-crud',
        'scheduler-daily-metrics-crud',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['admin', 'gm'] as $roleName) {
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
            );

            $role->givePermissionTo(self::PERMISSIONS);
        }

        if (! Schema::hasTable('menus')) {
            return;
        }

        $now = now();
        $generalParentId = DB::table('menus')
            ->where('route', '/payroll/settings')
            ->where('title', 'General')
            ->value('id');

        if (! $generalParentId) {
            $generalParentId = DB::table('menus')
                ->where('title', 'General')
                ->whereIn('type', ['menu', 'submenu'])
                ->value('id');
        }

        if (! $generalParentId) {
            return;
        }

        if (DB::table('menus')->where('route', '/payroll/settings/scheduler-metrics')->exists()) {
            return;
        }

        $maxOrder = (int) DB::table('menus')->where('parent_id', $generalParentId)->max('order');

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $generalParentId,
            'title' => 'Scheduler Metrics',
            'route' => '/payroll/settings/scheduler-metrics',
            'icon' => 'analytics',
            'permission' => 'view-scheduler-metrics',
            'order' => $maxOrder + 1,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'payroll',
            'source' => 'system',
            'system_key' => 'settings.scheduler-metrics',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')->where('route', '/payroll/settings/scheduler-metrics')->delete();
        }

        foreach (Role::query()->where('guard_name', 'web')->get() as $role) {
            foreach (self::PERMISSIONS as $name) {
                if ($role->hasPermissionTo($name)) {
                    $role->revokePermissionTo($name);
                }
            }
        }

        Permission::query()->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
    }
};
