<?php

use App\Models\Account;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_account_mappings')) {
            return;
        }

        $defaults = [
            ['WAGES_PAYABLE', 'Wages Payable', 'Net pay liability', '2100', 10],
            ['INCOME_TAX_PAYABLE', 'Income Tax Payable', 'Employee income tax withheld', '2101', 20],
            ['EMPLOYEE_SOCIAL_SECURITY_PAYABLE', 'Employee Social Security Payable', 'Employee SS contribution liability', '2102', 30],
            ['EMPLOYER_SOCIAL_SECURITY_EXPENSE', 'Employer Social Security Expense', 'Employer SS contribution expense', '6201', 40],
            ['DEDUCTIONS_PAYABLE', 'Deductions Payable', 'Default account for deductions without a specific account', '6199', 50],
            ['DEPARTMENT_WAGES', 'Department Wages', 'Default wage expense when department/earning code has no account', '6101', 60],
            ['ALLOWANCES', 'Other Payments', 'Default other payment expense when other payment has no account', '6106', 70],
        ];

        $now = now();

        foreach ($defaults as [$code, $name, $description, $accountCode, $sortOrder]) {
            $exists = DB::table('payroll_account_mappings')->where('code', $code)->exists();
            if ($exists) {
                continue;
            }

            $accountId = Account::query()->where('code1', $accountCode)->value('id');

            DB::table('payroll_account_mappings')->insert([
                'id' => (string) Str::uuid(),
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'account_id' => $accountId,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $viewPermission = Permission::firstOrCreate([
            'name' => 'view-account-mappings',
            'guard_name' => 'web',
        ]);
        $crudPermission = Permission::firstOrCreate([
            'name' => 'account-mapping-crud',
            'guard_name' => 'web',
        ]);

        Role::query()
            ->whereIn('name', ['admin', 'accountant', 'payroll-accountant'])
            ->orWhereHas('permissions', fn ($query) => $query->whereIn('name', [
                'view-accounts',
                'manager-tax',
                'pay-employees-crud',
            ]))
            ->get()
            ->each(function (Role $role) use ($viewPermission, $crudPermission) {
                $role->givePermissionTo([$viewPermission, $crudPermission]);
            });

        if (Schema::hasTable('menus')) {
            $accountsMenu = Menu::query()->where('route', '/settings/accounts')->first();
            $parentId = $accountsMenu?->parent_id ?? Menu::query()->where('title', 'Settings')->whereNull('parent_id')->value('id');

            if ($parentId) {
                Menu::query()->updateOrCreate(
                    ['route' => '/settings/account-mapping'],
                    [
                        'title' => 'Account Mapping',
                        'icon' => 'fas fa-project-diagram',
                        'permission' => 'view-account-mappings',
                        'parent_id' => $parentId,
                        'order' => ($accountsMenu?->order ?? 3) + 1,
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
            Menu::query()->where('route', '/settings/account-mapping')->delete();
        }

        if (Schema::hasTable('payroll_account_mappings')) {
            DB::table('payroll_account_mappings')->whereIn('code', [
                'WAGES_PAYABLE',
                'INCOME_TAX_PAYABLE',
                'EMPLOYEE_SOCIAL_SECURITY_PAYABLE',
                'EMPLOYER_SOCIAL_SECURITY_EXPENSE',
                'DEDUCTIONS_PAYABLE',
                'DEPARTMENT_WAGES',
                'ALLOWANCES',
            ])->delete();
        }
    }
};
