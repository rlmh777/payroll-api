<?php

namespace Database\Seeders;

use App\Models\CalendarGroup;
use Illuminate\Database\Seeder;

class CalendarGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $groups = [
            ['key' => 'general', 'name' => 'My Calendar', 'color' => '#1976D2'],
            ['key' => 'birthdays', 'name' => 'Birthdays', 'color' => '#9C27B0'],
            ['key' => 'leaves', 'name' => 'Leaves / Absent', 'color' => '#FB8C00'],
            ['key' => 'holidays', 'name' => 'Holidays', 'color' => '#26A69A'],
        ];

        foreach ($groups as $group) {
            CalendarGroup::updateOrCreate(
                ['key' => $group['key']],
                ['name' => $group['name'], 'color' => $group['color']],
            );
        }
    }
}
