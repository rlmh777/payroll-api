<?php

namespace Database\Seeders;

use App\Models\EmployeeStatus;
use Illuminate\Database\Seeder;

class EmployeeStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $employeeStatuses = [
            ['name' => 'active'],
            ['name' => 'fired'],
            ['name' => 'suspended'],
            ['name' => 'retired'],
        ];

        foreach ($employeeStatuses as $employeeStatus) {
            EmployeeStatus::create($employeeStatus);
        }
    }
}
