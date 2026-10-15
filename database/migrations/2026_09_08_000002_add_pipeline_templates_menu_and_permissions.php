<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'view-pipeline-templates', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'pipeline-templates-crud', 'guard_name' => 'web']);

        $admin = Role::query()->where('name', 'admin')->first();
        if ($admin) {
            $admin->givePermissionTo(['view-pipeline-templates', 'pipeline-templates-crud']);
        }

        if (! Schema::hasTable('menus')) {
            return;
        }

        $now = now();
        $generalParentId = DB::table('menus')
            ->where('title', 'General')
            ->where('type', 'menu')
            ->value('id');

        if (! $generalParentId) {
            $generalParentId = DB::table('menus')
                ->where('route', '/payroll/settings')
                ->where('title', 'General')
                ->value('id');
        }

        if (! $generalParentId) {
            return;
        }

        if (DB::table('menus')->where('route', '/payroll/settings/pipelines')->exists()) {
            return;
        }

        $maxOrder = (int) DB::table('menus')->where('parent_id', $generalParentId)->max('order');

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $generalParentId,
            'title' => 'Pipelines',
            'route' => '/payroll/settings/pipelines',
            'icon' => 'account_tree',
            'permission' => 'view-pipeline-templates',
            'order' => $maxOrder + 1,
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'payroll',
            'source' => 'system',
            'system_key' => 'settings.pipelines',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')->where('route', '/payroll/settings/pipelines')->delete();
        }

        Permission::query()->whereIn('name', [
            'view-pipeline-templates',
            'pipeline-templates-crud',
        ])->delete();
    }
};
