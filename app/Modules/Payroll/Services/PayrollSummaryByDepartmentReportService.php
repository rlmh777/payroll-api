<?php

namespace App\Modules\Payroll\Services;

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

        $lineAggregates = PayrollEarningLine::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->selectRaw('"employeeId", "departmentId", payroll_earning_code_id, SUM(amount) as total_amount, SUM(COALESCE(hours, 0)) as total_hours')
            ->groupBy('employeeId', 'departmentId', 'payroll_earning_code_id')
            ->get();

        $usedCodeIds = $lineAggregates
            ->pluck('payroll_earning_code_id')
            ->unique()
            ->values();

        $earningCodes = PayrollEarningCode::query()
            ->whereIn('id', $usedCodeIds)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'source', 'sort_order']);

        $earningColumns = $earningCodes
            ->map(fn (PayrollEarningCode $code) => [
                'id' => $code->id,
                'key' => (string) $code->id,
                'name' => trim((string) $code->name) !== '' ? (string) $code->name : (string) $code->code,
                'source' => $code->source,
                'showHours' => in_array($code->source, [
                    PayrollEarningCode::SOURCE_TIMESHEET_REGULAR,
                    PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME,
                    PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
                    PayrollEarningCode::SOURCE_VACATION,
                ], true),
            ])
            ->values()
            ->all();

        $codesById = $earningCodes->keyBy('id');

        $departmentIds = $rows
            ->pluck('departmentId')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $departments = $departmentIds->isEmpty()
            ? collect()
            : Department::query()->whereIn('id', $departmentIds)->get(['id', 'name'])->keyBy('id');

        $linesByEmployee = $lineAggregates->groupBy(fn (PayrollEarningLine $line) => $this->lineKey(
            (string) $line->employeeId,
            $line->departmentId !== null ? (int) $line->departmentId : null,
        ));

        $departmentRows = $rows
            ->map(function (array $row) use ($timesheetEarnings, $linesByEmployee, $codesById, $earningColumns) {
                $employeeId = (string) ($row['employeeId'] ?? '');
                $departmentId = isset($row['departmentId']) ? (int) $row['departmentId'] : null;
                $key = $this->lineKey($employeeId, $departmentId);
                $lines = $linesByEmployee->get($key, collect());
                $timesheet = $timesheetEarnings[$employeeId] ?? [];

                $hours = [];
                $amounts = [];
                foreach ($earningColumns as $column) {
                    $hours[$column['key']] = 0.0;
                    $amounts[$column['key']] = 0.0;
                }

                foreach ($lines as $line) {
                    $codeKey = (string) $line->payroll_earning_code_id;
                    $amounts[$codeKey] = round((float) ($amounts[$codeKey] ?? 0) + (float) ($line->total_amount ?? 0), 2);
                    $hours[$codeKey] = round((float) ($hours[$codeKey] ?? 0) + (float) ($line->total_hours ?? 0), 2);
                }

                foreach ($codesById as $code) {
                    $codeKey = (string) $code->id;
                    if ($code->source === PayrollEarningCode::SOURCE_TIMESHEET_REGULAR && ($hours[$codeKey] ?? 0) <= 0) {
                        $hours[$codeKey] = round((float) ($timesheet['regularHours'] ?? 0), 2);
                    }
                    if ($code->source === PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME && ($hours[$codeKey] ?? 0) <= 0) {
                        $hours[$codeKey] = round((float) ($row['overtimeHours'] ?? 0), 2);
                    }
                    if ($code->source === PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY && ($hours[$codeKey] ?? 0) <= 0) {
                        $hours[$codeKey] = round((float) ($row['holidayHours'] ?? $timesheet['holidayHours'] ?? 0), 2);
                    }
                }

                return [
                    'departmentId' => $departmentId,
                    'employeeId' => $employeeId,
                    'employeeCode' => (string) ($row['employeeCode'] ?? ''),
                    'employeeName' => (string) ($row['employeeName'] ?? 'Unknown employee'),
                    'hours' => $hours,
                    'amounts' => $amounts,
                    'grossPay' => round((float) ($row['grossPay'] ?? 0), 2),
                ];
            })
            ->groupBy(fn (array $row) => (string) ($row['departmentId'] ?? 'unassigned'))
            ->map(function (Collection $group, string $key) use ($departments, $earningColumns) {
                $departmentId = $key === 'unassigned' ? null : (int) $key;
                $departmentName = $departmentId !== null
                    ? (string) ($departments->get($departmentId)?->name ?? 'Unassigned')
                    : 'Unassigned';

                $hoursTotals = [];
                $amountTotals = [];
                foreach ($earningColumns as $column) {
                    $hoursTotals[$column['key']] = round((float) $group->sum(fn (array $row) => (float) ($row['hours'][$column['key']] ?? 0)), 2);
                    $amountTotals[$column['key']] = round((float) $group->sum(fn (array $row) => (float) ($row['amounts'][$column['key']] ?? 0)), 2);
                }

                return [
                    'departmentId' => $departmentId,
                    'departmentName' => $departmentName,
                    'rows' => $group
                        ->sortBy([['employeeCode', 'asc'], ['employeeName', 'asc']])
                        ->values()
                        ->all(),
                    'totals' => [
                        'hours' => $hoursTotals,
                        'amounts' => $amountTotals,
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
            'earningColumns' => $earningColumns,
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
