<?php

namespace Database\Seeders;

use App\Models\EmploymentStatus;
use Illuminate\Database\Seeder;

class EmploymentStatusSeeder extends Seeder
{
    public function run(): void
    {
        // Working arrangement for this contract (hours, stage, leave — not agreement type).
        foreach ([
            'Full-Time',
            'Part-Time',
            'Probation',
            'On Leave',
            'Terminated',
        ] as $name) {
            EmploymentStatus::updateOrCreate(['name' => $name]);
        }
    }
}
