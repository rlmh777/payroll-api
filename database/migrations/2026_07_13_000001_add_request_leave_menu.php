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
        if (!Schema::hasTable('menus')) {
            return;
        }

        Permission::firstOrCreate(['name' => 'view-leave']);

        foreach (['super-admin', 'supervisor', 'employee'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo('view-leave');
            }
        }

        $leavesId = DB::table('menus')
            ->where('route', '/leaves')
            ->where('type', 'menu')
            ->value('id');

        if (!$leavesId) {
            return;
        }

        $orders = [
            '/leaves/list' => 1,
            '/leaves/assign' => 2,
            '/leaves/request' => 3,
            '/leaves/my-usage' => 4,
            '/leaves/calendar' => 5,
            '/leaves/entitlement' => 6,
            '/leaves/types' => 7,
        ];

        foreach ($orders as $route => $order) {
            DB::table('menus')
                ->where('route', $route)
                ->update(['order' => $order, 'updated_at' => now()]);
        }

        $exists = DB::table('menus')->where('route', '/leaves/request')->exists();
        if ($exists) {
            DB::table('menus')
                ->where('route', '/leaves/request')
                ->update([
                    'parent_id' => $leavesId,
                    'title' => 'Request Leave',
                    'icon' => 'add_task',
                    'permission' => 'view-leave',
                    'order' => 3,
                    'type' => 'submenu',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $leavesId,
            'title' => 'Request Leave',
            'route' => '/leaves/request',
            'icon' => 'add_task',
            'permission' => 'view-leave',
            'order' => 3,
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

        DB::table('menus')->where('route', '/leaves/request')->delete();

        $orders = [
            '/leaves/list' => 1,
            '/leaves/assign' => 2,
            '/leaves/my-usage' => 3,
            '/leaves/calendar' => 4,
            '/leaves/entitlement' => 5,
            '/leaves/types' => 6,
        ];

        foreach ($orders as $route => $order) {
            DB::table('menus')
                ->where('route', $route)
                ->update(['order' => $order, 'updated_at' => now()]);
        }
    }
};
