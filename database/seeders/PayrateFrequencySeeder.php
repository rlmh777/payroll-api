<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\PayrateFrequency;

class PayrateFrequencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PayrateFrequency::create([
            'name' => 'Monthly'
        ]);
    }
}
