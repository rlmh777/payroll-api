<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PayrollChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $expenseType = AccountType::where('name', 'EXPENSE')->first();
        $liabilityType = AccountType::where('name', 'LIABILITY')->first();

        if (!$expenseType || !$liabilityType) {
            $this->command?->warn('PayrollChartOfAccountsSeeder skipped: account types missing.');
            return;
        }

        $payrollExpense = Account::updateOrCreate(
            ['code1' => '6100'],
            [
                'id' => Account::where('code1', '6100')->value('id') ?? (string) Str::uuid(),
                'name' => 'Payroll Expense',
                'description' => 'Parent payroll wage expense',
                'code2' => null,
                'balance' => 0,
                'account_type_id' => $expenseType->id,
                'parent_id' => null,
            ],
        );

        $employerCosts = Account::updateOrCreate(
            ['code1' => '6200'],
            [
                'id' => Account::where('code1', '6200')->value('id') ?? (string) Str::uuid(),
                'name' => 'Employer Payroll Costs',
                'description' => 'Employer payroll taxes and contributions',
                'code2' => null,
                'balance' => 0,
                'account_type_id' => $expenseType->id,
                'parent_id' => null,
            ],
        );

        $subAccounts = [
            ['6100', '6101', 'Regular Wages', $payrollExpense->id],
            ['6100', '6102', 'Overtime Wages', $payrollExpense->id],
            ['6100', '6103', 'Holiday Pay', $payrollExpense->id],
            ['6100', '6104', 'Tips', $payrollExpense->id],
            ['6100', '6105', 'Bonuses', $payrollExpense->id],
            ['6100', '6106', 'Other Payments', $payrollExpense->id],
            ['6100', '6199', 'Other Earnings', $payrollExpense->id],
            ['6200', '6201', 'Employer Social Security', $employerCosts->id],
            ['6200', '6202', 'Employer Other Statutory', $employerCosts->id],
        ];

        foreach ($subAccounts as [$parentCode, $code, $name, $parentId]) {
            Account::updateOrCreate(
                ['code1' => $code],
                [
                    'id' => Account::where('code1', $code)->value('id') ?? (string) Str::uuid(),
                    'name' => $name,
                    'description' => $name,
                    'code2' => $parentCode,
                    'balance' => 0,
                    'account_type_id' => $expenseType->id,
                    'parent_id' => $parentId,
                ],
            );
        }

        $liabilityAccounts = [
            ['2100', 'Wages Payable'],
            ['2101', 'Income Tax Payable'],
            ['2102', 'Social Security Payable'],
            ['2103', 'Tips Payable'],
        ];

        $wagesPayable = Account::updateOrCreate(
            ['code1' => '2100'],
            [
                'id' => Account::where('code1', '2100')->value('id') ?? (string) Str::uuid(),
                'name' => 'Wages Payable',
                'description' => 'Wages Payable',
                'code2' => null,
                'balance' => 0,
                'account_type_id' => $liabilityType->id,
                'parent_id' => null,
            ],
        );

        foreach (array_slice($liabilityAccounts, 1) as [$code, $name]) {
            Account::updateOrCreate(
                ['code1' => $code],
                [
                    'id' => Account::where('code1', $code)->value('id') ?? (string) Str::uuid(),
                    'name' => $name,
                    'description' => $name,
                    'code2' => '2100',
                    'balance' => 0,
                    'account_type_id' => $liabilityType->id,
                    'parent_id' => $wagesPayable->id,
                ],
            );
        }
    }
}
