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
            ['name' => 'ASSET'],
            ['name' => 'LIABILITY'],
            ['name' => 'EQUITY'],
            ['name' => 'REVENUE'],
            ['name' => 'EXPENSE'],
        ];

        foreach ($accountTypes as $accountType) {
            AccountType::updateOrCreate(
                ['name' => $accountType['name']],
                $accountType
            );
        }
    }
}

