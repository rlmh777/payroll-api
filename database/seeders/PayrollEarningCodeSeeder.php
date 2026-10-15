<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\PayrollEarningCode;
use Illuminate\Database\Seeder;

class PayrollEarningCodeSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            ['REGULAR', 'Regular Wages', '6101', true, true, 1, PayrollEarningCode::SOURCE_TIMESHEET_REGULAR, true],
            ['OVERTIME', 'Overtime Wages', '6101', true, true, 2, PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME, true],
            ['HOLIDAY', 'Holiday Pay', '6101', true, true, 3, PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY, true],
            ['TIPS', 'Tips', '6104', true, true, 4, null, false],
            ['BONUS', 'Bonuses', '6105', true, true, 5, null, false],
            ['ALLOWANCE', 'Other Payments', '6106', true, true, 6, PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK, false],
            ['VACATION', 'Vacation Pay', '6101', false, false, 7, PayrollEarningCode::SOURCE_VACATION, true],
            ['OTHER', 'Other Earnings', '6199', true, true, 99, null, false],
        ];

        foreach ($codes as [$code, $name, $accountCode, $taxable, $ssSubject, $sortOrder, $source, $postToDepartment]) {
            $accountId = Account::where('code1', $accountCode)->value('id');
            if (
                filled($source)
                && PayrollEarningCode::query()
                    ->where('source', $source)
                    ->where('code', '!=', $code)
                    ->exists()
            ) {
                $source = null;
                $postToDepartment = false;
            }

            PayrollEarningCode::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'account_id' => $accountId,
                    'source' => $source,
                    'post_to_department_account' => $postToDepartment,
                    'is_taxable' => $taxable,
                    'is_ss_subject' => $ssSubject,
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ],
            );
        }

        if (! PayrollEarningCode::query()->where('source', PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK)->exists()) {
            PayrollEarningCode::query()
                ->whereIn('code', ['ALLOWANCE', 'OTHER_PAYMENTS'])
                ->whereNull('source')
                ->limit(1)
                ->update([
                    'source' => PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK,
                    'post_to_department_account' => false,
                ]);
        }
    }
}
