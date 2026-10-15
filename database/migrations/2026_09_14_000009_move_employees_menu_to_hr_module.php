<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('modules')->where('code', 'hr')->update([
            'default_route' => '/hr/employees',
            'updated_at' => $now,
        ]);

        $employeesId = DB::table('menus')->where('system_key', 'hr.employees')->value('id');

        if ($employeesId) {
            DB::table('menus')->where('id', $employeesId)->update([
                'title' => 'Employees',
                'route' => '/hr/employees',
                'module_code' => 'hr',
                'permission' => 'view-employees',
                'source' => 'system',
                'is_active' => true,
                'updated_at' => $now,
            ]);

            DB::table('menus')
                ->where('parent_id', $employeesId)
                ->where(function ($query) {
                    $query->where('route', 'like', '/payroll/employees%')
                        ->orWhere('route', 'like', '/hr/employees%')
                        ->orWhereIn('title', ['Add Employee', 'Import Employees']);
                })
                ->get()
                ->each(function ($child) use ($now) {
                    $route = (string) ($child->route ?? '');
                    if (str_contains($route, '/import') || $child->title === 'Import Employees') {
                        $route = '/hr/employees/import';
                    } elseif (str_contains($route, '/new') || $child->title === 'Add Employee') {
                        $route = '/hr/employees/new';
                    } else {
                        $route = str_starts_with($route, '/payroll/employees')
                            ? preg_replace('#^/payroll/employees#', '/hr/employees', $route)
                            : $route;
                    }

                    DB::table('menus')->where('id', $child->id)->update([
                        'route' => $route,
                        'module_code' => 'hr',
                        'updated_at' => $now,
                    ]);
                });
        }

        // Catch any leftover employee routes still under payroll prefix.
        DB::table('menus')
            ->where('route', 'like', '/payroll/employees%')
            ->orderBy('id')
            ->get()
            ->each(function ($row) use ($now) {
                DB::table('menus')->where('id', $row->id)->update([
                    'route' => preg_replace('#^/payroll/employees#', '/hr/employees', (string) $row->route),
                    'module_code' => 'hr',
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        $now = now();

        DB::table('modules')->where('code', 'hr')->update([
            'default_route' => '/payroll/employees',
            'updated_at' => $now,
        ]);

        DB::table('menus')
            ->where(function ($query) {
                $query->where('system_key', 'hr.employees')
                    ->orWhere('route', 'like', '/hr/employees%');
            })
            ->orderBy('id')
            ->get()
            ->each(function ($row) use ($now) {
                DB::table('menus')->where('id', $row->id)->update([
                    'route' => preg_replace('#^/hr/employees#', '/payroll/employees', (string) $row->route),
                    'module_code' => 'payroll',
                    'updated_at' => $now,
                ]);
            });
    }
};
