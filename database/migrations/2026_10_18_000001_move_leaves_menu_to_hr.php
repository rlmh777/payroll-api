<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')
                ->where('route', 'like', '/payroll/leaves%')
                ->orderBy('id')
                ->get(['id', 'route'])
                ->each(function (object $menu): void {
                    DB::table('menus')->where('id', $menu->id)->update([
                        'route' => preg_replace('#^/payroll/leaves#', '/hr/leaves', (string) $menu->route),
                        'module_code' => 'hr',
                    ]);
                });

            if (Schema::hasColumn('menus', 'system_key')) {
                DB::table('menus')
                    ->where('system_key', 'hr.leaves')
                    ->update([
                        'route' => '/hr/leaves',
                        'module_code' => 'hr',
                    ]);
            }
        }

        $this->revokeEmployeeLeaveMenus();
    }

    public function down(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')
            ->where('route', 'like', '/hr/leaves%')
            ->orderBy('id')
            ->get(['id', 'route'])
            ->each(function (object $menu): void {
                DB::table('menus')->where('id', $menu->id)->update([
                    'route' => preg_replace('#^/hr/leaves#', '/payroll/leaves', (string) $menu->route),
                    'module_code' => 'payroll',
                ]);
            });
    }

    private function revokeEmployeeLeaveMenus(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereRaw('LOWER(name) = ?', ['employee'])
            ->pluck('id');
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['view-leave', 'view-leave-types'])
            ->pluck('id');

        if ($roleIds->isEmpty() || $permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
