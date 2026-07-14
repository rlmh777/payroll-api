<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\Permission;
use App\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        $permissionNames = [
            'view-leave',
            'view-leave-types',
            'leave-crud',
        ];

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        $role = Role::query()->where('name', 'super-admin')->first();
        if ($role) {
            $role->givePermissionTo($permissionNames);
        }

        $existingLeavesId = DB::table('menus')
            ->where('route', '/leaves')
            ->where('type', 'menu')
            ->value('id');

        if ($existingLeavesId) {
            $this->ensureSubmenus((string) $existingLeavesId);

            return;
        }

        DB::table('menus')
            ->whereNull('parent_id')
            ->where('type', 'menu')
            ->where('route', '/payroll')
            ->update(['order' => 7, 'updated_at' => now()]);

        DB::table('menus')
            ->whereNull('parent_id')
            ->where('type', 'menu')
            ->where('route', '/reports')
            ->update(['order' => 8, 'updated_at' => now()]);

        $leavesId = (string) Str::uuid();

        DB::table('menus')->insert([
            'id' => $leavesId,
            'parent_id' => null,
            'title' => 'Leaves',
            'route' => '/leaves',
            'icon' => 'fas fa-calendar-check',
            'permission' => 'view-leave',
            'order' => 6,
            'type' => 'menu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ensureSubmenus($leavesId);
    }

    public function down(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        $leavesId = DB::table('menus')
            ->where('route', '/leaves')
            ->where('type', 'menu')
            ->value('id');

        if ($leavesId) {
            DB::table('menus')->where('parent_id', $leavesId)->delete();
            DB::table('menus')->where('id', $leavesId)->delete();
        }

        DB::table('menus')
            ->whereNull('parent_id')
            ->where('type', 'menu')
            ->where('route', '/payroll')
            ->update(['order' => 6, 'updated_at' => now()]);

        DB::table('menus')
            ->whereNull('parent_id')
            ->where('type', 'menu')
            ->where('route', '/reports')
            ->update(['order' => 7, 'updated_at' => now()]);
    }

    private function ensureSubmenus(string $leavesId): void
    {
        $submenus = [
            [
                'title' => 'Leave List',
                'route' => '/leaves/list',
                'icon' => 'list_alt',
                'permission' => 'view-leave',
                'order' => 1,
            ],
            [
                'title' => 'Assign Leave',
                'route' => '/leaves/assign',
                'icon' => 'event_available',
                'permission' => 'leave-crud',
                'order' => 2,
            ],
            [
                'title' => 'My Leave Usage',
                'route' => '/leaves/my-usage',
                'icon' => 'pie_chart',
                'permission' => 'view-leave',
                'order' => 3,
            ],
            [
                'title' => 'Leave Calendar',
                'route' => '/leaves/calendar',
                'icon' => 'calendar_month',
                'permission' => 'view-leave',
                'order' => 4,
            ],
            [
                'title' => 'Leave Entitlement',
                'route' => '/leaves/entitlement',
                'icon' => 'card_membership',
                'permission' => 'view-leave',
                'order' => 5,
            ],
            [
                'title' => 'Leave Types',
                'route' => '/leaves/types',
                'icon' => 'event_busy',
                'permission' => 'view-leave-types',
                'order' => 6,
            ],
        ];

        foreach ($submenus as $submenu) {
            $exists = DB::table('menus')->where('route', $submenu['route'])->exists();
            if ($exists) {
                DB::table('menus')
                    ->where('route', $submenu['route'])
                    ->update([
                        'parent_id' => $leavesId,
                        'title' => $submenu['title'],
                        'icon' => $submenu['icon'],
                        'permission' => $submenu['permission'],
                        'order' => $submenu['order'],
                        'type' => 'submenu',
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('menus')->insert([
                'id' => (string) Str::uuid(),
                'parent_id' => $leavesId,
                'title' => $submenu['title'],
                'route' => $submenu['route'],
                'icon' => $submenu['icon'],
                'permission' => $submenu['permission'],
                'order' => $submenu['order'],
                'type' => 'submenu',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
