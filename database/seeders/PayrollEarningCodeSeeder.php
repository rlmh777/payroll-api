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
            ['REGULAR', 'Regular Wages', '6101', true, true, 1],
            ['OVERTIME', 'Overtime Wages', '6102', true, true, 2],
            ['HOLIDAY', 'Holiday Pay', '6103', true, true, 3],
            ['TIPS', 'Tips', '6104', true, true, 4],
            ['BONUS', 'Bonuses', '6105', true, true, 5],
            ['ALLOWANCE', 'Other Payments', '6106', true, true, 6],
            ['OTHER', 'Other Earnings', '6199', true, true, 99],
        ];

        foreach ($codes as [$code, $name, $accountCode, $taxable, $ssSubject, $sortOrder]) {
            $accountId = Account::where('code1', $accountCode)->value('id');

            PayrollEarningCode::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'account_id' => $accountId,
                    'is_taxable' => $taxable,
                    'is_ss_subject' => $ssSubject,
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ],
            );
        }
    }
}
