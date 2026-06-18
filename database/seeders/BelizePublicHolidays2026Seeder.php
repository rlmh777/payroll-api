<?php

namespace Database\Seeders;

use App\Models\Calendar;
use App\Models\CalendarGroup;
use Illuminate\Database\Seeder;

class BelizePublicHolidays2026Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $holidays = [
            ['date' => '2026-01-01', 'description' => "New Year's Day"],
            ['date' => '2026-01-15', 'description' => 'George Price Day'],
            ['date' => '2026-03-09', 'description' => 'National Heroes and Benefactor Day'],
            ['date' => '2026-04-03', 'description' => 'Good Friday'],
            ['date' => '2026-04-04', 'description' => 'Holy Saturday'],
            ['date' => '2026-04-06', 'description' => 'Easter Monday'],
            ['date' => '2026-05-01', 'description' => 'Labour Day'],
            ['date' => '2026-08-01', 'description' => 'Emancipation Day'],
            ['date' => '2026-09-10', 'description' => "St. George's Caye Day"],
            ['date' => '2026-09-21', 'description' => 'Independence Day'],
            ['date' => '2026-10-12', 'description' => "Indigenous People's Resistance Day"],
            ['date' => '2026-11-19', 'description' => 'Garifuna Settlement Day'],
            ['date' => '2026-12-25', 'description' => 'Christmas Day'],
            ['date' => '2026-12-26', 'description' => 'Boxing Day'],
        ];

        $holidayGroup = CalendarGroup::where('key', 'holidays')->first();

        foreach ($holidays as $holiday) {
            Calendar::updateOrCreate(
                ['date' => $holiday['date'], 'description' => $holiday['description']],
                [
                    'type' => 'holiday',
                    'rate' => 1,
                    'calendar_group_id' => $holidayGroup?->id,
                ],
            );
        }
    }
}
