<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Honorific;

class HonorificSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Honorific::create([
            'name'=>'Mrs'
        ]);
    }
}
