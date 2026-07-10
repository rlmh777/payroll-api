<?php

namespace App\Services\Payroll;

use App\Models\Department;
use App\Models\PayPeriodSchedule;
use App\Models\PayrollEarningCode;
use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PayrollSummaryByDepartmentReportService
{
    public function __construct(
        private readonly PayrollRunCalculationService $payrollRunCalculationService,
        private readonly PayrollRunEarningLineBuilderService $payrollRunEarningLineBuilderService,
        private readonly PayrollRunFrequencyResolver $payrollRunFrequencyResolver,
        private readonly TimesheetGrossPayService $timesheetGrossPayService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRun $payrollRun): array
    {
        if (strtolower((string) $payrollRun->status) !== 'posted') {
            throw new InvalidArgumentException('Payroll summary by department is only available for processed payroll runs.');
        }

        $payrollRun->load(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency']);
        $schedule = $payrollRun->payPeriodSchedule;

        if (!$schedule?->start_date || !$schedule?->end_date) {
            throw new InvalidArgumentException('Pay period schedule is missing for this payroll run.');
        }

        if (!$payrollRun->earningLines()->exists()) {
            $summaryForBackfill = $this->payrollRunCalculationService->calculate($payrollRun, false, true);
            $this->payrollRunEarningLineBuilderService->syncForRun($payrollRun, $summaryForBackfill);
        }

        $summary = $this->payrollRunCalculationService->calculate($payrollRun, false, true);
        $rows = collect($summary['rows'] ?? []);

        if ($rows->isEmpty()) {
            throw new InvalidArgumentException('No payroll data is available for this payroll run.');
        }

        $employeeIds = $rows->pluck('employeeId')->map(fn ($id) => (string) $id);
        $frequencyId = $this->payrollRunFrequencyResolver->resolveForRun($payrollRun, $schedule);
        $timesheetEarnings = $this->timesheetGrossPayService->forEmployees(
            $employeeIds,
            $schedule->start_date,
            $schedule->end_date,
            (string) $schedule->pay_period_group_id,
            $frequencyId,
        );

        $earningCodes = PayrollEarningCode::query()
            ->whereIn('code', ['REGULAR', 'OVERTIME', 'ALLOWANCE'])
            ->get(['id', 'code'])
            ->pluck('code', 'id');

        $departmentIds = $rows
            ->pluck('departmentId')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $departments = $departmentIds->isEmpty()
            ? collect()
            : Department::query()->whereIn('id', $departmentIds)->get(['id', 'name'])->keyBy('id');

        $lineAggregates = PayrollEarningLine::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->selectRaw('"employeeId", "departmentId", payroll_earning_code_id, SUM(amount) as total_amount, SUM(COALESCE(hours, 0)) as total_hours')
            ->groupBy('employeeId', 'departmentId', 'payroll_earning_code_id')
            ->get()
            ->groupBy(fn (PayrollEarningLine $line) => $this->lineKey(
                (string) $line->employeeId,
                $line->departmentId !== null ? (int) $line->departmentId : null,
            ));

        $departmentRows = $rows
            ->map(function (array $row) use ($timesheetEarnings, $lineAggregates, $earningCodes) {
                $employeeId = (string) ($row['employeeId'] ?? '');
                $departmentId = isset($row['departmentId']) ? (int) $row['departmentId'] : null;
                $key = $this->lineKey($employeeId, $departmentId);
                $lines = $lineAggregates->get($key, collect());

                $regularAmount = 0.0;
                $overtimeAmount = 0.0;
                $allowances = 0.0;
                $overtimeHoursFromLines = 0.0;

                foreach ($lines as $line) {
                    $code = (string) ($earningCodes->get((int) $line->payroll_earning_code_id) ?? '');
                    $amount = round((float) ($line->total_amount ?? 0), 2);
                    $hours = round((float) ($line->total_hours ?? 0), 2);

                    if ($code === 'REGULAR') {
                        $regularAmount = round($regularAmount + $amount, 2);
                    } elseif ($code === 'OVERTIME') {
                        $overtimeAmount = round($overtimeAmount + $amount, 2);
                        $overtimeHoursFromLines = round($overtimeHoursFromLines + $hours, 2);
                    } elseif ($code === 'ALLOWANCE') {
                        $allowances = round($allowances + $amount, 2);
                    }
                }

                $timesheet = $timesheetEarnings[$employeeId] ?? [];
                $regularHours = round((float) ($timesheet['regularHours'] ?? 0), 2);
                $overtimeHours = round((float) ($row['overtimeHours'] ?? $overtimeHoursFromLines), 2);

                return [
                    'departmentId' => $departmentId,
                    'employeeId' => $employeeId,
                    'employeeCode' => (string) ($row['employeeCode'] ?? ''),
                    'employeeName' => (string) ($row['employeeName'] ?? 'Unknown employee'),
                    'regularHours' => $regularHours,
                    'regularAmount' => $regularAmount,
                    'overtimeHours' => $overtimeHours,
                    'overtimeAmount' => $overtimeAmount,
                    'doubleTimeHours' => 0.0,
                    'doubleTimeAmount' => 0.0,
                    'allowances' => round((float) ($row['taxableAllowances'] ?? 0) + (float) ($row['nonTaxableAllowances'] ?? 0), 2),
                    'grossPay' => round((float) ($row['grossPay'] ?? 0), 2),
                ];
            })
            ->groupBy(fn (array $row) => (string) ($row['departmentId'] ?? 'unassigned'))
            ->map(function (Collection $group, string $key) use ($departments) {
                $departmentId = $key === 'unassigned' ? null : (int) $key;
                $departmentName = $departmentId !== null
                    ? (string) ($departments->get($departmentId)?->name ?? 'Unassigned')
                    : 'Unassigned';

                $rows = $group
                    ->sortBy([['employeeCode', 'asc'], ['employeeName', 'asc']])
                    ->values()
                    ->all();

                return [
                    'departmentId' => $departmentId,
                    'departmentName' => $departmentName,
                    'rows' => $rows,
                    'totals' => [
                        'regularHours' => round((float) $group->sum('regularHours'), 2),
                        'regularAmount' => round((float) $group->sum('regularAmount'), 2),
                        'overtimeHours' => round((float) $group->sum('overtimeHours'), 2),
                        'overtimeAmount' => round((float) $group->sum('overtimeAmount'), 2),
                        'doubleTimeHours' => round((float) $group->sum('doubleTimeHours'), 2),
                        'doubleTimeAmount' => round((float) $group->sum('doubleTimeAmount'), 2),
                        'allowances' => round((float) $group->sum('allowances'), 2),
                        'grossPay' => round((float) $group->sum('grossPay'), 2),
                    ],
                ];
            })
            ->sortBy('departmentName')
            ->values()
            ->all();

        return [
            'payrollRunId' => (string) $payrollRun->id,
            'payPeriodGroupId' => (string) $schedule->pay_period_group_id,
            'payPeriodGroupName' => $schedule->payPeriodGroup?->name,
            'payPeriodStartDate' => $schedule->start_date->toDateString(),
            'payPeriodEndDate' => $schedule->end_date->toDateString(),
            'payPeriodNumber' => $this->resolvePayPeriodNumber($schedule),
            'departments' => $departmentRows,
        ];
    }

    private function resolvePayPeriodNumber(PayPeriodSchedule $schedule): int
    {
        $startDate = $schedule->start_date->copy()->startOfDay();

        return (int) PayPeriodSchedule::query()
            ->where('pay_period_group_id', $schedule->pay_period_group_id)
            ->whereYear('start_date', $startDate->year)
            ->whereDate('start_date', '<=', $startDate->toDateString())
            ->count();
    }

    private function lineKey(string $employeeId, ?int $departmentId): string
    {
        return "{$employeeId}|".($departmentId !== null ? (string) $departmentId : 'unassigned');
    }
}
