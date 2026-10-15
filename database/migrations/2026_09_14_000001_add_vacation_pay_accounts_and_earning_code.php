<?php

use App\Models\Account;
use App\Models\AccountType;
use App\Models\PayrollEarningCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $expenseType = AccountType::query()->where('name', 'EXPENSE')->first();
        $liabilityType = AccountType::query()->where('name', 'LIABILITY')->first();
        $payrollExpenseId = Account::query()->where('code1', '6100')->value('id');
        $wagesPayableId = Account::query()->where('code1', '2100')->value('id');

        if ($expenseType && $payrollExpenseId) {
            Account::query()->updateOrCreate(
                ['code1' => '6107'],
                [
                    'id' => Account::query()->where('code1', '6107')->value('id') ?? (string) Str::uuid(),
                    'name' => 'Vacation Pay',
                    'description' => 'Vacation / leave pay expense',
                    'code2' => '6100',
                    'balance' => 0,
                    'account_type_id' => $expenseType->id,
                    'parent_id' => $payrollExpenseId,
                ],
            );
        }

        if ($liabilityType && $wagesPayableId) {
            Account::query()->updateOrCreate(
                ['code1' => '2104'],
                [
                    'id' => Account::query()->where('code1', '2104')->value('id') ?? (string) Str::uuid(),
                    'name' => 'Leave Advances Clearing',
                    'description' => 'Clearing for leave paid in advance (offsets vacation expense on payroll)',
                    'code2' => '2100',
                    'balance' => 0,
                    'account_type_id' => $liabilityType->id,
                    'parent_id' => $wagesPayableId,
                ],
            );
        }

        $vacationAccountId = Account::query()->where('code1', '6107')->value('id');
        if ($vacationAccountId) {
            PayrollEarningCode::query()->updateOrCreate(
                ['code' => 'VACATION'],
                [
                    'name' => 'Vacation Pay',
                    'account_id' => $vacationAccountId,
                    'is_taxable' => false,
                    'is_ss_subject' => false,
                    'is_active' => true,
                    'sort_order' => 7,
                ],
            );
        }

        if (Schema::hasTable('payroll_account_mappings')) {
            $mappings = [
                ['VACATION_PAY', 'Vacation Pay', 'Vacation / leave pay expense for already-paid leave attribution', '6107', 75],
                ['LEAVE_ADVANCE_CLEARING', 'Leave Advances Clearing', 'Offsets vacation expense when leave was paid in advance', '2104', 76],
            ];

            foreach ($mappings as [$code, $name, $description, $accountCode, $sortOrder]) {
                $accountId = Account::query()->where('code1', $accountCode)->value('id');
                if (! $accountId) {
                    continue;
                }

                $exists = DB::table('payroll_account_mappings')->where('code', $code)->exists();
                if ($exists) {
                    DB::table('payroll_account_mappings')->where('code', $code)->update([
                        'name' => $name,
                        'description' => $description,
                        'account_id' => $accountId,
                        'sort_order' => $sortOrder,
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                    continue;
                }

                DB::table('payroll_account_mappings')->insert([
                    'id' => (string) Str::uuid(),
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'account_id' => $accountId,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_account_mappings')) {
            DB::table('payroll_account_mappings')->whereIn('code', [
                'VACATION_PAY',
                'LEAVE_ADVANCE_CLEARING',
            ])->delete();
        }

        PayrollEarningCode::query()->where('code', 'VACATION')->delete();
    }
};
