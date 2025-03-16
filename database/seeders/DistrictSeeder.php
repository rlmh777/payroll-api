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
        District::create([
            'id'=> Str::uuid(),
            'name' => 'Cayo',
            'countryId' => Country::all()->random()->id,
        ]);
    }
}
