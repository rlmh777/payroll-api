<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\District;
use App\Models\Country;
use Illuminate\Support\Str;

class DistrictSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure we have at least one country before creating districts
        $countries = Country::all();
        
        if ($countries->isEmpty()) {
            echo "No countries found. Creating a default country first...\n";
            $defaultCountry = Country::create([
                'id' => Str::uuid(),
                'name' => 'Belize',
                'code1' => 'BZ',
                'code2' => 'BLZ',
                'nationalityName' => 'Belizean',
            ]);
            $countryId = $defaultCountry->id;
        } else {
            $countryId = $countries->random()->id;
        }

        District::create([
            'id' => Str::uuid(),
            'name' => 'Cayo',
            'countryId' => $countryId,
        ]);
        
        echo "District 'Cayo' created successfully!\n";
    }
}
