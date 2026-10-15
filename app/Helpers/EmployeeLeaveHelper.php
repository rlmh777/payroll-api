<?php

namespace App\Helpers;

use Carbon\Carbon;

class EmployeeLeaveHelper
{
    /**
     * Count working days in a date range (excluding weekends)
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return int
     */
    public static function countWorkingDays(Carbon $startDate, Carbon $endDate): int
    {
        $count = 0;
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            if ($currentDate->dayOfWeek !== Carbon::SATURDAY && $currentDate->dayOfWeek !== Carbon::SUNDAY) {
                $count++;
            }
            $currentDate->addDay();
        }
        
        return $count;
    }

    /**
     * Get all working days in a date range (excluding weekends)
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array Array of Carbon dates
     */
    public static function getWorkingDays(Carbon $startDate, Carbon $endDate): array
    {
        $workingDays = [];
        $currentDate = $startDate->copy();
        
        while ($currentDate->lte($endDate)) {
            if ($currentDate->dayOfWeek !== Carbon::SATURDAY && $currentDate->dayOfWeek !== Carbon::SUNDAY) {
                $workingDays[] = $currentDate->copy();
            }
            $currentDate->addDay();
        }
        
        return $workingDays;
    }

    /**
     * Check if working days in the date range are consecutive (no weekends in between)
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return bool
     */
    public static function areWorkingDaysConsecutive(Carbon $startDate, Carbon $endDate): bool
    {
        $workingDays = self::getWorkingDays($startDate, $endDate);
        
        // If only one working day, it's considered consecutive
        if (count($workingDays) <= 1) {
            return true;
        }
        
        // Check if all working days are consecutive (no gaps)
        for ($i = 0; $i < count($workingDays) - 1; $i++) {
            $currentDay = $workingDays[$i];
            $nextDay = $workingDays[$i + 1];
            
            // Check if next day is exactly one day after current day
            // (accounting for weekends that might be skipped)
            $expectedNextDay = $currentDay->copy()->addDay();
            
            // Skip weekends to find the next expected working day
            while ($expectedNextDay->dayOfWeek === Carbon::SATURDAY || $expectedNextDay->dayOfWeek === Carbon::SUNDAY) {
                $expectedNextDay->addDay();
            }
            
            // If the actual next working day doesn't match the expected one, they're not consecutive
            if (!$expectedNextDay->isSameDay($nextDay)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Check if two time ranges overlap
     *
     * @param string|null $fromTime1
     * @param string|null $toTime1
     * @param string|null $fromTime2
     * @param string|null $toTime2
     * @return bool
     */
    public static function doTimesOverlap(?string $fromTime1, ?string $toTime1, ?string $fromTime2, ?string $toTime2): bool
    {
        // If either time range is null, consider it as full day overlap
        if (!$fromTime1 || !$toTime1 || !$fromTime2 || !$toTime2) {
            return true;
        }

        // Normalize time formats
        $fromTime1Normalized = strlen($fromTime1) === 5 ? $fromTime1 . ':00' : $fromTime1;
        $toTime1Normalized = strlen($toTime1) === 5 ? $toTime1 . ':00' : $toTime1;
        $fromTime2Normalized = strlen($fromTime2) === 5 ? $fromTime2 . ':00' : $fromTime2;
        $toTime2Normalized = strlen($toTime2) === 5 ? $toTime2 . ':00' : $toTime2;

        $from1 = Carbon::createFromFormat('H:i:s', $fromTime1Normalized);
        $to1 = Carbon::createFromFormat('H:i:s', $toTime1Normalized);
        $from2 = Carbon::createFromFormat('H:i:s', $fromTime2Normalized);
        $to2 = Carbon::createFromFormat('H:i:s', $toTime2Normalized);

        // Handle case where toTime is before fromTime (next day)
        if ($to1->lt($from1)) {
            $to1->addDay();
        }
        if ($to2->lt($from2)) {
            $to2->addDay();
        }

        // Check if time ranges overlap
        // Two ranges overlap if: start1 < end2 AND start2 < end1
        return $from1->lt($to2) && $from2->lt($to1);
    }

    /**
     * Check if two leave periods overlap (considering dates and times)
     *
     * @param string $startDate1
     * @param string $endDate1
     * @param string $duration1
     * @param string|null $fromTime1
     * @param string|null $toTime1
     * @param string $startDate2
     * @param string $endDate2
     * @param string $duration2
     * @param string|null $fromTime2
     * @param string|null $toTime2
     * @return bool
     */
    public static function doLeavesOverlap(
        string $startDate1,
        string $endDate1,
        string $duration1,
        ?string $fromTime1,
        ?string $toTime1,
        string $startDate2,
        string $endDate2,
        string $duration2,
        ?string $fromTime2,
        ?string $toTime2
    ): bool {
        $start1 = Carbon::parse($startDate1);
        $end1 = Carbon::parse($endDate1);
        $start2 = Carbon::parse($startDate2);
        $end2 = Carbon::parse($endDate2);

        // Check if date ranges overlap
        // Two date ranges overlap if: start1 <= end2 AND start2 <= end1
        if ($start1->gt($end2) || $start2->gt($end1)) {
            return false; // No date overlap
        }

        // If dates overlap, check times based on duration
        $isFullDay1 = in_array($duration1, ['Full Day', 'All Days']);
        $isFullDay2 = in_array($duration2, ['Full Day', 'All Days']);

        // If either is a full day, they overlap (since dates already overlap)
        if ($isFullDay1 || $isFullDay2) {
            return true;
        }

        // For partial days, check if times overlap on any overlapping date
        // Get the overlapping date range
        $overlapStart = $start1->gt($start2) ? $start1 : $start2;
        $overlapEnd = $end1->lt($end2) ? $end1 : $end2;

        // Check each day in the overlap range
        $currentDate = $overlapStart->copy();
        while ($currentDate->lte($overlapEnd)) {
            // Skip weekends
            if ($currentDate->dayOfWeek !== Carbon::SATURDAY && $currentDate->dayOfWeek !== Carbon::SUNDAY) {
                // Check if times overlap for this day
                if (self::doTimesOverlap($fromTime1, $toTime1, $fromTime2, $toTime2)) {
                    return true;
                }
            }
            $currentDate->addDay();
        }

        return false;
    }

    /**
     * Calculate totalDays based on duration
     *
     * @param string $duration
     * @param string|null $fromTime
     * @param string|null $toTime
     * @return float
     */
    public static function calculateTotalDaysForDuration(string $duration, ?string $fromTime, ?string $toTime): float
    {
        switch ($duration) {
            case 'Full Day':
            case 'All Days':
                return 1.0;
            
            case 'Morning':
            case 'Afternoon':
                return 0.5;
            
            case 'Custom':
                if ($fromTime && $toTime) {
                    // Normalize time format to HH:mm:ss
                    $fromTimeNormalized = strlen($fromTime) === 5 ? $fromTime . ':00' : $fromTime;
                    $toTimeNormalized = strlen($toTime) === 5 ? $toTime . ':00' : $toTime;
                    
                    // Parse times and calculate hours
                    $from = Carbon::createFromFormat('H:i:s', $fromTimeNormalized);
                    $to = Carbon::createFromFormat('H:i:s', $toTimeNormalized);
                    
                    // Handle case where toTime is before fromTime (next day)
                    if ($to->lt($from)) {
                        $to->addDay();
                    }
                    
                    // Calculate difference in minutes for more precision
                    $minutes = $from->diffInMinutes($to);
                    $hours = $minutes / 60.0;
                    return $hours / 8.0; // Assuming 8 hours = 1 day
                }
                return 0.0;
            
            default:
                return 1.0;
        }
    }
}

