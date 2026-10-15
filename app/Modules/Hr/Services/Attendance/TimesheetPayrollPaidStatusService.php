<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Models\Payroll;
use App\Models\Timesheet;
use Illuminate\Support\Collection;

class TimesheetPayrollPaidStatusService
{
    /** @var array<string, bool> */
    private array $paidCache = [];

    /** @var array<string, array{payrollRunId:?string, payDate:?string, payrollId:?string}> */
    private array $paymentInfoCache = [];

    /**
     * Prefetch posted-payroll coverage for a timesheet collection.
     *
     * @param Collection<int, Timesheet> $timesheets
     */
    public function warmCache(Collection $timesheets): void
    {
        if ($timesheets->isEmpty()) {
            return;
        }

        $employeeIds = $timesheets
            ->pluck('employeeId')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        $dates = $timesheets
            ->map(fn (Timesheet $timesheet) => $timesheet->date?->toDateString())
            ->filter()
            ->unique()
            ->values();

        if ($employeeIds->isEmpty() || $dates->isEmpty()) {
            return;
        }

        $minDate = $dates->min();
        $maxDate = $dates->max();

        $payrolls = Payroll::query()
            ->select([
                'payroll.id',
                'payroll.employeeId',
                'payroll.payroll_run_id',
                'payroll.paymentMethodId',
                'payroll.accountNumber',
                'payroll_runs.status as payroll_run_status',
                'pay_period_schedule.start_date',
                'pay_period_schedule.end_date',
                'pay_period_schedule.pay_date',
            ])
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll.payroll_run_id')
            ->join('pay_period_schedule', 'pay_period_schedule.id', '=', 'payroll_runs.pay_period_schedule_id')
            ->whereIn('payroll.employeeId', $employeeIds->all())
            ->whereRaw('LOWER(payroll_runs.status) = ?', ['posted'])
            ->whereDate('pay_period_schedule.start_date', '<=', $maxDate)
            ->whereDate('pay_period_schedule.end_date', '>=', $minDate)
            ->get();

        foreach ($timesheets as $timesheet) {
            $key = $this->cacheKey($timesheet);
            if ($key === null || array_key_exists($key, $this->paidCache)) {
                continue;
            }

            $workDate = $timesheet->date?->toDateString();
            $employeeId = (string) $timesheet->employeeId;

            $match = $payrolls->first(function ($payroll) use ($employeeId, $workDate) {
                if ((string) $payroll->employeeId !== $employeeId) {
                    return false;
                }

                $start = $payroll->start_date
                    ? (is_string($payroll->start_date) ? $payroll->start_date : $payroll->start_date->toDateString())
                    : null;
                $end = $payroll->end_date
                    ? (is_string($payroll->end_date) ? $payroll->end_date : $payroll->end_date->toDateString())
                    : null;

                if (!$workDate || !$start || !$end) {
                    return false;
                }

                return $workDate >= $start && $workDate <= $end;
            });

            $this->paidCache[$key] = $match !== null;
            $this->paymentInfoCache[$key] = [
                'payrollRunId' => $match?->payroll_run_id ? (string) $match->payroll_run_id : null,
                'payrollId' => $match?->id ? (string) $match->id : null,
                'payDate' => $match?->pay_date
                    ? (is_string($match->pay_date) ? $match->pay_date : $match->pay_date->toDateString())
                    : null,
            ];
        }
    }

    public function hasBeenPaid(Timesheet $timesheet): bool
    {
        $key = $this->cacheKey($timesheet);
        if ($key !== null && array_key_exists($key, $this->paidCache)) {
            return $this->paidCache[$key];
        }

        $this->warmCache(collect([$timesheet]));

        return $key !== null && ($this->paidCache[$key] ?? false);
    }

    /**
     * @return array{payrollRunId:?string, payDate:?string, payrollId:?string}
     */
    public function paymentInfo(Timesheet $timesheet): array
    {
        $key = $this->cacheKey($timesheet);
        if ($key === null) {
            return ['payrollRunId' => null, 'payDate' => null, 'payrollId' => null];
        }

        if (!array_key_exists($key, $this->paymentInfoCache)) {
            $this->warmCache(collect([$timesheet]));
        }

        return $this->paymentInfoCache[$key] ?? [
            'payrollRunId' => null,
            'payDate' => null,
            'payrollId' => null,
        ];
    }

    private function cacheKey(Timesheet $timesheet): ?string
    {
        $workDate = $timesheet->date?->toDateString();
        if (!$workDate || !$timesheet->employeeId) {
            return null;
        }

        return (string) $timesheet->employeeId.'|'.$workDate;
    }
}
