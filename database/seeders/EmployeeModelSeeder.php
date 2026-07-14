<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Employee;
use App\Modules\Hr\Services\EmployeePersonSync;
use App\Models\Locality;
use App\Models\Country;
use App\Models\Honorific;
use App\Models\Gender;
use App\Models\CitizenshipStatus;
use App\Models\PayrateFrequency;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class EmployeeModelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        // Get all available IDs from related tables
        $honorificIds = Honorific::pluck('id')->toArray();
        $genderIds = Gender::pluck('id')->toArray();
        $localityIds = Locality::pluck('id')->toArray();
        $countryIds = Country::pluck('id')->toArray();
        $citizenshipStatusIds = CitizenshipStatus::pluck('id')->toArray();
        $payrateFrequencyIds = PayrateFrequency::pluck('id')->toArray();
        $paymentMethodIds = PaymentMethod::pluck('id')->toArray();

        // Ensure we have at least some data, otherwise use defaults
        if (empty($honorificIds)) $honorificIds = [1];
        if (empty($genderIds)) $genderIds = [1];
        if (empty($localityIds)) $localityIds = [1];
        if (empty($countryIds)) $countryIds = [1];
        if (empty($citizenshipStatusIds)) $citizenshipStatusIds = [1];
        if (empty($payrateFrequencyIds)) $payrateFrequencyIds = [1];
        if (empty($paymentMethodIds)) $paymentMethodIds = [1];

        // Generate 100 employees
        for ($i = 1; $i <= 100; $i++) {
            $firstName = $faker->firstName();
            $lastName = $faker->lastName();
            $genderId = $faker->randomElement($genderIds);
            $gender = Gender::find($genderId);
            
            // Determine if employee should have a maiden name (typically for married females)
            $hasMaidenName = $gender && strtolower($gender->name) === 'female' && $faker->boolean(30);

            EmployeePersonSync::create([
                'id' => Str::uuid(),
                'code' => str_pad($i, 6, '0', STR_PAD_LEFT), // Generate sequential codes: 000001, 000002, etc.
                'internalId1' => 'INT' . strtoupper($faker->bothify('?##??')),
                'internalId2' => $faker->boolean(70) ? 'EXT' . strtoupper($faker->bothify('?##??')) : null,
                'honorificId' => $faker->randomElement($honorificIds),
                'firstName' => $firstName,
                'middleName' => $faker->boolean(60) ? $faker->firstName() : null,
                'lastName' => $lastName,
                'maidenName' => $hasMaidenName ? $faker->lastName() : null,
                'birthdate' => $faker->dateTimeBetween('-65 years', '-18 years')->format('Y-m-d'),
                'address1' => $faker->streetAddress(),
                'address2' => $faker->boolean(40) ? $faker->secondaryAddress() : null,
                'localityId' => $faker->randomElement($localityIds),
                'phone' => $faker->numerify('#######'),
                'email' => strtolower($firstName . '.' . $lastName . '@' . $faker->safeEmailDomain()),
                'genderId' => $genderId,
                'socialSecurityNumber' => $faker->numerify('########'),
                'taxIdentificationNumber' => 'TIN' . $faker->numerify('########'),
                'passportNumber' => strtoupper($faker->bothify('?########')),
                'votersId' => $faker->boolean(80) ? 'VOTER' . $faker->numerify('######') : null,
                'citizenshipStatusId' => $faker->randomElement($citizenshipStatusIds),
                'nationalityId' => $faker->randomElement($countryIds),
                'payrateFrequencyId' => $faker->randomElement($payrateFrequencyIds),
                'paymentMethodId' => $faker->randomElement($paymentMethodIds),
                'notes' => $faker->boolean(50) ? $faker->sentence() : null,
                'picturePath' => $faker->boolean(60) ? 'images/profile/employee_' . $i . '.jpg' : null,
                'health' => $faker->randomElement(['Excellent', 'Good', 'Fair', 'Needs Attention', null]),
                'unionMembership' => $faker->boolean(40) ? $faker->randomElement(['PSU', 'BSU', 'NTEU', 'CWA', 'Local 123']) : null,
            ]);
        }
    }
}
