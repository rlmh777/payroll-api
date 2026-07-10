<?php

namespace Database\Seeders;

use App\Models\EmploymentDetail;
use App\Models\ScheduledWork;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SchedulerSeeder extends Seeder
{
    public function run(): void
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(4);

        $employmentDetails = EmploymentDetail::query()
            ->with(['employee', 'department', 'worksite'])
            ->where('isActive', true)
            ->orderBy('employeeId')
            ->get();

        if ($employmentDetails->isEmpty()) {
            $this->command?->warn('SchedulerSeeder skipped: no active employment details.');
            return;
        }

        $shiftTemplates = [
            ['start' => '08:00', 'end' => '16:00', 'description' => 'Morning shift'],
            ['start' => '09:00', 'end' => '17:00', 'description' => 'Day shift'],
            ['start' => '10:00', 'end' => '18:00', 'description' => 'Late shift'],
        ];

        foreach ($employmentDetails->take(40) as $index => $detail) {
            if (!$detail->employee) {
                continue;
            }

            $template = $shiftTemplates[$index % count($shiftTemplates)];

            for ($date = $weekStart->copy(); $date->lte($weekEnd); $date->addDay()) {
                $day = $date->format('Y-m-d');

                $exists = ScheduledWork::query()
                    ->where('employeeId', $detail->employeeId)
                    ->whereDate('startDate', '<=', $day)
                    ->whereDate('endDate', '>=', $day)
                    ->exists();

                if ($exists) {
                    continue;
                }

                ScheduledWork::create([
                    'id' => (string) Str::uuid(),
                    'startDate' => $day,
                    'endDate' => $day,
                    'startTime' => $template['start'],
                    'endTime' => $template['end'],
                    'employeeId' => $detail->employeeId,
                    'departmentId' => $detail->departmentId,
                    'worksiteId' => $detail->worksiteId,
                    'description' => $template['description'],
                    'rate' => 1,
                    'includeLunchHour' => fake()->boolean(25),
                ]);
            }
        }

        $this->command?->info('SchedulerSeeder created work shifts for the current week.');
    }
}
