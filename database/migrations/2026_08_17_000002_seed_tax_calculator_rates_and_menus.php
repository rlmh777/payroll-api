<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TaxCalculatorRate;
use App\Modules\Payroll\Services\TaxCalculatorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_calculator_rates')) {
            $now = now();
            $defaults = [
                [
                    'category' => TaxCalculatorService::CATEGORY_BUSINESS_TAX,
                    'code' => TaxCalculatorService::BUSINESS_INCOME,
                    'name' => 'Business Tax — Income',
                    'rate' => 0.0175,
                    'iris_line' => 'Line 10',
                    'applies_to_accounts' => true,
                    'sort_order' => 10,
                ],
                [
                    'category' => TaxCalculatorService::CATEGORY_BUSINESS_TAX,
                    'code' => TaxCalculatorService::BUSINESS_PROFESSIONAL_SERVICES,
                    'name' => 'Business Tax — Professional Services',
                    'rate' => 0.06,
                    'iris_line' => 'Line 20',
                    'applies_to_accounts' => true,
                    'sort_order' => 20,
                ],
                [
                    'category' => TaxCalculatorService::CATEGORY_BUSINESS_TAX,
                    'code' => TaxCalculatorService::BUSINESS_TOUR_OPERATOR,
                    'name' => 'Business Tax — Tour Operator',
                    'rate' => 0.06,
                    'iris_line' => 'Line 120',
                    'applies_to_accounts' => true,
                    'sort_order' => 30,
                ],
                [
                    'category' => TaxCalculatorService::CATEGORY_BUSINESS_TAX,
                    'code' => TaxCalculatorService::BUSINESS_DIVIDEND,
                    'name' => 'Business Tax — Dividend',
                    'rate' => 0.15,
                    'iris_line' => 'Line 110',
                    'applies_to_accounts' => true,
                    'sort_order' => 40,
                ],
                [
                    'category' => TaxCalculatorService::CATEGORY_GST,
                    'code' => TaxCalculatorService::GST_INCOME,
                    'name' => 'GST — Income',
                    'rate' => 0.125,
                    'iris_line' => 'Line 100',
                    'applies_to_accounts' => true,
                    'sort_order' => 50,
                ],
                [
                    'category' => TaxCalculatorService::CATEGORY_GST,
                    'code' => TaxCalculatorService::GST_EXEMPT_INCOME,
                    'name' => 'GST — Exempt Income',
                    'rate' => 0,
                    'iris_line' => 'Line 120',
                    'applies_to_accounts' => true,
                    'sort_order' => 60,
                ],
                [
                    'category' => TaxCalculatorService::CATEGORY_GST,
                    'code' => TaxCalculatorService::GST_ZERO_INCOME,
                    'name' => 'GST — Zero Income',
                    'rate' => 0,
                    'iris_line' => 'Line 110',
                    'applies_to_accounts' => true,
                    'sort_order' => 70,
                ],
                [
                    'category' => TaxCalculatorService::CATEGORY_BTB,
                    'code' => TaxCalculatorService::BTB_HOTEL_TAX,
                    'name' => 'BTB Hotel Tax',
                    'rate' => 0.09,
                    'iris_line' => null,
                    'applies_to_accounts' => true,
                    'sort_order' => 80,
                ],
                [
                    'category' => TaxCalculatorService::CATEGORY_ADJUSTMENT,
                    'code' => TaxCalculatorService::GST_PARTIAL_EXEMPTION,
                    'name' => 'GST partial exemption rate',
                    'rate' => 0.08,
                    'iris_line' => 'Line 230',
                    'applies_to_accounts' => false,
                    'sort_order' => 90,
                ],
                [
                    'category' => TaxCalculatorService::CATEGORY_ADJUSTMENT,
                    'code' => TaxCalculatorService::GST_INPUT_RECOVERY_MULTIPLIER,
                    'name' => 'GST input recovery multiplier',
                    'rate' => 8,
                    'iris_line' => 'Line 210',
                    'applies_to_accounts' => false,
                    'sort_order' => 100,
                ],
            ];

            foreach ($defaults as $row) {
                TaxCalculatorRate::query()->firstOrCreate(
                    ['code' => $row['code']],
                    array_merge($row, [
                        'id' => (string) Str::uuid(),
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]),
                );
            }
        }

        $viewPermission = Permission::firstOrCreate([
            'name' => 'view-tax-calculator',
            'guard_name' => 'web',
        ]);
        $crudPermission = Permission::firstOrCreate([
            'name' => 'tax-calculator-crud',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereIn('name', ['admin', 'accountant', 'payroll-accountant'])
            ->orWhereHas('permissions', fn ($query) => $query->whereIn('name', [
                'manager-tax',
                'view-reports',
                'view-taxes',
            ]))
            ->get()
            ->each(function (Role $role) use ($viewPermission, $crudPermission) {
                $role->givePermissionTo([$viewPermission, $crudPermission]);
            });

        if (Schema::hasTable('menus')) {
            $settings = Menu::query()->where('title', 'Settings')->whereNull('parent_id')->first();
            $payrollSettings = Menu::query()->where('route', '/settings/payroll-settings')->first();

            if ($settings) {
                Menu::query()->updateOrCreate(
                    ['route' => '/settings/tax-calculator-accounts'],
                    [
                        'title' => 'GST Calculator',
                        'icon' => 'fa-solid fa-file-invoice-dollar',
                        'permission' => 'view-tax-calculator',
                        'parent_id' => $settings->id,
                        'order' => ($payrollSettings?->order ?? 8) + 1,
                        'type' => 'submenu',
                        'is_active' => true,
                    ],
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Menu::query()->where('route', '/settings/tax-calculator-accounts')->delete();
        }
    }
};
