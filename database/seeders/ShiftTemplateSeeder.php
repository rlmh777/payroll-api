<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\ShiftTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ShiftTemplateSeeder extends Seeder
{
    /**
     * Canonical shift catalog from Shifts.xlsx.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const ROWS = [
        ['2am-11am (w/ 1 hr. break)', 'Storeroom, Kitchen, Admin'],
        ['5am-1pm', 'Kitchen, Restaurant, Bar, Staff Kitchen'],
        ['5am-2pm (w/ 1 hr. break)', 'Front Desk, Storeroom, Admin'],
        ['5am-10am/5pm-10pm', 'Kitchen, Restaurant, Bar'],
        ['6am-10am', 'Guava Limb Café'],
        ['6am-12pm', 'Guava Limb Café'],
        ['6am-2pm', 'Guava Limb Café'],
        ['6am-3pm', 'Guava Limb Café'],
        ['6am-3pm (w/ 1 hr. break)', 'NHC/Butterfly Farm'],
        ['6am-11am/4pm-8pm', 'BRR'],
        ['7am-12pm', 'Housekeeping/Laundry, Facilities, Maintenance, Landscapers/Gardeners, Woodshop, Maya Farm'],
        ['7am-4pm', 'Kitchen, Staff Kitchen'],
        ['7am-4pm (w/ 1 hr. break)', 'Front Desk, Concierge, Housekeeping/Laundry, Facilities, NHC/Butterfly Farm, Stables, BRR, Gift Shop, Storeroom, Admin, House, Maintenance, Landscapers/Gardeners, Woodshop, Maya Farm, Security'],
        ['9am-2pm/6pm-10pm', 'Guava Limb Café'],
        ['10am-7pm', 'Restaurant, Bar'],
        ['10am-7pm (w/ 1 hr. break)', 'Storeroom'],
        ['10am-2pm/6pm-10pm', 'Guava Limb Café'],
        ['12pm-8pm', 'Guava Limb Café'],
        ['12:30pm-9pm', 'Staff Kitchen, Admin'],
        ['12:30pm-10pm', 'Kitchen, Restaurant, Bar'],
        ['1pm-9pm', 'Front Desk, Concierge, Housekeeping/Laundry, Maintenance'],
        ['1pm-10pm', 'NHC/Butterfly Farm'],
        ['2pm-10pm', 'Guava Limb Café'],
        ['3pm-1am (w/ 1 hr. break)', 'Security'],
        ['4pm-2am (w/ 1 hr. break)', 'Security'],
        ['6pm-6am', 'Security'],
        ['9pm-7am (w/ 1 hr. break)', 'Security'],
    ];

    /**
     * Map spreadsheet department labels onto seeded / preferred names.
     *
     * @var array<string, string>
     */
    private const DEPARTMENT_ALIASES = [
        'BRR' => 'Belize Rainforest Retreat',
        'NHC/Butterfly Farm' => 'Natural History Center',
        'Landscapers/Gardeners' => 'Gardeners',
        'Restaurant' => 'Dining',
        'Housekeeping/Laundry' => 'Housekeeping/Laundry',
        'Guava Limb Café' => 'Guava Limb Café',
        'Admin' => 'Admin',
        'Front Desk' => 'Front Desk',
        'Concierge' => 'Concierge',
        'Facilities' => 'Facilities',
        'Maintenance' => 'Maintenance',
        'Woodshop' => 'Woodshop',
        'Maya Farm' => 'Maya Farm',
        'Stables' => 'Stables',
        'Gift Shop' => 'Gift Shop',
        'House' => 'House',
        'Security' => 'Security',
        'Kitchen' => 'Kitchen',
        'Staff Kitchen' => 'Staff Kitchen',
        'Storeroom' => 'Storeroom',
        'Bar' => 'Bar',
    ];

    public function run(): void
    {
        foreach (self::ROWS as [$label, $departmentsCsv]) {
            $parsed = $this->parseShiftLabel($label);
            if ($parsed['segments'] === []) {
                continue;
            }

            $departmentIds = $this->resolveDepartmentIds($departmentsCsv);

            $template = ShiftTemplate::query()->firstOrNew(['name' => $parsed['name']]);
            if (! $template->exists) {
                $template->id = (string) Str::uuid();
            }

            $template->segments = $parsed['segments'];
            $template->include_lunch_hour = $parsed['include_lunch_hour'];
            $template->lunch_hour_hours = $parsed['lunch_hour_hours'];
            $template->is_active = true;
            $template->save();

            $template->departments()->sync($departmentIds);
        }
    }

    /**
     * @return array{
     *   name: string,
     *   segments: list<array{start_time: string, end_time: string}>,
     *   include_lunch_hour: bool,
     *   lunch_hour_hours: float
     * }
     */
    private function parseShiftLabel(string $label): array
    {
        $raw = trim($label);
        $includeLunch = (bool) preg_match('/\(w\/\s*1\s*hr\.?\s*break\)?/i', $raw);
        $name = trim(preg_replace('/\s*\(w\/.*?break\)?\s*/i', '', $raw) ?? $raw);
        if ($includeLunch) {
            $name = $name.' (w/ 1 hr. break)';
        }

        $segmentLabels = preg_split('#\s*/\s*#', preg_replace('/\s*\(w\/.*?break\)?\s*/i', '', $raw) ?? $raw) ?: [];
        $segments = [];

        foreach ($segmentLabels as $segmentLabel) {
            $segmentLabel = trim((string) $segmentLabel);
            if ($segmentLabel === '') {
                continue;
            }

            if (! preg_match('/^(.+?)\s*-\s*(.+)$/', $segmentLabel, $matches)) {
                continue;
            }

            $start = $this->parseClock($matches[1]);
            $end = $this->parseClock($matches[2]);
            if ($start === null || $end === null) {
                continue;
            }

            $segments[] = [
                'start_time' => $start,
                'end_time' => $end,
            ];
        }

        return [
            'name' => $name !== '' ? $name : $raw,
            'segments' => $segments,
            'include_lunch_hour' => $includeLunch,
            'lunch_hour_hours' => 1.0,
        ];
    }

    private function parseClock(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (! preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)$/', $value, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) ($matches[2] ?? 0);
        $meridiem = $matches[3];

        if ($hour < 1 || $hour > 12 || $minute > 59) {
            return null;
        }

        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        }
        if ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * @return list<int>
     */
    private function resolveDepartmentIds(string $csv): array
    {
        $ids = [];

        foreach (preg_split('/\s*,\s*/', $csv) ?: [] as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }

            $name = self::DEPARTMENT_ALIASES[$label] ?? $label;
            $department = Department::query()->firstOrCreate(
                ['name' => $name],
                ['parentId' => null],
            );
            $ids[] = (int) $department->id;
        }

        return array_values(array_unique($ids));
    }
}
