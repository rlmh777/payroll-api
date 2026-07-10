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
        Permission::firstOrCreate(['name' => 'view-department-heads']);
        Permission::firstOrCreate(['name' => 'department-head-crud']);

        $role = Role::query()->where('name', 'super-admin')->first();
        if ($role) {
            $role->givePermissionTo(['view-department-heads', 'department-head-crud']);
        }

        if (!Schema::hasTable('menus')) {
            return;
        }

        $exists = DB::table('menus')->where('route', '/settings/department-heads')->exists();
        if ($exists) {
            return;
        }

        $generalMenuId = DB::table('menus')
            ->where('route', '/settings')
            ->where('type', 'submenu')
            ->where('title', 'General')
            ->value('id');

        if (!$generalMenuId) {
            return;
        }

        $maxOrder = (int) DB::table('menus')
            ->where('parent_id', $generalMenuId)
            ->max('order');

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $generalMenuId,
            'title' => 'Department Heads',
            'route' => '/settings/department-heads',
            'icon' => 'supervisor_account',
            'permission' => 'view-department-heads',
            'order' => $maxOrder + 1,
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

        DB::table('menus')->where('route', '/settings/department-heads')->delete();
    }
};
