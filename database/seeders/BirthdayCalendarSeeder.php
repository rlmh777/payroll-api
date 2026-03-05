<?php

namespace Database\Seeders;

use App\Models\Calendar;
use App\Models\CalendarGroup;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class BirthdayCalendarSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $birthdayGroup = CalendarGroup::where('key', 'birthdays')->first();
        if (!$birthdayGroup) {
            return;
        }

        $year = (int) now()->format('Y');
        $employees = Employee::query()->get();

        foreach ($employees as $employee) {
            if (!$employee->birthdate) {
                continue;
            }

            $birth = Carbon::parse($employee->birthdate);

            if ($birth->month === 2 && $birth->day === 29 && !Carbon::create($year)->isLeapYear()) {
                $birthday = Carbon::create($year, 2, 28);
            } else {
                $birthday = Carbon::create($year, $birth->month, $birth->day);
            }

            $fullName = trim(sprintf('%s %s', $employee->firstName, $employee->lastName));
            $description = sprintf('Birthday - %s', $fullName);

            Calendar::updateOrCreate(
                [
                    'date' => $birthday->format('Y-m-d'),
                    'description' => $description,
                ],
                [
                    'type' => 'other',
                    'rate' => 1,
                    'calendar_group_id' => $birthdayGroup->id,
                ]
            );
        }
    }
}
