<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\District;
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
            'countryId' => 'e84c2f37-355e-4a0f-8917-be23b5922cd9',
        ]);
    }
}
