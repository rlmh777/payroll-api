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
        if (Schema::hasTable('permissions')) {
            foreach (['view-pool-distribution-types', 'pool-distribution-type-crud'] as $permissionName) {
                Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
            }

            Role::query()
                ->where(function ($query) {
                    $query->whereIn('name', ['admin', 'super-admin', 'hr-admin', 'payroll-admin'])
                        ->orWhereHas('permissions', function ($permissionQuery) {
                            $permissionQuery->whereIn('name', [
                                'view-payroll-earning-codes',
                                'view-general',
                                'view-settings',
                            ]);
                        });
                })
                ->get()
                ->each(fn (Role $role) => $role->givePermissionTo([
                    'view-pool-distribution-types',
                    'pool-distribution-type-crud',
                ]));

            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        }

        if (! Schema::hasTable('menus')) {
            return;
        }

        $parentId = DB::table('menus')
            ->where('route', '/settings/payroll-earning-codes')
            ->value('parent_id')
            ?: DB::table('menus')
                ->where('title', 'General')
                ->where('type', 'submenu')
                ->value('id')
            ?: DB::table('menus')
                ->where('route', '/settings')
                ->where('type', 'menu')
                ->value('id');

        if (! $parentId) {
            return;
        }

        $existing = DB::table('menus')->where('route', '/settings/pool-distribution-types')->first();
        $payload = [
            'parent_id' => $parentId,
            'title' => 'Pool Distribution',
            'icon' => 'fas fa-chart-pie',
            'permission' => 'view-pool-distribution-types',
            'order' => 9,
            'type' => 'submenu',
            'is_active' => true,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('menus')->where('id', $existing->id)->update($payload);

            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            ...$payload,
            'route' => '/settings/pool-distribution-types',
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')->where('route', '/settings/pool-distribution-types')->delete();
        }
    }
};
