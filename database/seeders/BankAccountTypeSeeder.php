<?php

namespace Database\Seeders;

use App\Models\BankAccountType;
use Illuminate\Database\Seeder;

class BankAccountTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            'Checking',
            'Savings',
            'Money Market',
            'Payroll',
        ];

        foreach ($types as $name) {
            BankAccountType::updateOrCreate(['name' => $name]);
        }
    }
}
