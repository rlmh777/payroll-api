<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\WorkTimesheet;
use App\Models\WorkTimesheetDepartment;
use Illuminate\Database\Seeder;

class WorkTimesheetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultTimesheet = WorkTimesheet::updateOrCreate(
            ['name' => 'Default Timesheet'],
            [
                'start_time' => '08:00',
                'end_time' => '17:00',
                'break_minutes' => 60,
                'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'is_active' => true,
            ],
        );

        WorkTimesheet::updateOrCreate(
            ['name' => 'Morning Shift'],
            [
                'start_time' => '06:00',
                'end_time' => '14:00',
                'break_minutes' => 30,
                'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'is_active' => true,
            ],
        );

        WorkTimesheet::updateOrCreate(
            ['name' => 'Evening Shift'],
            [
                'start_time' => '14:00',
                'end_time' => '22:00',
                'break_minutes' => 30,
                'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'is_active' => true,
            ],
        );

        Department::whereDoesntHave('workTimesheetAssignments')->each(function (Department $department) use ($defaultTimesheet) {
            WorkTimesheetDepartment::create([
                'work_timesheet_id' => $defaultTimesheet->id,
                'department_id' => $department->id,
                'effective_date' => now()->toDateString(),
            ]);
        });
    }
}
