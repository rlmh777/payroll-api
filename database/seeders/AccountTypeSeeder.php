<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AccountType;

class AccountTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accountTypes = [
            [
                'name' => 'ASSET',
                'normal_balance' => 'DEBIT',
                'statement' => 'Balance Sheet',
            ],
            [
                'name' => 'LIABILITY',
                'normal_balance' => 'CREDIT',
                'statement' => 'Balance Sheet',
            ],
            [
                'name' => 'EQUITY',
                'normal_balance' => 'CREDIT',
                'statement' => 'Balance Sheet',
            ],
            [
                'name' => 'REVENUE',
                'normal_balance' => 'CREDIT',
                'statement' => 'Income Statement',
            ],
            [
                'name' => 'EXPENSE',
                'normal_balance' => 'DEBIT',
                'statement' => 'Income Statement',
            ],
        ];

        foreach ($accountTypes as $accountType) {
            AccountType::updateOrCreate(
                ['name' => $accountType['name']],
                $accountType
            );
        }
    }
}

