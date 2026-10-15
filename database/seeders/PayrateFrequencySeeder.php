<?php

namespace Database\Seeders;

use App\Models\PayrateFrequency;
use Illuminate\Database\Seeder;

class PayrateFrequencySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Monthly', 'Biweekly'] as $name) {
            PayrateFrequency::updateOrCreate(['name' => $name]);
        }
    }
}
