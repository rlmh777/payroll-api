<?php

namespace Database\Seeders;

use App\Models\Degree;
use Illuminate\Database\Seeder;

class DegreeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $degrees = [
            'Associate',
            'Bachelor',
            'Master',
            'Doctorate',
            'Professional'
        ];

        foreach ($degrees as $degree) {
            Degree::create(['name' => $degree]);
        }
    }
}
