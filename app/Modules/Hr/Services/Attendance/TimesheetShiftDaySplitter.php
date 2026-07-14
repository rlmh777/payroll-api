<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\OvernightShiftMode;
use Carbon\Carbon;

class TimesheetShiftDaySplitter
{
    /**
     * Build day segments for a worked interval using the department overnight-shift mode.
     *
     * @return array<int, array{
     *   date:string,
     *   start:Carbon,
     *   end:Carbon,
     *   seconds:float,
     *   hours:float
     * }>
     */
    public function buildSegments(Carbon $start, Carbon $end, OvernightShiftMode $mode): array
    {
        if ($end->lte($start)) {
            return [];
        }

        if ($mode->splitsAtMidnight()) {
            return $this->split($start, $end);
        }

        $date = match ($mode) {
            OvernightShiftMode::AttributeToClockOutDay => $end->toDateString(),
            default => $start->toDateString(),
        };
        $seconds = (float) $start->diffInSeconds($end, false);

        return [[
            'date' => $date,
            'start' => $start->copy(),
            'end' => $end->copy(),
            'seconds' => $seconds,
            'hours' => round($seconds / 3600, 2),
        ]];
    }

    /**
     * Split a worked interval at calendar-day boundaries (24-hour days).
     *
     * @return array<int, array{
     *   date:string,
     *   start:Carbon,
     *   end:Carbon,
     *   seconds:float,
     *   hours:float
     * }>
     */
    public function split(Carbon $start, Carbon $end): array
    {
        if ($end->lte($start)) {
            return [];
        }

        $segments = [];
        $cursor = $start->copy();

        while ($cursor->lt($end)) {
            $dayBoundary = $cursor->copy()->startOfDay()->addDay();
            $segmentEnd = $end->lt($dayBoundary) ? $end->copy() : $dayBoundary;
            $seconds = (float) $cursor->diffInSeconds($segmentEnd, false);

            if ($seconds > 0) {
                $segments[] = [
                    'date' => $cursor->toDateString(),
                    'start' => $cursor->copy(),
                    'end' => $segmentEnd->copy(),
                    'seconds' => $seconds,
                    'hours' => round($seconds / 3600, 2),
                ];
            }

            $cursor = $segmentEnd;
        }

        return $segments;
    }

    /**
     * @param array<int, array{seconds:float}> $segments
     */
    public function distributePayableSeconds(float $totalPayableSeconds, array $segments): array
    {
        $totalRawSeconds = round(array_sum(array_map(
            static fn (array $segment) => (float) ($segment['seconds'] ?? 0),
            $segments,
        )), 2);

        if ($totalRawSeconds <= 0 || $totalPayableSeconds <= 0) {
            return array_fill(0, count($segments), 0.0);
        }

        $distributed = [];
        $allocated = 0.0;

        foreach ($segments as $index => $segment) {
            $rawSeconds = (float) ($segment['seconds'] ?? 0);

            if ($index === array_key_last($segments)) {
                $distributed[] = round(max(0.0, $totalPayableSeconds - $allocated), 2);

                continue;
            }

            $share = round($totalPayableSeconds * ($rawSeconds / $totalRawSeconds), 2);
            $distributed[] = $share;
            $allocated += $share;
        }

        return $distributed;
    }
}
