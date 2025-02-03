<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Locality;
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
            'districtId' => 'ce069ff2-dbc6-4bf3-857a-dee4557d938f',
        ]);
    }
}
