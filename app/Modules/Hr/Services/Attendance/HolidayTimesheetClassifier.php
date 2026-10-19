<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\CompensationMethod;
use App\Models\Timesheet;

class HolidayTimesheetClassifier
{
    public const DOUBLE_TIME_MULTIPLIER = 2.0;

    public static function receivesHolidayPremium(?string $payType): bool
    {
        return ! CompensationMethod::fromStored($payType)->isBaseBased();
    }

    public static function isDoubleTime(?float $configuredMultiplier): bool
    {
        return $configuredMultiplier !== null
            && abs($configuredMultiplier - self::DOUBLE_TIME_MULTIPLIER) < 0.001;
    }

    public static function storedHolidayHours(float $clockedHours, float $configuredMultiplier, ?string $payType): float
    {
        $clockedHours = round(max(0, $clockedHours), 2);
        if ($clockedHours <= 0) {
            return 0.0;
        }

        if (! self::receivesHolidayPremium($payType)) {
            return $clockedHours;
        }

        return round($clockedHours * max(0, $configuredMultiplier), 2);
    }

    public static function payMultiplier(?string $payType, ?float $configuredMultiplier): float
    {
        if ($configuredMultiplier === null) {
            return 0.0;
        }

        return self::receivesHolidayPremium($payType) ? max(0, $configuredMultiplier) : 1.0;
    }

    public static function clockedHours(Timesheet $timesheet, ?float $configuredMultiplier): float
    {
        $worked = round((float) ($timesheet->hoursWorked ?? 0), 2);
        if ($worked > 0) {
            return $worked;
        }

        $regular = (float) ($timesheet->regularHours ?? 0);
        $overtime = (float) ($timesheet->overtimeHours ?? 0);
        $holiday = (float) ($timesheet->holidayHours ?? 0);
        if ($holiday > 0 && $configuredMultiplier !== null && $configuredMultiplier > 1
            && self::receivesHolidayPremium($timesheet->payType)) {
            $holiday = round($holiday / $configuredMultiplier, 2);
        }

        return round(max(0, $regular + $overtime + $holiday), 2);
    }
}
