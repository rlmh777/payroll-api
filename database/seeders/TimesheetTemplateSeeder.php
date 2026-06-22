<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\TimesheetTemplate;
use App\Models\TimesheetTemplateDepartment;
use Illuminate\Database\Seeder;

class TimesheetTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

        $weekdaySchedulesForDepartment = function (
            string $start,
            string $end,
            bool $includeLunchHour,
            int $departmentId,
        ) use ($weekdays): array {
            return array_map(
                fn (string $day) => [
                    'day' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'include_lunch_hour' => $includeLunchHour,
                    'department_id' => $departmentId,
                ],
                $weekdays,
            );
        };

        $departments = Department::all();

        $defaultSchedules = $departments->flatMap(
            fn (Department $department) => $weekdaySchedulesForDepartment('08:00', '17:00', false, $department->id),
        )->values()->all();

        $defaultTemplate = TimesheetTemplate::updateOrCreate(
            ['name' => 'Default Template'],
            [
                'start_time' => '08:00',
                'end_time' => '17:00',
                'break_minutes' => 60,
                'days' => $weekdays,
                'day_schedules' => $defaultSchedules,
                'is_active' => true,
            ],
        );

        $operations = Department::where('name', 'Operations')->first();
        $sales = Department::where('name', 'Sales')->first();

        if ($operations) {
            TimesheetTemplate::updateOrCreate(
                ['name' => 'Morning Shift Template'],
                [
                    'start_time' => '06:00',
                    'end_time' => '14:00',
                    'break_minutes' => 30,
                    'days' => $weekdays,
                    'day_schedules' => $weekdaySchedulesForDepartment('06:00', '14:00', false, $operations->id),
                    'is_active' => true,
                ],
            );
        }

        if ($sales) {
            TimesheetTemplate::updateOrCreate(
                ['name' => 'Evening Shift Template'],
                [
                    'start_time' => '14:00',
                    'end_time' => '22:00',
                    'break_minutes' => 30,
                    'days' => $weekdays,
                    'day_schedules' => $weekdaySchedulesForDepartment('14:00', '22:00', false, $sales->id),
                    'is_active' => true,
                ],
            );
        }

        Department::whereDoesntHave('timesheetTemplateAssignments')->each(function (Department $department) use ($defaultTemplate) {
            TimesheetTemplateDepartment::create([
                'timesheet_template_id' => $defaultTemplate->id,
                'department_id' => $department->id,
                'effective_date' => now()->toDateString(),
            ]);
        });
    }
}
