<?php

use App\Models\Menu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $this->promoteHrMenu(
            oldKeys: ['hr.employees.vacancies'],
            oldRoutes: ['/hr/employees/vacancies', '/payroll/employees/vacancies'],
            newKey: 'hr.vacancies',
            title: 'Vacancies',
            route: '/hr/vacancies',
            icon: 'work',
            order: 3,
        );

        $this->promoteHrMenu(
            oldKeys: ['hr.employees.candidates'],
            oldRoutes: ['/hr/employees/candidates', '/payroll/employees/candidates'],
            newKey: 'hr.candidates',
            title: 'Candidates',
            route: '/hr/candidates',
            icon: 'badge',
            order: 4,
        );

        Menu::query()->whereIn('system_key', [
            'payroll.employees.vacancies',
            'payroll.employees.candidates',
        ])->delete();

        Menu::query()->whereIn('route', [
            '/hr/employees/vacancies',
            '/hr/employees/candidates',
            '/payroll/employees/vacancies',
            '/payroll/employees/candidates',
        ])->delete();
    }

    public function down(): void
    {
        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $hrEmployeesId = Menu::query()->where('system_key', 'hr.employees')->value('id');
        $payrollEmployeesId = Menu::query()->where('system_key', 'payroll.employees')->value('id');

        $this->restoreSubmenu('hr.vacancies', [
            'system_key' => 'hr.employees.vacancies',
            'parent_id' => $hrEmployeesId,
            'title' => 'Vacancies',
            'route' => '/hr/employees/vacancies',
            'icon' => 'work',
            'permission' => 'view-vacancies',
            'order' => 3,
            'type' => 'submenu',
            'module_code' => 'hr',
        ]);

        $this->restoreSubmenu('hr.candidates', [
            'system_key' => 'hr.employees.candidates',
            'parent_id' => $hrEmployeesId,
            'title' => 'Candidates',
            'route' => '/hr/employees/candidates',
            'icon' => 'badge',
            'permission' => 'view-vacancies',
            'order' => 4,
            'type' => 'submenu',
            'module_code' => 'hr',
        ]);

        if ($payrollEmployeesId) {
            Menu::query()->updateOrCreate(
                ['system_key' => 'payroll.employees.vacancies'],
                [
                    'parent_id' => $payrollEmployeesId,
                    'title' => 'Vacancies',
                    'route' => '/payroll/employees/vacancies',
                    'icon' => 'work',
                    'permission' => 'view-vacancies',
                    'order' => 3,
                    'is_active' => true,
                    'type' => 'submenu',
                    'module_code' => 'payroll',
                    'source' => 'system',
                ],
            );
            Menu::query()->updateOrCreate(
                ['system_key' => 'payroll.employees.candidates'],
                [
                    'parent_id' => $payrollEmployeesId,
                    'title' => 'Candidates',
                    'route' => '/payroll/employees/candidates',
                    'icon' => 'badge',
                    'permission' => 'view-vacancies',
                    'order' => 4,
                    'is_active' => true,
                    'type' => 'submenu',
                    'module_code' => 'payroll',
                    'source' => 'system',
                ],
            );
        }
    }

    /**
     * @param  list<string>  $oldKeys
     * @param  list<string>  $oldRoutes
     */
    private function promoteHrMenu(
        array $oldKeys,
        array $oldRoutes,
        string $newKey,
        string $title,
        string $route,
        string $icon,
        int $order,
    ): void {
        $payload = [
            'parent_id' => null,
            'title' => $title,
            'route' => $route,
            'icon' => $icon,
            'permission' => 'view-vacancies',
            'order' => $order,
            'is_active' => true,
            'type' => 'menu',
            'module_code' => 'hr',
            'source' => 'system',
            'system_key' => $newKey,
        ];

        $existing = Menu::query()->where('system_key', $newKey)->first();
        $legacy = Menu::query()
            ->where(function ($query) use ($oldKeys, $oldRoutes) {
                $query->whereIn('system_key', $oldKeys)
                    ->orWhereIn('route', $oldRoutes);
            })
            ->orderBy('id')
            ->get();

        if ($existing) {
            $existing->update($payload);
            $legacy->where('id', '!=', $existing->id)->each->delete();

            return;
        }

        $first = $legacy->first();
        if ($first) {
            $first->update($payload);
            $legacy->where('id', '!=', $first->id)->each->delete();

            return;
        }

        Menu::query()->create($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function restoreSubmenu(string $newKey, array $payload): void
    {
        $menu = Menu::query()->where('system_key', $newKey)->first();
        if (! $menu) {
            return;
        }

        $menu->update(array_merge($payload, [
            'is_active' => true,
            'source' => 'system',
        ]));
    }
};
