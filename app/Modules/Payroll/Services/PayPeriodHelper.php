<?php

namespace App\Modules\Payroll\Services;

use App\Models\PayPeriodSchedule;
use App\Models\PayrollRun;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Collection;

class PayPeriodHelper
{
    /**
     * Payroll run period pickers may include past/current periods and at most one period
     * that starts after today.
     *
     * @param  iterable<int, object|array<string, mixed>>  $periods
     * @return list<object|array<string, mixed>>
     */
    public static function keepCurrentAndOneAhead(iterable $periods, DateTimeInterface|string|null $today = null): array
    {
        $today = Carbon::parse($today ?? now())->startOfDay();
        $sorted = collect($periods)
            ->sortBy(fn ($period) => (string) self::periodStartDate($period)?->toDateString())
            ->values();

        $currentAndPast = $sorted->filter(function ($period) use ($today) {
            $start = self::periodStartDate($period);

            return $start instanceof CarbonInterface && $start->lte($today);
        });

        $nextAhead = $sorted->first(function ($period) use ($today) {
            $start = self::periodStartDate($period);

            return $start instanceof CarbonInterface && $start->gt($today);
        });

        return $nextAhead === null
            ? $currentAndPast->values()->all()
            : $currentAndPast->push($nextAhead)->values()->all();
    }

    /**
     * Draft payroll runs that are still open for allowance entry: current or next pay period,
     * excluding processed runs and stale draft runs for past periods.
     *
     * @return Collection<int, PayrollRun>
     */
    public static function upcomingEditablePayrollRuns(DateTimeInterface|string|null $today = null): Collection
    {
        $today = Carbon::parse($today ?? now())->startOfDay();

        $draftRuns = PayrollRun::query()
            ->with(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency'])
            ->whereRaw('LOWER(status) = ?', ['draft'])
            ->get()
            ->filter(fn (PayrollRun $run) => $run->payPeriodSchedule !== null);

        if ($draftRuns->isEmpty()) {
            return collect();
        }

        $groupIds = $draftRuns
            ->map(fn (PayrollRun $run) => $run->payPeriodSchedule?->pay_period_group_id)
            ->filter()
            ->unique()
            ->values();

        $schedulesByGroup = PayPeriodSchedule::query()
            ->whereIn('pay_period_group_id', $groupIds)
            ->get()
            ->groupBy('pay_period_group_id');

        return $draftRuns
            ->filter(function (PayrollRun $run) use ($schedulesByGroup, $today) {
                $schedule = $run->payPeriodSchedule;
                if (! $schedule) {
                    return false;
                }

                $end = self::periodEndDate($schedule);
                if (! $end instanceof CarbonInterface || $end->lt($today)) {
                    return false;
                }

                $groupSchedules = $schedulesByGroup->get($schedule->pay_period_group_id, collect());
                $horizonIds = collect(self::keepCurrentAndOneAhead($groupSchedules, $today))
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id);

                return $horizonIds->contains((string) $schedule->id);
            })
            ->sortBy(fn (PayrollRun $run) => (string) self::periodStartDate($run->payPeriodSchedule)?->toDateString())
            ->values();
    }

    public static function canGenerateAnotherFutureSchedule(string $payPeriodGroupId, DateTimeInterface|string|null $today = null): bool
    {
        $today = Carbon::parse($today ?? now())->startOfDay();

        $futureCount = PayPeriodSchedule::query()
            ->where('pay_period_group_id', $payPeriodGroupId)
            ->whereDate('start_date', '>', $today->toDateString())
            ->count();

        return $futureCount < 1;
    }

    private static function periodStartDate(object|array $period): ?Carbon
    {
        $value = is_array($period)
            ? ($period['start_date'] ?? $period['startDate'] ?? null)
            : ($period->start_date ?? $period->startDate ?? null);

        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

    private static function periodEndDate(object|array $period): ?Carbon
    {
        $value = is_array($period)
            ? ($period['end_date'] ?? $period['endDate'] ?? null)
            : ($period->end_date ?? $period->endDate ?? null);

        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

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
