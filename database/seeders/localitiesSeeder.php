<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Locality;
use App\Models\District;
use Illuminate\Support\Str;

class localitiesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Locality::create([
            'id'=> Str::uuid(),
            'name' => 'San Ignacio',
            'districtId' => District::all()->random()->id,
        ]);
    }
}
