<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Country;
use Illuminate\Support\Str;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Fallback to static data if API fails
        $countries = [
            ['name' => 'Belize', 'code1' => 'BZ', 'code2' => 'BLZ', 'nationalityName' => 'Belizean'],
            ['name' => 'United States', 'code1' => 'US', 'code2' => 'USA', 'nationalityName' => 'American'],
            ['name' => 'Canada', 'code1' => 'CA', 'code2' => 'CAN', 'nationalityName' => 'Canadian'],
            ['name' => 'Mexico', 'code1' => 'MX', 'code2' => 'MEX', 'nationalityName' => 'Mexican'],
            ['name' => 'Guatemala', 'code1' => 'GT', 'code2' => 'GTM', 'nationalityName' => 'Guatemalan'],
            ['name' => 'Honduras', 'code1' => 'HN', 'code2' => 'HND', 'nationalityName' => 'Honduran'],
            ['name' => 'El Salvador', 'code1' => 'SV', 'code2' => 'SLV', 'nationalityName' => 'Salvadoran'],
            ['name' => 'Nicaragua', 'code1' => 'NI', 'code2' => 'NIC', 'nationalityName' => 'Nicaraguan'],
            ['name' => 'Costa Rica', 'code1' => 'CR', 'code2' => 'CRI', 'nationalityName' => 'Costa Rican'],
            ['name' => 'Panama', 'code1' => 'PA', 'code2' => 'PAN', 'nationalityName' => 'Panamanian'],
            ['name' => 'Jamaica', 'code1' => 'JM', 'code2' => 'JAM', 'nationalityName' => 'Jamaican'],
            ['name' => 'United Kingdom', 'code1' => 'GB', 'code2' => 'GBR', 'nationalityName' => 'British'],
            ['name' => 'Germany', 'code1' => 'DE', 'code2' => 'DEU', 'nationalityName' => 'German'],
            ['name' => 'France', 'code1' => 'FR', 'code2' => 'FRA', 'nationalityName' => 'French'],
            ['name' => 'Spain', 'code1' => 'ES', 'code2' => 'ESP', 'nationalityName' => 'Spanish'],
            ['name' => 'Italy', 'code1' => 'IT', 'code2' => 'ITA', 'nationalityName' => 'Italian'],
            ['name' => 'China', 'code1' => 'CN', 'code2' => 'CHN', 'nationalityName' => 'Chinese'],
            ['name' => 'Japan', 'code1' => 'JP', 'code2' => 'JPN', 'nationalityName' => 'Japanese'],
            ['name' => 'India', 'code1' => 'IN', 'code2' => 'IND', 'nationalityName' => 'Indian'],
            ['name' => 'Brazil', 'code1' => 'BR', 'code2' => 'BRA', 'nationalityName' => 'Brazilian'],
        ];

        try {
            // Try API first
            $url = 'https://restcountries.com/v3.1/all';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Mozilla/5.0 (compatible; Laravel Seeder)'
                ]
            ]);

            $response = @file_get_contents($url, false, $context);

            if ($response !== false) {
                $countriesData = json_decode($response, true);
                
                if ($countriesData && is_array($countriesData)) {
                    // Clear existing countries
                    Country::truncate();
                    
                    // Insert countries from API
                    foreach ($countriesData as $country) {
                        Country::create([
                            'id' => Str::uuid(),
                            'name' => $country['name']['common'] ?? 'N/A',
                            'code1' => $country['cca2'] ?? 'N/A',
                            'code2' => $country['cca3'] ?? 'N/A',
                            'nationalityName' => $country['demonyms']['eng']['m'] ?? 'N/A',
                        ]);
                    }
                    echo "Countries seeded successfully from API!\n";
                    return;
                }
            }
            
            // Fallback to static data
            echo "API failed, using fallback data for countries...\n";
            Country::truncate();
            
            foreach ($countries as $country) {
                Country::create([
                    'id' => Str::uuid(),
                    'name' => $country['name'],
                    'code1' => $country['code1'],
                    'code2' => $country['code2'],
                    'nationalityName' => $country['nationalityName'],
                ]);
            }
            
            echo "Countries seeded successfully with fallback data!\n";

        } catch (\Exception $e) {
            echo "Error while seeding countries: " . $e->getMessage() . "\n";
            
            // Final fallback - ensure at least one country exists
            if (Country::count() === 0) {
                Country::create([
                    'id' => Str::uuid(),
                    'name' => 'Belize',
                    'code1' => 'BZ',
                    'code2' => 'BLZ',
                    'nationalityName' => 'Belizean',
                ]);
                echo "Created fallback country: Belize\n";
            }
        }
    }
}
