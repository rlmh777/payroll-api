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
        Country::create([
            'id'=> Str::uuid(),
            'name' => 'Belize',
            'code1' => 'BZ',
            'code2' =>  'BZE',
            'nationalityName' => 'Belizean',
        ]);
    }
}
