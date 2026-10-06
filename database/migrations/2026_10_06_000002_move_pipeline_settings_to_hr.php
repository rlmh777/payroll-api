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

        $hrSettings = $this->ensureHrSettings();

        $this->movePipelineMenu(
            oldKeys: ['payroll.settings.vacancy_stages'],
            oldRoutes: ['/payroll/settings/vacancy-stages', '/payroll/settings/general/vacancy-stages'],
            newKey: 'hr.settings.vacancy_stages',
            parentId: $hrSettings->id,
            title: 'Vacancy pipeline',
            route: '/hr/settings/vacancy-stages',
            icon: 'view_kanban',
            order: 1,
        );

        $this->movePipelineMenu(
            oldKeys: ['payroll.settings.candidate_stages'],
            oldRoutes: ['/payroll/settings/candidate-stages', '/payroll/settings/general/candidate-stages'],
            newKey: 'hr.settings.candidate_stages',
            parentId: $hrSettings->id,
            title: 'Candidate pipeline',
            route: '/hr/settings/candidate-stages',
            icon: 'account_tree',
            order: 2,
        );

        Menu::query()->whereIn('route', [
            '/payroll/settings/vacancy-stages',
            '/payroll/settings/candidate-stages',
            '/payroll/settings/general/vacancy-stages',
            '/payroll/settings/general/candidate-stages',
        ])->delete();
    }

    public function down(): void
    {
        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $generalId = Menu::query()->where('system_key', 'payroll.settings.general')->value('id');

        $this->restorePayrollPipeline('hr.settings.vacancy_stages', [
            'system_key' => 'payroll.settings.vacancy_stages',
            'parent_id' => $generalId,
            'title' => 'Vacancy pipeline',
            'route' => '/payroll/settings/vacancy-stages',
            'icon' => 'view_kanban',
            'permission' => 'view-vacancies',
            'order' => 18,
            'type' => 'submenu',
            'module_code' => 'payroll',
        ]);

        $this->restorePayrollPipeline('hr.settings.candidate_stages', [
            'system_key' => 'payroll.settings.candidate_stages',
            'parent_id' => $generalId,
            'title' => 'Candidate pipeline',
            'route' => '/payroll/settings/candidate-stages',
            'icon' => 'account_tree',
            'permission' => 'view-vacancies',
            'order' => 19,
            'type' => 'submenu',
            'module_code' => 'payroll',
        ]);

        Menu::query()->where('system_key', 'hr.settings')->delete();
    }

    private function ensureHrSettings(): Menu
    {
        $payload = [
            'parent_id' => null,
            'title' => 'Settings',
            'route' => '/hr/settings',
            'icon' => 'settings',
            'permission' => 'view-vacancies',
            'order' => 5,
            'is_active' => true,
            'type' => 'menu',
            'module_code' => 'hr',
            'source' => 'system',
            'system_key' => 'hr.settings',
        ];

        $existing = Menu::query()->where('system_key', 'hr.settings')->first()
            ?? Menu::query()->where('route', '/hr/settings')->whereNull('parent_id')->first();

        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return Menu::query()->create($payload);
    }

    /**
     * @param  list<string>  $oldKeys
     * @param  list<string>  $oldRoutes
     */
    private function movePipelineMenu(
        array $oldKeys,
        array $oldRoutes,
        string $newKey,
        string $parentId,
        string $title,
        string $route,
        string $icon,
        int $order,
    ): void {
        $payload = [
            'parent_id' => $parentId,
            'title' => $title,
            'route' => $route,
            'icon' => $icon,
            'permission' => 'view-vacancies',
            'order' => $order,
            'is_active' => true,
            'type' => 'submenu',
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
    private function restorePayrollPipeline(string $newKey, array $payload): void
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
