<?php

namespace Database\Seeders;

use App\Models\TimesheetTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TimesheetTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $daySchedules = [];
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $day) {
            $daySchedules[] = [
                'day' => $day,
                'start_time' => '08:00',
                'end_time' => '17:00',
                'include_lunch_hour' => true,
                'lunch_hour_hours' => 1,
                'department_id' => null,
            ];
        }

        $template = TimesheetTemplate::query()->firstOrNew(['name' => 'Default Template']);
        if (! $template->exists) {
            $template->id = (string) Str::uuid();
        }

        $template->is_active = true;
        $template->applyDaySchedules($daySchedules);
        $template->save();
    }
}
