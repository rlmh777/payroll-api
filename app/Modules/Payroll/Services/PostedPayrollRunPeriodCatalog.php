<?php

namespace App\Modules\Payroll\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Years/months that overlap at least one posted (processed) payroll run schedule.
 */
class PostedPayrollRunPeriodCatalog
{
    private const MONTH_NAMES = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ];

    /**
     * @return array{
     *   years: list<int>,
     *   monthsByYear: array<string, list<array{value: int, label: string}>>
     * }
     */
    public function available(): array
    {
        $schedules = DB::table('payroll_runs')
            ->join('pay_period_schedule', 'pay_period_schedule.id', '=', 'payroll_runs.pay_period_schedule_id')
            ->whereRaw('LOWER(payroll_runs.status) = ?', ['posted'])
            ->select([
                'pay_period_schedule.start_date',
                'pay_period_schedule.end_date',
            ])
            ->get();

        $monthsByYear = [];

        foreach ($schedules as $schedule) {
            $cursor = Carbon::parse($schedule->start_date)->startOfMonth();
            $end = Carbon::parse($schedule->end_date)->startOfMonth();

            while ($cursor->lte($end)) {
                $year = (int) $cursor->year;
                $month = (int) $cursor->month;
                $monthsByYear[$year][$month] = self::MONTH_NAMES[$month];
                $cursor->addMonth();
            }
        }

        krsort($monthsByYear);

        $years = array_map('intval', array_keys($monthsByYear));
        $serializedMonths = [];

        foreach ($monthsByYear as $year => $months) {
            krsort($months);
            $serializedMonths[(string) $year] = collect($months)
                ->map(fn (string $label, int $value) => [
                    'value' => $value,
                    'label' => $label,
                ])
                ->values()
                ->all();
        }

        return [
            'years' => $years,
            'monthsByYear' => $serializedMonths,
        ];
    }
}
