<?php

namespace Database\Seeders;

use App\Models\EmployeeStatus;
use Illuminate\Database\Seeder;

class EmployeeStatusSeeder extends Seeder
{
    public function run(): void
    {
        // HR lifecycle for the person (not contract type or work arrangement).
        foreach ([
            'active',
            'inactive',
            'suspended',
            'fired',
            'retired',
        ] as $name) {
            EmployeeStatus::updateOrCreate(['name' => $name]);
        }
    }
}
