<?php

namespace App\Modules\Payroll\Services;

use App\Models\Employee;
use App\Models\EmployeeBank;
use App\Models\HistoricalEmployeeAllowance;
use App\Models\HistoricalEmployeeDeduction;
use App\Models\PaymentMethod;
use App\Models\Payroll;
use App\Models\PayrollRun;
use App\Services\SocialSecurity\SocialSecurityContributionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayrollRunCalculationService
{
    public function __construct(
        private readonly TimesheetGrossPayService $timesheetGrossPayService,
        private readonly AllowanceResolutionService $allowanceResolutionService,
        private readonly DeductionResolutionService $deductionResolutionService,
        private readonly SocialSecurityContributionService $socialSecurityContributionService,
        private readonly IncomeTaxCalculationService $incomeTaxCalculationService,
        private readonly PayrollTimesheetScopeService $payrollTimesheetScopeService,
        private readonly PayrollRunFrequencyResolver $payrollRunFrequencyResolver,
    ) {
    }

    /**
     * @return array{
     *     payrollRunId: string,
     *     asOfDate: string,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, float>
     * }
     */
    public function calculate(PayrollRun $payrollRun, bool $persistDraft = true, bool $includeCalculation = false): array
    {
        $payrollRun->load(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency']);

        $schedule = $payrollRun->payPeriodSchedule;
        if (!$schedule?->start_date || !$schedule?->end_date) {
            return $this->emptySummary($payrollRun);
        }

        $startDate = Carbon::parse($schedule->start_date)->startOfDay();
        $endDate = Carbon::parse($schedule->end_date)->startOfDay();
        $payDate = Carbon::parse($schedule->pay_date ?? $schedule->end_date)->startOfDay();
        $payPeriodGroupId = (string) $schedule->pay_period_group_id;
        $frequencyId = $this->payrollRunFrequencyResolver->resolveForRun($payrollRun, $schedule);
        $frequencyName = $payrollRun->payrateFrequency?->name
            ?? ($frequencyId
                ? \App\Models\PayrateFrequency::query()->whereKey($frequencyId)->value('name')
                : null);
        $mondays = PayPeriodHelper::countMondays($startDate, $endDate);
        $weeksInPeriod = max(1, $mondays);

        $employeeIds = $this->resolveEmployeeIds(
            $payrollRun,
            $payPeriodGroupId,
            $startDate,
            $endDate,
            $frequencyId,
        );

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        $primaryBanksByEmployee = EmployeeBank::query()
            ->with('bank')
            ->whereIn('employeeId', $employeeIds)
            ->orderByDesc('isPrimary')
            ->orderBy('created_at')
            ->get()
            ->groupBy('employeeId')
            ->map(fn (Collection $banks) => $banks->first());

        $timesheetEarnings = $this->timesheetGrossPayService->forEmployees(
            $employeeIds,
            $startDate,
            $endDate,
            $payPeriodGroupId,
            $frequencyId,
        );

        $allowancesByEmployee = $this->allowanceResolutionService->forEmployees(
            $employeeIds,
            (string) $payrollRun->id,
            $frequencyId,
        );

        $preTaxByEmployee = [];
        foreach ($employeeIds as $employeeId) {
            $employeeKey = (string) $employeeId;
            $baseEarnings = (float) ($timesheetEarnings[$employeeKey]['baseEarnings'] ?? 0);
            $taxableAllowances = (float) ($allowancesByEmployee[$employeeKey]['taxableTotal'] ?? 0);
            $nonTaxableAllowances = (float) ($allowancesByEmployee[$employeeKey]['nonTaxableTotal'] ?? 0);
            $taxableGross = round($baseEarnings + $taxableAllowances, 2);

            $employee = $employees->get($employeeKey);
            $ss = $employee
                ? $this->socialSecurityContributionService->calculate(
                    $employee,
                    $payDate,
                    $mondays > 0 ? round($taxableGross / $mondays, 2) : 0.0,
                    (float) $weeksInPeriod,
                )
                : [
                    'employee_amount' => 0.0,
                    'employer_amount' => 0.0,
                    'applied_rule_id' => null,
                    'applied_tier_id' => null,
                    'detail' => [],
                ];

            $tax = $employee
                ? $this->incomeTaxCalculationService->calculate(
                    $employee,
                    $payDate,
                    $taxableGross,
                    $frequencyName,
                    (string) $payrollRun->id,
                )
                : [
                    'income_tax_amount' => 0.0,
                    'applied_personal_relief_id' => null,
                    'taxable_gross' => $taxableGross,
                    'detail' => [],
                ];

            $preTaxByEmployee[$employeeKey] = [
                'baseEarnings' => $baseEarnings,
                'taxableAllowances' => $taxableAllowances,
                'nonTaxableAllowances' => $nonTaxableAllowances,
                'taxableGross' => $taxableGross,
                'employeeSocialSecurity' => (float) $ss['employee_amount'],
                'employerSocialSecurity' => (float) $ss['employer_amount'],
                'incomeTax' => (float) $tax['income_tax_amount'],
                'ss' => $ss,
                'tax' => $tax,
            ];
        }

        $deductionsByEmployee = $this->deductionResolutionService->forEmployees(
            $employeeIds,
            (string) $payrollRun->id,
            $frequencyId,
            function (string $employeeId) use ($preTaxByEmployee) {
                $preTax = $preTaxByEmployee[$employeeId];

                return round(
                    $preTax['taxableGross']
                    - $preTax['employeeSocialSecurity']
                    - $preTax['incomeTax']
                    + $preTax['nonTaxableAllowances'],
                    2,
                );
            },
        );

        $ytdByEmployee = $this->loadYtdTotals($employeeIds, $payDate, (string) $payrollRun->id);

        $rows = $employeeIds
            ->map(function (string $employeeId) use (
                $employees,
                $timesheetEarnings,
                $allowancesByEmployee,
                $preTaxByEmployee,
                $deductionsByEmployee,
                $ytdByEmployee,
                $primaryBanksByEmployee,
                $mondays,
            ) {
                $employee = $employees->get($employeeId);
                $timesheet = $timesheetEarnings[$employeeId] ?? [];
                $preTax = $preTaxByEmployee[$employeeId];
                $deductions = $deductionsByEmployee[$employeeId];
                $ytd = $ytdByEmployee[$employeeId] ?? null;
                $employeeBank = $primaryBanksByEmployee->get($employeeId);

                $netPay = round(
                    $preTax['taxableGross']
                    - $preTax['employeeSocialSecurity']
                    - $preTax['incomeTax']
                    + $preTax['nonTaxableAllowances']
                    - (float) $deductions['total'],
                    2,
                );

                return [
                    'employeeId' => $employeeId,
                    'employeeCode' => $employee?->code,
                    'employeeName' => $employee
                        ? trim(collect([$employee->firstName, $employee->middleName, $employee->lastName])->filter()->implode(' '))
                        : null,
                    'baseEarnings' => round((float) $preTax['baseEarnings'], 2),
                    'taxableAllowances' => round((float) $preTax['taxableAllowances'], 2),
                    'nonTaxableAllowances' => round((float) $preTax['nonTaxableAllowances'], 2),
                    'grossPay' => round((float) $preTax['taxableGross'], 2),
                    'taxableGross' => round((float) $preTax['taxableGross'], 2),
                    'allowances' => round((float) $preTax['taxableAllowances'], 2),
                    'deductions' => round((float) $deductions['total'], 2),
                    'employerSocialSecurity' => round((float) $preTax['employerSocialSecurity'], 2),
                    'employeeSocialSecurity' => round((float) $preTax['employeeSocialSecurity'], 2),
                    'incomeTax' => round((float) $preTax['incomeTax'], 2),
                    'overtimeHours' => round((float) ($timesheet['overtimeHours'] ?? 0), 2),
                    'holidayHours' => round((float) ($timesheet['holidayHours'] ?? 0), 2),
                    'ytdEarnings' => round((float) ($ytd['ytd_earnings'] ?? 0), 2),
                    'ytdIncomeTax' => round((float) ($ytd['ytd_income_tax'] ?? 0), 2),
                    'ytdSocialSecurity' => round((float) ($ytd['ytd_social_security'] ?? 0), 2),
                    'netPay' => $netPay,
                    'mondaysInPeriod' => $mondays,
                    'employeeBankId' => $employeeBank?->id,
                    'bankId' => $employeeBank?->bankId,
                    'bankName' => $employeeBank?->bank?->name,
                    'accountNumber' => $employeeBank?->accountNumber,
                    'paymentReady' => $employeeBank !== null && filled($employeeBank->accountNumber),
                    'employmentDetailId' => $timesheet['employmentDetailId'] ?? null,
                    'employeeCompensationId' => $timesheet['employeeCompensationId'] ?? null,
                    'departmentId' => $timesheet['departmentId'] ?? null,
                    '_calculation' => [
                        'ss' => $preTax['ss'],
                        'tax' => $preTax['tax'],
                        'allowances' => $allowancesByEmployee[$employeeId] ?? [],
                        'deductions' => $deductions,
                    ],
                ];
            })
            ->sortBy([
                ['employeeCode', 'asc'],
                ['employeeName', 'asc'],
            ])
            ->values()
            ->all();

        if ($persistDraft && strtolower((string) $payrollRun->status) === 'draft') {
            $this->persistDraftPayrolls($payrollRun, $payDate, $rows, $employees, $primaryBanksByEmployee);
        }

        $publicRows = array_map(function (array $row) use ($includeCalculation) {
            if (!$includeCalculation) {
                unset($row['_calculation'], $row['employmentDetailId'], $row['employeeCompensationId'], $row['departmentId']);
            } else {
                unset($row['employmentDetailId'], $row['employeeCompensationId']);
            }

            return $row;
        }, $rows);

        return [
            'payrollRunId' => (string) $payrollRun->id,
            'asOfDate' => $payDate->toDateString(),
            'rows' => $publicRows,
            'totals' => $this->buildTotals($publicRows),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, float>
     */
    private function buildTotals(array $rows): array
    {
        $collection = collect($rows);

        return [
            'baseEarnings' => round((float) $collection->sum('baseEarnings'), 2),
            'taxableAllowances' => round((float) $collection->sum('taxableAllowances'), 2),
            'nonTaxableAllowances' => round((float) $collection->sum('nonTaxableAllowances'), 2),
            'grossPay' => round((float) $collection->sum('grossPay'), 2),
            'taxableGross' => round((float) $collection->sum('taxableGross'), 2),
            'allowances' => round((float) $collection->sum('allowances'), 2),
            'deductions' => round((float) $collection->sum('deductions'), 2),
            'employerSocialSecurity' => round((float) $collection->sum('employerSocialSecurity'), 2),
            'employeeSocialSecurity' => round((float) $collection->sum('employeeSocialSecurity'), 2),
            'incomeTax' => round((float) $collection->sum('incomeTax'), 2),
            'overtimeHours' => round((float) $collection->sum('overtimeHours'), 2),
            'holidayHours' => round((float) $collection->sum('holidayHours'), 2),
            'ytdEarnings' => round((float) $collection->sum('ytdEarnings'), 2),
            'ytdIncomeTax' => round((float) $collection->sum('ytdIncomeTax'), 2),
            'ytdSocialSecurity' => round((float) $collection->sum('ytdSocialSecurity'), 2),
            'netPay' => round((float) $collection->sum('netPay'), 2),
        ];
    }

    /**
     * Employees in the payroll summary must match accountant review scope:
     * timesheets in the pay period for the run's pay period group, plus import rows.
     *
     * @return Collection<int, string>
     */
    private function resolveEmployeeIds(
        PayrollRun $payrollRun,
        string $payPeriodGroupId,
        Carbon $startDate,
        Carbon $endDate,
        ?int $frequencyId,
    ): Collection {
        if ($payPeriodGroupId === '') {
            return collect();
        }

        $fromTimesheets = collect(
            $this->payrollTimesheetScopeService->employeeIds(
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $frequencyId,
            ),
        );

        $fromImports = HistoricalEmployeeAllowance::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->pluck('employee_id')
            ->merge(
                HistoricalEmployeeDeduction::query()
                    ->where('payroll_run_id', $payrollRun->id)
                    ->pluck('employee_id'),
            )
            ->map(fn ($id) => (string) $id)
            ->filter(fn (string $employeeId) => $fromTimesheets->contains($employeeId));

        return $fromTimesheets
            ->merge($fromImports)
            ->unique()
            ->values();
    }

    /**
     * @param Collection<int, string> $employeeIds
     * @return array<string, array<string, float>>
     */
    private function loadYtdTotals(Collection $employeeIds, Carbon $payDate, string $excludePayrollRunId): array
    {
        $yearStart = $payDate->copy()->startOfYear();

        return Payroll::query()
            ->select(
                'employeeId',
                DB::raw('SUM("taxableGross") as ytd_earnings'),
                DB::raw('SUM("incomeTaxAmount") as ytd_income_tax'),
                DB::raw('SUM("employeeSocialSecurityAmount") as ytd_social_security'),
            )
            ->whereDate('date', '>=', $yearStart->toDateString())
            ->whereDate('date', '<', $payDate->toDateString())
            ->whereIn('employeeId', $employeeIds)
            ->where(function ($query) use ($excludePayrollRunId) {
                $query->whereNull('payroll_run_id')
                    ->orWhere('payroll_run_id', '!=', $excludePayrollRunId);
            })
            ->where(function ($query) {
                $query->whereDoesntHave('payrollRun')
                    ->orWhereHas('payrollRun', fn ($runQuery) => $runQuery->where('status', 'posted'));
            })
            ->groupBy('employeeId')
            ->get()
            ->keyBy('employeeId')
            ->map(fn ($row) => [
                'ytd_earnings' => (float) ($row->ytd_earnings ?? 0),
                'ytd_income_tax' => (float) ($row->ytd_income_tax ?? 0),
                'ytd_social_security' => (float) ($row->ytd_social_security ?? 0),
            ])
            ->all();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param Collection<string, Employee> $employees
     */
    private function persistDraftPayrolls(
        PayrollRun $payrollRun,
        Carbon $payDate,
        array $rows,
        Collection $employees,
        Collection $primaryBanksByEmployee,
    ): void {
        DB::transaction(function () use ($payrollRun, $payDate, $rows, $employees, $primaryBanksByEmployee) {
            $existing = Payroll::query()
                ->where('payroll_run_id', $payrollRun->id)
                ->get()
                ->keyBy('employeeId');

            $defaultPaymentMethodId = PaymentMethod::query()->value('id');
            $retainedEmployeeIds = [];

            foreach ($rows as $row) {
                $employeeId = (string) $row['employeeId'];
                $retainedEmployeeIds[] = $employeeId;
                $calculation = $row['_calculation'] ?? [];
                $ss = $calculation['ss'] ?? [];
                $tax = $calculation['tax'] ?? [];
                $employee = $employees->get($employeeId);
                $paymentMethodId = $employee?->paymentMethodId ?? $defaultPaymentMethodId;
                $employeeBank = $primaryBanksByEmployee->get($employeeId);

                if ($paymentMethodId === null) {
                    continue;
                }

                $mondaysInPeriod = (int) ($row['mondaysInPeriod'] ?? 0);
                $taxableGross = (float) ($row['taxableGross'] ?? 0);

                $payload = [
                    'employeeId' => $employeeId,
                    'employmentDetailId' => $row['employmentDetailId'] ?? null,
                    'employeeCompensationId' => $row['employeeCompensationId'] ?? null,
                    'payroll_run_id' => $payrollRun->id,
                    'departmentId' => $row['departmentId'] ?? null,
                    'date' => $payDate->toDateString(),
                    'paymentMethodId' => (int) $paymentMethodId,
                    'employeeBankId' => $employeeBank?->id,
                    'bankId' => $employeeBank?->bankId,
                    'accountNumber' => $employeeBank?->accountNumber,
                    'totalRegularHours' => 0,
                    'totalOvertimeHours' => $row['overtimeHours'] ?? 0,
                    'holidayHours' => $row['holidayHours'] ?? 0,
                    'grossSalary' => $row['baseEarnings'] ?? 0,
                    'taxableGross' => $taxableGross,
                    'ssWages' => $mondaysInPeriod > 0
                        ? round($taxableGross / $mondaysInPeriod, 2)
                        : 0,
                    'totalAllowances' => round(
                        (float) ($row['taxableAllowances'] ?? 0) + (float) ($row['nonTaxableAllowances'] ?? 0),
                        2,
                    ),
                    'totalDeductions' => $row['deductions'] ?? 0,
                    'employeeSocialSecurityAmount' => $row['employeeSocialSecurity'] ?? 0,
                    'employerSocialSecurityAmount' => $row['employerSocialSecurity'] ?? 0,
                    'incomeTaxAmount' => $row['incomeTax'] ?? 0,
                    'netSalary' => $row['netPay'] ?? 0,
                    'employerCostTotal' => round(
                        (float) ($row['taxableGross'] ?? 0)
                        + (float) ($row['nonTaxableAllowances'] ?? 0)
                        + (float) ($row['employerSocialSecurity'] ?? 0),
                        2,
                    ),
                    'applied_ss_rule_id' => $ss['applied_rule_id'] ?? null,
                    'applied_ss_tier_id' => $ss['applied_tier_id'] ?? null,
                    'ss_calculation_detail' => $ss['detail'] ?? null,
                    'applied_personal_relief_id' => $tax['applied_personal_relief_id'] ?? null,
                    'tax_calculation_detail' => $tax['detail'] ?? null,
                ];

                $payroll = $existing->get($employeeId);
                if ($payroll) {
                    $payroll->update($payload);
                } else {
                    Payroll::query()->create([
                        'id' => (string) Str::uuid(),
                        ...$payload,
                    ]);
                }
            }

            Payroll::query()
                ->where('payroll_run_id', $payrollRun->id)
                ->whereNotIn('employeeId', $retainedEmployeeIds)
                ->delete();
        });
    }

    /**
     * @return array{payrollRunId: string, asOfDate: string, rows: array<int, never>, totals: array<string, float>}
     */
    private function emptySummary(PayrollRun $payrollRun): array
    {
        return [
            'payrollRunId' => (string) $payrollRun->id,
            'asOfDate' => now()->toDateString(),
            'rows' => [],
            'totals' => $this->buildTotals([]),
        ];
    }
}
