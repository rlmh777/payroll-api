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
        // API endpoint for fetching country data
        $url = 'https://restcountries.com/v3.1/all';

        try {
            // Fetch JSON data from API
            $response = file_get_contents($url);

            if ($response === false) {
                throw new \Exception("Failed to fetch data from API.");
            }

            // Decode JSON response
            $countriesData = json_decode($response, true);

            if (!$countriesData) {
                throw new \Exception("Invalid JSON response.");
            }

            // Loop through API response and insert each country
            foreach ($countriesData as $country) {
                Country::create([
                    'id' => Str::uuid(),
                    'name' => $country['name']['common'] ?? 'N/A',
                    'code1' => $country['cca2'] ?? 'N/A',
                    'code2' => $country['cca3'] ?? 'N/A',
                    'nationalityName' => $country['demonyms']['eng']['m'] ?? 'N/A',
                ]);
            }

            echo "Countries seeded successfully!\n";

        } catch (\Exception $e) {
            echo "Error while seeding countries: " . $e->getMessage() . "\n";
        }
    }
}
