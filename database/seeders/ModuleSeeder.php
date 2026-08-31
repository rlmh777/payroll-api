<?php

namespace Database\Seeders;

use App\Models\CompanyModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $now = now();
        $modules = [
            [
                'code' => 'core',
                'title' => 'Core',
                'icon' => 'home',
                'default_route' => '/',
                'is_core' => false,
                'sort_order' => 1,
            ],
            [
                'code' => 'hr',
                'title' => 'HR',
                'icon' => 'groups',
                'default_route' => '/payroll/employees',
                'is_core' => false,
                'sort_order' => 2,
            ],
            [
                'code' => 'payroll',
                'title' => 'Payroll',
                'icon' => 'payments',
                'default_route' => '/payroll/overview',
                'is_core' => false,
                'sort_order' => 3,
            ],
            [
                'code' => 'performance',
                'title' => 'Performance',
                'icon' => 'trending_up',
                'default_route' => '/performance',
                'is_core' => false,
                'sort_order' => 4,
            ],
            [
                'code' => 'recruiting',
                'title' => 'Recruiting',
                'icon' => 'person_search',
                'default_route' => null,
                'is_core' => false,
                'sort_order' => 5,
            ],
            [
                'code' => 'assets',
                'title' => 'Assets',
                'icon' => 'inventory_2',
                'default_route' => null,
                'is_core' => false,
                'sort_order' => 6,
            ],
            [
                'code' => 'admin',
                'title' => 'Administration',
                'icon' => 'settings',
                'default_route' => '/payroll/settings',
                'is_core' => false,
                'sort_order' => 99,
            ],
        ];

        foreach ($modules as $module) {
            $exists = DB::table('modules')->where('code', $module['code'])->exists();

            $payload = [
                ...$module,
                'is_active' => true,
                'version' => '1.0.0',
                'updated_at' => $now,
            ];

            if ($exists) {
                DB::table('modules')->where('code', $module['code'])->update($payload);
            } else {
                DB::table('modules')->insert([
                    ...$payload,
                    'created_at' => $now,
                ]);
            }
        }

        $this->seedCompanyModules();
    }

    private function seedCompanyModules(): void
    {
        if (! Schema::hasTable('company_modules') || ! Schema::hasTable('company')) {
            return;
        }

        $companyId = DB::table('company')->orderBy('id')->value('id');
        if (! $companyId) {
            return;
        }

        $now = now();
        $enabledByDefault = ['core', 'hr', 'payroll', 'admin'];
        $moduleCodes = DB::table('modules')->pluck('code');

        foreach ($moduleCodes as $code) {
            $enabled = in_array($code, $enabledByDefault, true);

            CompanyModule::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'module_code' => $code,
                ],
                [
                    'enabled' => $enabled,
                    'enabled_at' => $enabled ? $now : null,
                    'enabled_by' => null,
                    'config' => null,
                ],
            );
        }
    }
}
