<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Employee;
use Illuminate\Support\Str;

class EmployeeModelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        Employee::create([
            'id' => Str::uuid(),
            'code' => '122',
            'internalId1' => '223sd',
            'internalId2' => 'sdsd22',
            'honorificId' => 1, // Example: Mr, Mrs, Dr, etc.
            'firstName' => 'John',
            'middleName' => 'A.',
            'lastName' => 'Doe',
            'maidenName' => null,
            'birthdate' => '1990-01-01',
            'address1' => '123 Main St',
            'address2' => 'Apt 4B',
            'localityId' => 'a434ac68-a096-49ab-b2af-85764db981e3',
            'phone' => '6072471',
            'email' => 'johndoe@example.com',
            'genderId' => 1, // Example: Male, Female, etc.
            'socialSecurityNumber' => '1',
            'taxIdentificationNumber' => 'TIN12345678',
            'passportNumber' => 'A12345678',
            'votersId' => 'VOTER12345',
            'citizenshipStatusId' => 1,
            'nationalityId' => 'e84c2f37-355e-4a0f-8917-be23b5922cd9',
            'payrateFrequencyId' => 1, // Example: Monthly, Weekly
            'paymentMethodId' => 1, // Example: Bank Transfer, Cash
            'notes' => 'Test data entry',
            'picturePath' => 'images/profile/default.jpg',
            'health' => 'Good',
            'unionMembership' => 'PSU',
        ]);


    }
}
