<?php

namespace App\Modules\Hr\Services\Attendance;

class LunchBreakHelper
{
    public static function breakMinutes(bool $includeLunchHour, mixed $lunchHourHours = null): int
    {
        if (!$includeLunchHour) {
            return 0;
        }

        $hours = max(0, (float) ($lunchHourHours ?? 1));

        return (int) round($hours * 60);
    }

    /**
     * @param array{include_lunch_hour?:bool, lunch_hour_hours?:float|int|string|null} $schedule
     */
    public static function breakMinutesFromSchedule(array $schedule): int
    {
        return self::breakMinutes(
            (bool) ($schedule['include_lunch_hour'] ?? false),
            $schedule['lunch_hour_hours'] ?? 1,
        );
    }

    public static function applyBreakToHours(float $hours, int $breakMinutes): float
    {
        if ($breakMinutes <= 0) {
            return round($hours, 2);
        }

        return round(max(0, $hours - ($breakMinutes / 60)), 2);
    }
}
