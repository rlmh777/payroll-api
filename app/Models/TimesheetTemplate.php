<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimesheetTemplate extends Model
{
    use HasUuids;

    public const DAY_CODES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    protected $table = 'timesheet_template';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'days',
        'day_schedules',
        'is_active',
    ];

    protected $casts = [
        'days' => 'array',
        'day_schedules' => 'array',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'break_minutes',
    ];

    public function departments(): HasMany
    {
        return $this->hasMany(TimesheetTemplateDepartment::class, 'timesheet_template_id');
    }

    /**
     * @param array<int, array<string, mixed>> $daySchedules
     * @return array<int, array{day:string,start_time:string,end_time:string,include_lunch_hour:bool,department_id:?int}>
     */
    public static function normalizeDaySchedules(array $daySchedules): array
    {
        $normalized = [];

        foreach ($daySchedules as $schedule) {
            $day = (string) ($schedule['day'] ?? '');
            if (!in_array($day, self::DAY_CODES, true)) {
                continue;
            }

            $startTime = substr((string) ($schedule['start_time'] ?? ''), 0, 5);
            $endTime = substr((string) ($schedule['end_time'] ?? ''), 0, 5);

            if ($startTime === '' || $endTime === '') {
                continue;
            }

            $departmentId = $schedule['department_id'] ?? null;
            $departmentId = $departmentId === null || $departmentId === ''
                ? null
                : (int) $departmentId;

            $normalized[] = [
                'day' => $day,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'include_lunch_hour' => (bool) ($schedule['include_lunch_hour'] ?? false),
                'lunch_hour_hours' => (float) ($schedule['lunch_hour_hours'] ?? 1),
                'department_id' => $departmentId,
            ];
        }

        usort($normalized, function (array $left, array $right) {
            $dayCompare = array_search($left['day'], self::DAY_CODES, true)
                <=> array_search($right['day'], self::DAY_CODES, true);

            if ($dayCompare !== 0) {
                return $dayCompare;
            }

            return strcmp($left['start_time'], $right['start_time']);
        });

        return $normalized;
    }

    /**
     * @return array<int, array{day:string,start_time:string,end_time:string,include_lunch_hour:bool,department_id:?int}>
     */
    public function resolvedDaySchedules(): array
    {
        $daySchedules = is_array($this->day_schedules) ? $this->day_schedules : [];

        if ($daySchedules !== []) {
            return self::normalizeDaySchedules($daySchedules);
        }

        $days = is_array($this->days) ? $this->days : [];
        $legacySchedules = [];

        foreach ($days as $day) {
            $legacySchedules[] = [
                'day' => $day,
                'start_time' => substr((string) $this->start_time, 0, 5),
                'end_time' => substr((string) $this->end_time, 0, 5),
                'include_lunch_hour' => ((int) $this->break_minutes) > 0,
                'lunch_hour_hours' => max(1, (int) $this->break_minutes) / 60,
                'department_id' => null,
            ];
        }

        return self::normalizeDaySchedules($legacySchedules);
    }

    /**
     * @return array<int, array{day:string,start_time:string,end_time:string,include_lunch_hour:bool,department_id:?int}>
     */
    public function daySlotsFor(string $dayCode, ?int $departmentId = null): array
    {
        return array_values(array_filter(
            $this->resolvedDaySchedules(),
            function (array $schedule) use ($dayCode, $departmentId) {
                if ($schedule['day'] !== $dayCode) {
                    return false;
                }

                if ($departmentId === null) {
                    return $schedule['department_id'] === null;
                }

                return (int) $schedule['department_id'] === (int) $departmentId;
            }
        ));
    }

    public function dayScheduleFor(string $dayCode): ?array
    {
        return $this->daySlotsFor($dayCode)[0] ?? null;
    }

    /**
     * @param array<int, array<string, mixed>> $daySchedules
     */
    public function applyDaySchedules(array $daySchedules): void
    {
        $normalized = self::normalizeDaySchedules($daySchedules);
        $this->day_schedules = $normalized;
        $this->days = array_values(array_unique(array_map(
            fn (array $schedule) => $schedule['day'],
            $normalized
        )));

        if ($normalized === []) {
            return;
        }

        $this->start_time = $normalized[0]['start_time'];
        $this->end_time = $normalized[0]['end_time'];
        $this->break_minutes = collect($normalized)->contains(
            fn (array $schedule) => $schedule['include_lunch_hour'] === false
        ) ? max((int) $this->break_minutes, 60) : 0;
    }
}
