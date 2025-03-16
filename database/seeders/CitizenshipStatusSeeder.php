<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\CitizenshipStatus;


class CitizenshipStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        CitizenshipStatus::create([
            'name' => 'Belizean Citizen'
        ]);

    }
}
