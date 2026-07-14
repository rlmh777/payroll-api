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

        foreach (['view-employees', 'employees-crud'] as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName]);
        }

        foreach (['super-admin', 'admin', 'hr-admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $role->givePermissionTo(['view-employees', 'employees-crud']);
            }
        }

        $employeesId = DB::table('menus')
            ->where('route', '/employees')
            ->where('type', 'menu')
            ->value('id');

        if (!$employeesId) {
            return;
        }

        $this->ensureSubmenu((string) $employeesId, 'Add Employee', '/employees/new', 'person_add', 'employees-crud', 1);
        $this->ensureSubmenu((string) $employeesId, 'Import Employees', '/employees/import', 'upload_file', 'employees-crud', 2);
    }

    public function down(): void
    {
        if (!Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')
            ->whereIn('route', ['/employees/new', '/employees/import'])
            ->delete();
    }

    private function ensureSubmenu(
        string $parentId,
        string $title,
        string $route,
        string $icon,
        string $permission,
        int $order,
    ): void {
        $exists = DB::table('menus')->where('route', $route)->exists();

        if ($exists) {
            DB::table('menus')
                ->where('route', $route)
                ->update([
                    'parent_id' => $parentId,
                    'title' => $title,
                    'icon' => $icon,
                    'permission' => $permission,
                    'order' => $order,
                    'type' => 'submenu',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('menus')->insert([
            'id' => (string) Str::uuid(),
            'parent_id' => $parentId,
            'title' => $title,
            'route' => $route,
            'icon' => $icon,
            'permission' => $permission,
            'order' => $order,
            'type' => 'submenu',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
