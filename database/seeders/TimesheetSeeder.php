<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Timesheet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TimesheetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $employees = Employee::query()->get();
        if ($employees->isEmpty()) {
            return;
        }

        foreach ($employees as $employee) {
            for ($i = 0; $i < 5; $i++) {
                Timesheet::updateOrCreate(
                    [
                        'employeeId' => $employee->id,
                        'date' => now()->subDays($i)->format('Y-m-d'),
                    ],
                    [
                        'id' => Str::uuid(),
                        'hoursWorked' => 8,
                        'approvalStatus' => $i % 2 === 0 ? 'PENDING' : 'APPROVED',
                    ]
                );
            }
        }
    }
}
