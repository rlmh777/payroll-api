<?php

namespace App\Modules\Payroll\Services;

use App\Models\Department;
use App\Models\Account;
use App\Models\PayPeriodSchedule;
use App\Models\PayrollRun;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PayrollJournalDepartmentsReportService
{
    public function __construct(
        private readonly PayrollRunCalculationService $payrollRunCalculationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRun $payrollRun): array
    {
        if (strtolower((string) $payrollRun->status) !== 'posted') {
            throw new InvalidArgumentException('Payroll journal departments report is only available for processed payroll runs.');
        }

        $payrollRun->load(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency']);
        $schedule = $payrollRun->payPeriodSchedule;

        if (!$schedule?->start_date || !$schedule?->end_date) {
            throw new InvalidArgumentException('Pay period schedule is missing for this payroll run.');
        }

        $summary = $this->payrollRunCalculationService->calculate($payrollRun, false, true);
        $summaryRows = collect($summary['rows'] ?? []);
        if ($summaryRows->isEmpty()) {
            throw new InvalidArgumentException('No payroll data is available for this payroll run.');
        }

        $employeeIds = $summaryRows->pluck('employeeId')->map(fn ($id) => (string) $id)->unique()->values();

        $timesheets = Timesheet::query()
            ->whereIn('employeeId', $employeeIds)
            ->whereBetween('date', [
                Carbon::parse($schedule->start_date)->toDateString(),
                Carbon::parse($schedule->end_date)->toDateString(),
            ])
            ->whereRaw('UPPER("approvalStatus") = ?', ['APPROVED'])
            ->orderBy('date')
            ->get();

        $timesheetsByEmployee = $timesheets->groupBy('employeeId');

        $periodEndDate = Carbon::parse($schedule->end_date)->toDateString();

        $departments = $summaryRows
            ->map(function (array $row) use ($timesheetsByEmployee, $periodEndDate) {
                $employeeId = (string) ($row['employeeId'] ?? '');
                $employeeTimesheets = $timesheetsByEmployee->get($employeeId, collect());

                $payrollDate = $periodEndDate;
                $workRows = $employeeTimesheets
                    ->map(function (Timesheet $timesheet) {
                        $hourlyRate = $this->resolveHourlyRate($timesheet);
                        $regularHours = round((float) ($timesheet->regularHours ?? 0), 2);
                        $overtimeHours = round((float) ($timesheet->overtimeHours ?? 0), 2);
                        $holidayHours = round((float) ($timesheet->holidayHours ?? 0), 2);
                        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);

                        $regularTotal = round($regularHours * $hourlyRate, 2);
                        $overtimeRate = round($hourlyRate * $otMultiplier, 2);
                        $overtimeTotal = round($overtimeHours * $overtimeRate, 2);
                        $holidayTotal = round($holidayHours * $hourlyRate, 2);

                        return [
                            'date' => Carbon::parse($timesheet->date)->toDateString(),
                            'description' => null,
                            'rowType' => 'work',
                            'regularHours' => $regularHours,
                            'regularRate' => round($hourlyRate, 2),
                            'regularTotal' => $regularTotal,
                            'overtimeHours' => $overtimeHours,
                            'overtimeRate' => $overtimeRate,
                            'overtimeTotal' => $overtimeTotal,
                            'holidayHours' => $holidayHours,
                            'holidayRate' => round($hourlyRate, 2),
                            'holidayTotal' => $holidayTotal,
                            'otherPayments' => 0.0,
                            'gross' => round($regularTotal + $overtimeTotal + $holidayTotal, 2),
                            'tax' => null,
                            'social' => null,
                            'otherDeductions' => null,
                            'netIncome' => 0.0,
                        ];
                    })
                    ->values();

                $otherPayments = round((float) ($row['taxableAllowances'] ?? 0) + (float) ($row['nonTaxableAllowances'] ?? 0), 2);
                $tax = round((float) ($row['incomeTax'] ?? 0), 2);
                $social = round((float) ($row['employeeSocialSecurity'] ?? 0), 2);
                $otherDeductions = round((float) ($row['deductions'] ?? 0), 2);
                $netIncome = round((float) ($row['netPay'] ?? 0), 2);
                $deductionLines = collect($row['_calculation']['deductions']['lines'] ?? []);
                $deductionAccountIds = $deductionLines
                    ->pluck('accountId')
                    ->filter()
                    ->map(fn ($id) => (string) $id)
                    ->unique()
                    ->values()
                    ->all();
                $deductionAccounts = empty($deductionAccountIds)
                    ? collect()
                    : Account::query()->whereIn('id', $deductionAccountIds)->get(['id', 'name', 'description'])->keyBy('id');

                if ($workRows->isNotEmpty()) {
                    $firstRow = (array) $workRows->first();
                    $firstRow['otherPayments'] = $otherPayments;
                    $firstRow['gross'] = round((float) ($firstRow['gross'] ?? 0) + $otherPayments, 2);
                    $workRows->put(0, $firstRow);
                } else {
                    $workRows = collect([[
                        'date' => $payrollDate,
                        'description' => null,
                        'rowType' => 'work',
                        'regularHours' => 0.0,
                        'regularRate' => 0.0,
                        'regularTotal' => 0.0,
                        'overtimeHours' => 0.0,
                        'overtimeRate' => 0.0,
                        'overtimeTotal' => 0.0,
                        'holidayHours' => 0.0,
                        'holidayRate' => 0.0,
                        'holidayTotal' => 0.0,
                        'otherPayments' => $otherPayments,
                        'gross' => $otherPayments,
                        'tax' => null,
                        'social' => null,
                        'otherDeductions' => null,
                        'netIncome' => 0.0,
                    ]]);
                }

                foreach ($deductionLines as $deductionLine) {
                    $amount = round((float) ($deductionLine['appliedAmount'] ?? $deductionLine['amount'] ?? 0), 2);
                    if ($amount <= 0) {
                        continue;
                    }
                    $accountId = (string) ($deductionLine['accountId'] ?? '');
                    $account = $accountId !== '' ? $deductionAccounts->get($accountId) : null;
                    $label = $account?->name ?? $account?->description ?? 'Other deduction';

                    $workRows->push([
                        'date' => $payrollDate,
                        'description' => $label,
                        'rowType' => 'deduction',
                        'regularHours' => 0.0,
                        'regularRate' => 0.0,
                        'regularTotal' => 0.0,
                        'overtimeHours' => 0.0,
                        'overtimeRate' => 0.0,
                        'overtimeTotal' => 0.0,
                        'holidayHours' => 0.0,
                        'holidayRate' => 0.0,
                        'holidayTotal' => 0.0,
                        'otherPayments' => 0.0,
                        'gross' => 0.0,
                        'tax' => null,
                        'social' => null,
                        'otherDeductions' => $amount,
                        'netIncome' => 0.0,
                    ]);
                }

                $totals = [
                    'regularHours' => round((float) $workRows->sum('regularHours'), 2),
                    'regularTotal' => round((float) $workRows->sum('regularTotal'), 2),
                    'overtimeHours' => round((float) $workRows->sum('overtimeHours'), 2),
                    'overtimeTotal' => round((float) $workRows->sum('overtimeTotal'), 2),
                    'holidayHours' => round((float) $workRows->sum('holidayHours'), 2),
                    'holidayTotal' => round((float) $workRows->sum('holidayTotal'), 2),
                    'otherPayments' => $otherPayments,
                    'gross' => round((float) ($row['grossPay'] ?? 0) + $otherPayments, 2),
                    'tax' => $tax,
                    'social' => $social,
                    'otherDeductions' => $otherDeductions,
                    'netIncome' => $netIncome,
                ];

                return [
                    'departmentId' => $row['departmentId'] ?? null,
                    'employeeId' => $employeeId,
                    'employeeCode' => $row['employeeCode'] ?? null,
                    'employeeName' => $row['employeeName'] ?? 'Unknown employee',
                    'bankName' => $row['bankName'] ?? null,
                    'accountNumber' => $row['accountNumber'] ?? null,
                    'rows' => $workRows->all(),
                    'totals' => $totals,
                ];
            })
            ->groupBy(fn (array $row) => (string) ($row['departmentId'] ?? 'unassigned'))
            ->map(function (Collection $employeeRows, string $departmentKey) {
                $departmentName = $employeeRows->pluck('employeeName')->isEmpty()
                    ? 'Unassigned'
                    : null;

                return [
                    'departmentId' => $departmentKey === 'unassigned' ? null : (int) $departmentKey,
                    'departmentName' => $departmentName,
                    'employees' => $employeeRows
                        ->sortBy([['employeeCode', 'asc'], ['employeeName', 'asc']])
                        ->values()
                        ->all(),
                    'totals' => [
                        'regularHours' => round((float) $employeeRows->sum('totals.regularHours'), 2),
                        'regularTotal' => round((float) $employeeRows->sum('totals.regularTotal'), 2),
                        'overtimeHours' => round((float) $employeeRows->sum('totals.overtimeHours'), 2),
                        'overtimeTotal' => round((float) $employeeRows->sum('totals.overtimeTotal'), 2),
                        'holidayHours' => round((float) $employeeRows->sum('totals.holidayHours'), 2),
                        'holidayTotal' => round((float) $employeeRows->sum('totals.holidayTotal'), 2),
                        'otherPayments' => round((float) $employeeRows->sum('totals.otherPayments'), 2),
                        'gross' => round((float) $employeeRows->sum('totals.gross'), 2),
                        'tax' => round((float) $employeeRows->sum('totals.tax'), 2),
                        'social' => round((float) $employeeRows->sum('totals.social'), 2),
                        'otherDeductions' => round((float) $employeeRows->sum('totals.otherDeductions'), 2),
                        'netIncome' => round((float) $employeeRows->sum('totals.netIncome'), 2),
                    ],
                ];
            })
            ->values()
            ->all();

        $departmentIds = collect($departments)
            ->pluck('departmentId')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $departmentNames = empty($departmentIds)
            ? collect()
            : Department::query()->whereIn('id', $departmentIds)->pluck('name', 'id');

        $resolvedDepartments = collect($departments)->map(function (array $department) use ($departmentNames) {
            if ($department['departmentId'] !== null) {
                $department['departmentName'] = (string) ($departmentNames->get($department['departmentId']) ?? 'Unassigned');
            } else {
                $department['departmentName'] = 'Unassigned';
            }

            return $department;
        })->sortBy('departmentName')->values()->all();

        return [
            'payrollRunId' => (string) $payrollRun->id,
            'payPeriodGroupName' => $schedule->payPeriodGroup?->name,
            'payPeriodStartDate' => Carbon::parse($schedule->start_date)->toDateString(),
            'payPeriodEndDate' => Carbon::parse($schedule->end_date)->toDateString(),
            'payPeriodNumber' => $this->resolvePayPeriodNumber($schedule),
            'departments' => $resolvedDepartments,
        ];
    }

    private function resolvePayPeriodNumber(PayPeriodSchedule $schedule): int
    {
        $startDate = Carbon::parse($schedule->start_date)->startOfDay();

        return (int) PayPeriodSchedule::query()
            ->where('pay_period_group_id', $schedule->pay_period_group_id)
            ->whereYear('start_date', $startDate->year)
            ->whereDate('start_date', '<=', $startDate->toDateString())
            ->count();
    }

    private function resolveHourlyRate(Timesheet $timesheet): float
    {
        $hourlyRate = (float) ($timesheet->hourlyRate ?? 0);
        if ($hourlyRate > 0) {
            return $hourlyRate;
        }

        $baseSalary = (float) ($timesheet->baseSalary ?? 0);
        if ($baseSalary > 0) {
            return app(EmployeeCompensationResolver::class)->hourlyRateFromBaseSalary($baseSalary);
        }

        return 0.0;
    }
}
