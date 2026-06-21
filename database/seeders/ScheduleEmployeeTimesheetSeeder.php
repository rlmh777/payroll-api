<?php

namespace Database\Seeders;

use App\Models\CalendarGroup;
use App\Models\Employee;
use App\Models\ScheduleEmployeeTimesheet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ScheduleEmployeeTimesheetSeeder extends Seeder
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

        $scheduleGroup = CalendarGroup::where('key', 'schedules')->first();

        foreach ($employees as $employee) {
            for ($i = 0; $i < 3; $i++) {
                ScheduleEmployeeTimesheet::updateOrCreate(
                    [
                        'employeeId' => $employee->id,
                        'date' => now()->addDays($i)->format('Y-m-d'),
                    ],
                    [
                        'id' => Str::uuid(),
                        'calendar_group_id' => $scheduleGroup?->id,
                        'startTime' => '08:00:00',
                        'endTime' => '17:00:00',
                        'approvalStatus' => $i === 0 ? 'pending' : 'approved',
                    ]
                );
            }
        }
    }
}
