<?php

namespace App\Services\Payroll;

use Carbon\Carbon;

class PayPeriodHelper
{
    public static function countMondays(Carbon $startDate, Carbon $endDate): int
    {
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $cursor = $start->copy();
        if ($cursor->dayOfWeek !== Carbon::MONDAY) {
            $cursor->next(Carbon::MONDAY);
        }

        $count = 0;
        while ($cursor->lte($end)) {
            $count++;
            $cursor->addWeek();
        }

        return $count;
    }

    public static function periodsPerYear(?string $frequencyName): int
    {
        $normalized = strtolower(trim((string) $frequencyName));
        $map = config('payroll.periods_per_year', []);

        return (int) ($map[$normalized] ?? config('payroll.default_periods_per_year', 26));
    }
}
