<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Company;
use App\Models\Locality;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CompanySeeder extends Seeder
{
    /**
     * Seed a single company record.
     */
    public function run(): void
    {
        if (Company::exists()) {
            return;
        }

        $locality = Locality::first();
        if (!$locality) {
            $this->command?->warn('CompanySeeder skipped: no localities found.');
            return;
        }

        $liabilityType = AccountType::where('name', 'LIABILITY')->first();
        if (!$liabilityType) {
            $this->command?->warn('CompanySeeder skipped: LIABILITY account type not found.');
            return;
        }

        $wagesPayableAccount = Account::create([
            'id' => Str::uuid(),
            'name' => 'Wages Payable',
            'description' => 'Default wages payable account for payroll',
            'code1' => '2100',
            'code2' => null,
            'balance' => 0,
            'account_type_id' => $liabilityType->id,
            'parent_id' => null,
        ]);

        Company::create([
            'legalName' => 'Chaa Creek Ltd.',
            'alias' => 'Chaa Creek',
            'socialSecurityNumber' => '0002323',
            'taxIdentificationNumber' => '',
            'logoPath' => '',
            'phoneNumber1' => '824-2222',
            'phoneNumber2' => '824-2222',
            'email' => 'info@chaacreek.com',
            'street' => '123 Main St',
            'localityId' => $locality->id,
            'logo' => '',
            'primaryColor' => '#1976D2',
            'secondaryColor' => '#26A69A',
            'wagesPayableAccountId' => $wagesPayableAccount->id,
            'defaultBankAccountId' => null,
        ]);
    }
}
