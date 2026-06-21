<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        // Fetch required foreign keys
        $localityId = DB::table('locality')->value('id');
        $wagesPayableAccountId = DB::table('accounts')->value('id');

        if (!$localityId || !$wagesPayableAccountId) {
            $this->command->warn(
                'Required locality or account not found. Company seeder skipped.'
            );
            return;
        }

        Company::create([
            'legalName' => $faker->company(),
            'alias' => strtoupper($faker->lexify('???')),

            // Binary / encrypted-safe numeric value
            'socialSecurityNumber' => (int) $faker->numerify('#########'),

            // Integer TIN
            'taxIdentificationNumber' => $faker->numerify('#########'),

            // Store relative path (CDN handled via accessor)
            'logoPath' => 'logos/' . $faker->randomElement([
                'acme.png',
                'company.png',
                'default.png',
            ]),
            'logo' => $faker->word() . '.png',

            'phoneNumber1' => $faker->phoneNumber(),
            'phoneNumber2' => $faker->phoneNumber(),
            'email' => $faker->companyEmail(),

            'street' => $faker->streetAddress(),

            'localityId' => $localityId,
            'wagesPayableAccountId' => $wagesPayableAccountId,

            // Nullable
            'defaultBankAccountId' => null,
        ]);

        $this->command->info('Single company record seeded successfully using Faker.');
    }
}
