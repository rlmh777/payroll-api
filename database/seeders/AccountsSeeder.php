<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountsSeeder extends Seeder
{
    public function run(): void
    {
        // Parent accounts
        $assetsId = Str::uuid();
        $liabilitiesId = Str::uuid();

        DB::table('accounts')->insert([
            [
                'id' => $assetsId,
                'name' => 'Assets',
                'description' => 'Asset accounts',
                'code1' => '1000',
                'code2' => null,
                'balance' => 0.00,
                'account_type_id' => 1,
                'parent_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $liabilitiesId,
                'name' => 'Liabilities',
                'description' => 'Liability accounts',
                'code1' => '2000',
                'code2' => null,
                'balance' => 0.00,
                'account_type_id' => 2,
                'parent_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Child accounts
        DB::table('accounts')->insert([
            [
                'id' => Str::uuid(),
                'name' => 'Cash',
                'description' => 'Cash on hand',
                'code1' => '1100',
                'code2' => 'CASH',
                'balance' => 15000.00,
                'account_type_id' => 1,
                'parent_id' => $assetsId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'name' => 'Bank',
                'description' => 'Bank account',
                'code1' => '1200',
                'code2' => 'BANK',
                'balance' => 25000.00,
                'account_type_id' => 1,
                'parent_id' => $assetsId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'name' => 'Accounts Payable',
                'description' => 'Supplier payables',
                'code1' => '2100',
                'code2' => 'AP',
                'balance' => 5000.00,
                'account_type_id' => 2,
                'parent_id' => $liabilitiesId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
