<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $payrollEmployeesId = DB::table('menus')->where('system_key', 'payroll.employees')->value('id');

        if (! $payrollEmployeesId) {
            $payrollEmployeesId = (string) Str::uuid();
            DB::table('menus')->insert([
                'id' => $payrollEmployeesId,
                'parent_id' => null,
                'title' => 'Employees',
                'route' => '/payroll/employees',
                'icon' => 'fas fa-user-friends',
                'permission' => 'view-employees',
                'order' => 2,
                'is_active' => true,
                'type' => 'menu',
                'system_key' => 'payroll.employees',
                'module_code' => 'payroll',
                'source' => 'system',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('menus')->where('id', $payrollEmployeesId)->update([
                'title' => 'Employees',
                'route' => '/payroll/employees',
                'icon' => 'fas fa-user-friends',
                'permission' => 'view-employees',
                'order' => 2,
                'is_active' => true,
                'type' => 'menu',
                'module_code' => 'payroll',
                'source' => 'system',
                'updated_at' => $now,
            ]);
        }

        $this->ensureChild(
            parentId: (string) $payrollEmployeesId,
            systemKey: 'payroll.employees.new',
            title: 'Add Employee',
            route: '/payroll/employees/new',
            icon: 'person_add',
            permission: 'employees-crud',
            order: 1,
            now: $now,
        );

        $this->ensureChild(
            parentId: (string) $payrollEmployeesId,
            systemKey: 'payroll.employees.import',
            title: 'Import Employees',
            route: '/payroll/employees/import',
            icon: 'upload_file',
            permission: 'employees-crud',
            order: 2,
            now: $now,
        );

        // Stabilize HR children system keys when present.
        $hrEmployeesId = DB::table('menus')->where('system_key', 'hr.employees')->value('id');
        if ($hrEmployeesId) {
            DB::table('menus')
                ->where('parent_id', $hrEmployeesId)
                ->where('route', '/hr/employees/new')
                ->whereNull('system_key')
                ->update([
                    'system_key' => 'hr.employees.new',
                    'module_code' => 'hr',
                    'updated_at' => $now,
                ]);

            DB::table('menus')
                ->where('parent_id', $hrEmployeesId)
                ->where('route', '/hr/employees/import')
                ->whereNull('system_key')
                ->update([
                    'system_key' => 'hr.employees.import',
                    'module_code' => 'hr',
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        $payrollEmployeesId = DB::table('menus')->where('system_key', 'payroll.employees')->value('id');
        if ($payrollEmployeesId) {
            DB::table('menus')->where('parent_id', $payrollEmployeesId)->delete();
            DB::table('menus')->where('id', $payrollEmployeesId)->delete();
        }

        DB::table('menus')
            ->whereIn('system_key', ['payroll.employees.new', 'payroll.employees.import'])
            ->delete();
    }

    private function ensureChild(
        string $parentId,
        string $systemKey,
        string $title,
        string $route,
        string $icon,
        string $permission,
        int $order,
        mixed $now,
    ): void {
        $existingId = DB::table('menus')->where('system_key', $systemKey)->value('id');

        if ($existingId) {
            DB::table('menus')->where('id', $existingId)->update([
                'parent_id' => $parentId,
                'title' => $title,
                'route' => $route,
                'icon' => $icon,
                'permission' => $permission,
                'order' => $order,
                'is_active' => true,
                'type' => 'submenu',
                'module_code' => 'payroll',
                'source' => 'system',
                'updated_at' => $now,
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
            'is_active' => true,
            'type' => 'submenu',
            'system_key' => $systemKey,
            'module_code' => 'payroll',
            'source' => 'system',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
