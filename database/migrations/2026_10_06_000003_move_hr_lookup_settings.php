<?php

use App\Models\Menu;
use App\Services\ModuleMenuCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return list<array{
     *     old_keys: list<string>,
     *     slug: string,
     *     new_key: string,
     *     title: string,
     *     icon: string,
     *     permission: string,
     *     order: int,
     *     payroll_order: int
     * }>
     */
    private function lookups(): array
    {
        return [
            [
                'old_keys' => [],
                'slug' => 'job-titles',
                'new_key' => 'hr.settings.job_titles',
                'title' => 'Job Titles',
                'icon' => 'work',
                'permission' => 'view-job-title',
                'order' => 3,
                'payroll_order' => 16,
            ],
            [
                'old_keys' => [],
                'slug' => 'department',
                'new_key' => 'hr.settings.department',
                'title' => 'Department',
                'icon' => 'fas fa-sitemap',
                'permission' => 'view-department',
                'order' => 4,
                'payroll_order' => 11,
            ],
            [
                'old_keys' => [],
                'slug' => 'worksite',
                'new_key' => 'hr.settings.worksite',
                'title' => 'Work Site',
                'icon' => 'fas fa-map',
                'permission' => 'view-worksite',
                'order' => 5,
                'payroll_order' => 12,
            ],
            [
                'old_keys' => [],
                'slug' => 'holidays',
                'new_key' => 'hr.settings.holidays',
                'title' => 'Public Holidays',
                'icon' => 'event',
                'permission' => 'view-holidays',
                'order' => 6,
                'payroll_order' => 13,
            ],
            [
                'old_keys' => [],
                'slug' => 'relationship',
                'new_key' => 'hr.settings.relationship',
                'title' => 'Relationship',
                'icon' => 'fas fa-users',
                'permission' => 'view-relationship',
                'order' => 7,
                'payroll_order' => 6,
            ],
            [
                'old_keys' => [],
                'slug' => 'degree',
                'new_key' => 'hr.settings.degree',
                'title' => 'Degree',
                'icon' => 'fas fa-graduation-cap',
                'permission' => 'view-degree',
                'order' => 8,
                'payroll_order' => 10,
            ],
            [
                'old_keys' => [],
                'slug' => 'country',
                'new_key' => 'hr.settings.country',
                'title' => 'Country',
                'icon' => 'fas fa-globe',
                'permission' => 'view-country',
                'order' => 9,
                'payroll_order' => 1,
            ],
            [
                'old_keys' => [],
                'slug' => 'district',
                'new_key' => 'hr.settings.district',
                'title' => 'District',
                'icon' => 'fas fa-map-marker-alt',
                'permission' => 'view-district',
                'order' => 10,
                'payroll_order' => 2,
            ],
            [
                'old_keys' => [],
                'slug' => 'locality',
                'new_key' => 'hr.settings.locality',
                'title' => 'Locality',
                'icon' => 'fas fa-map-pin',
                'permission' => 'view-locality',
                'order' => 11,
                'payroll_order' => 3,
            ],
            [
                'old_keys' => [],
                'slug' => 'institution',
                'new_key' => 'hr.settings.institution',
                'title' => 'Institution',
                'icon' => 'fas fa-university',
                'permission' => 'view-institution',
                'order' => 12,
                'payroll_order' => 4,
            ],
        ];
    }

    public function up(): void
    {
        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $hrSettings = $this->ensureHrSettings();

        foreach ($this->lookups() as $item) {
            $this->moveLookup($hrSettings->id, $item);
        }

        Menu::query()->whereIn('route', $this->legacyRoutes())->delete();
    }

    public function down(): void
    {
        if (! class_exists(Menu::class) || ! Schema::hasTable('menus')) {
            return;
        }

        $generalId = Menu::query()->where('system_key', 'payroll.settings.general')->value('id');

        foreach ($this->lookups() as $item) {
            $menu = Menu::query()->where('system_key', $item['new_key'])->first();
            if (! $menu) {
                continue;
            }

            $menu->update([
                'system_key' => null,
                'parent_id' => $generalId,
                'title' => $item['title'],
                'route' => '/payroll/settings/'.$item['slug'],
                'icon' => $item['icon'],
                'permission' => $item['permission'],
                'order' => $item['payroll_order'],
                'type' => 'submenu',
                'module_code' => 'payroll',
                'is_active' => true,
                'source' => 'system',
            ]);
        }

        Menu::query()->where('system_key', 'hr.settings')->update([
            'permission' => 'view-vacancies',
        ]);
    }

    private function ensureHrSettings(): Menu
    {
        $payload = [
            'parent_id' => null,
            'title' => 'Settings',
            'route' => '/hr/settings',
            'icon' => 'settings',
            'permission' => ModuleMenuCatalog::HR_SETTINGS_PERMISSION,
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
     * @param  array{
     *     old_keys: list<string>,
     *     slug: string,
     *     new_key: string,
     *     title: string,
     *     icon: string,
     *     permission: string,
     *     order: int
     * }  $item
     */
    private function moveLookup(string $parentId, array $item): void
    {
        $payload = [
            'parent_id' => $parentId,
            'title' => $item['title'],
            'route' => '/hr/settings/'.$item['slug'],
            'icon' => $item['icon'],
            'permission' => $item['permission'],
            'order' => $item['order'],
            'is_active' => true,
            'type' => 'submenu',
            'module_code' => 'hr',
            'source' => 'system',
            'system_key' => $item['new_key'],
        ];

        $existing = Menu::query()->where('system_key', $item['new_key'])->first();
        $legacy = Menu::query()
            ->where(function ($query) use ($item) {
                $query->whereIn('route', [
                    '/payroll/settings/'.$item['slug'],
                    '/payroll/settings/general/'.$item['slug'],
                ]);
                if ($item['old_keys'] !== []) {
                    $query->orWhereIn('system_key', $item['old_keys']);
                }
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
     * @return list<string>
     */
    private function legacyRoutes(): array
    {
        $routes = [];
        foreach ($this->lookups() as $item) {
            $routes[] = '/payroll/settings/'.$item['slug'];
            $routes[] = '/payroll/settings/general/'.$item['slug'];
        }

        return $routes;
    }
};
