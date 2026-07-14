<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDefaultAllowance;
use App\Models\EmployeeDefaultDeduction;
use App\Models\HistoricalEmployeeAllowance;
use App\Models\HistoricalEmployeeDeduction;
use App\Models\PaymentMethod;
use App\Models\PayrollRun;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PayrollRunPayslipService
{
    public function __construct(
        private readonly PayrollRunCalculationService $payrollRunCalculationService,
        private readonly TimesheetGrossPayService $timesheetGrossPayService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        PayrollRun $payrollRun,
        string $sort = 'last_name',
        ?string $employeeId = null,
        ?int $departmentId = null,
    ): array {
        $sort = $this->normalizeSort($sort);

        $payrollRun->load(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency']);
        $schedule = $payrollRun->payPeriodSchedule;

        if (!$schedule?->start_date || !$schedule?->end_date) {
            throw new InvalidArgumentException('Pay period schedule is missing for this payroll run.');
        }

        if (strtolower((string) $payrollRun->status) !== 'posted') {
            throw new InvalidArgumentException('Payslips can only be generated for processed payroll runs.');
        }

        $startDate = Carbon::parse($schedule->start_date)->startOfDay();
        $endDate = Carbon::parse($schedule->end_date)->startOfDay();
        $payPeriodGroupId = (string) $schedule->pay_period_group_id;
        $frequencyId = $payrollRun->payrate_frequency_id;

        $summary = $this->payrollRunCalculationService->calculate($payrollRun, false, true);
        $rows = collect($summary['rows'] ?? []);

        if ($employeeId !== null) {
            $rows = $rows->filter(fn (array $row) => (string) ($row['employeeId'] ?? '') === $employeeId)->values();

            if ($rows->isEmpty()) {
                throw new InvalidArgumentException('The selected employee is not included in this processed payroll run.');
            }
        }

        if ($departmentId !== null) {
            $rows = $rows
                ->filter(fn (array $row) => (int) ($row['departmentId'] ?? 0) === $departmentId)
                ->values();

            if ($rows->isEmpty()) {
                throw new InvalidArgumentException('No employees in the selected department are included in this processed payroll run.');
            }
        }

        if ($rows->isEmpty()) {
            throw new InvalidArgumentException('No employees are in scope for this payroll run.');
        }

        $employeeIds = $rows->pluck('employeeId')->map(fn ($id) => (string) $id)->all();

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        $paymentMethods = PaymentMethod::query()->get()->keyBy('id');

        $timesheetEarnings = $this->timesheetGrossPayService->forEmployees(
            collect($employeeIds),
            $startDate,
            $endDate,
            $payPeriodGroupId,
            $frequencyId,
        );

        $departmentIds = $rows
            ->pluck('departmentId')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $departments = $departmentIds === []
            ? collect()
            : Department::query()->whereIn('id', $departmentIds)->get()->keyBy('id');

        $defaultAllowances = EmployeeDefaultAllowance::query()
            ->with('allowance')
            ->whereIn('employeeId', $employeeIds)
            ->get()
            ->keyBy('id');

        $importAllowances = HistoricalEmployeeAllowance::query()
            ->with('allowance')
            ->where('payroll_run_id', $payrollRun->id)
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->keyBy('id');

        $defaultDeductions = EmployeeDefaultDeduction::query()
            ->with('deductionType')
            ->whereIn('employeeId', $employeeIds)
            ->get()
            ->keyBy('id');

        $importDeductions = HistoricalEmployeeDeduction::query()
            ->with('deductionType')
            ->where('payroll_run_id', $payrollRun->id)
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->keyBy('id');

        $payrollNumber = PayrollRun::query()
            ->where('created_at', '<=', $payrollRun->created_at)
            ->count();

        $company = Company::query()->first();
        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);

        $payslips = $rows
            ->map(function (array $row) use (
                $employees,
                $departments,
                $defaultAllowances,
                $importAllowances,
                $defaultDeductions,
                $importDeductions,
                $timesheetEarnings,
                $paymentMethods,
                $payrollNumber,
                $otMultiplier,
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $frequencyId,
            ) {
                $employeeId = (string) $row['employeeId'];
                $employee = $employees->get($employeeId);
                $calculation = $row['_calculation'] ?? [];
                $allowanceData = $calculation['allowances'] ?? [];
                $deductionData = $calculation['deductions'] ?? [];
                $departmentId = $row['departmentId'] ?? null;
                $department = $departmentId !== null ? $departments->get((int) $departmentId) : null;
                $timesheet = $timesheetEarnings[$employeeId] ?? [];

                $baseEarnings = round((float) ($row['baseEarnings'] ?? 0), 2);
                $overtimeHours = round((float) ($row['overtimeHours'] ?? 0), 2);
                $regularHours = round((float) ($timesheet['regularHours'] ?? 0), 2);
                $holidayHours = round((float) ($row['holidayHours'] ?? 0), 2);
                $hourlyRate = $this->resolveHourlyRate($employeeId, $startDate, $endDate, $payPeriodGroupId, $frequencyId);

                $overtimeAmount = round($overtimeHours * $hourlyRate * $otMultiplier, 2);
                $holidayAmount = round($holidayHours * $hourlyRate, 2);
                $regularAmount = round(max(0, $baseEarnings - $overtimeAmount - $holidayAmount), 2);
                $regularHoursDisplay = $regularHours > 0 ? $regularHours : ($regularAmount > 0 ? 1.0 : 0.0);
                $regularRate = $regularHoursDisplay > 0
                    ? round($regularAmount / $regularHoursDisplay, 2)
                    : 0.0;
                $overtimeRate = $overtimeHours > 0
                    ? round($overtimeAmount / $overtimeHours, 2)
                    : round($hourlyRate * $otMultiplier, 2);
                $holidayRate = $holidayHours > 0
                    ? round($holidayAmount / $holidayHours, 2)
                    : round($hourlyRate, 2);
                $payPeriodEarningsTotal = round($regularAmount + $overtimeAmount + $holidayAmount, 2);

                $allowanceLines = collect($allowanceData['lines'] ?? [])
                    ->map(fn (array $line) => [
                        'label' => $this->labelAllowanceLine($line, $defaultAllowances, $importAllowances),
                        'amount' => round((float) ($line['amount'] ?? 0), 2),
                        'taxableAmount' => round((float) ($line['taxableAmount'] ?? 0), 2),
                        'nonTaxableAmount' => round((float) ($line['nonTaxableAmount'] ?? 0), 2),
                    ])
                    ->filter(fn (array $line) => $line['amount'] > 0)
                    ->values();

                $otherPayRows = $allowanceLines
                    ->filter(fn (array $line) => $line['nonTaxableAmount'] > 0)
                    ->map(fn (array $line) => [
                        'label' => $line['label'],
                        'amount' => $line['nonTaxableAmount'],
                    ])
                    ->values()
                    ->all();

                $employerSocialSecurity = round((float) ($row['employerSocialSecurity'] ?? 0), 2);
                if ($employerSocialSecurity > 0) {
                    $otherPayRows[] = [
                        'label' => 'Employer Social Security',
                        'amount' => $employerSocialSecurity,
                    ];
                }

                $deductionRows = [];
                $incomeTax = round((float) ($row['incomeTax'] ?? 0), 2);
                $employeeSocialSecurity = round((float) ($row['employeeSocialSecurity'] ?? 0), 2);

                if ($incomeTax > 0) {
                    $deductionRows[] = ['label' => 'Income Tax', 'amount' => $incomeTax];
                }

                if ($employeeSocialSecurity > 0) {
                    $deductionRows[] = ['label' => 'Social Security', 'amount' => $employeeSocialSecurity];
                }

                foreach ($deductionData['lines'] ?? [] as $line) {
                    $amount = round((float) ($line['appliedAmount'] ?? $line['amount'] ?? 0), 2);
                    if ($amount <= 0) {
                        continue;
                    }

                    $deductionRows[] = [
                        'label' => $this->labelDeductionLine($line, $defaultDeductions, $importDeductions),
                        'amount' => $amount,
                    ];
                }

                $earningsTotal = round((float) ($row['grossPay'] ?? 0), 2);
                $totalDeductions = round(collect($deductionRows)->sum('amount'), 2);
                $totalOtherPay = round(collect($otherPayRows)->sum('amount'), 2);
                $netAdjustment = round($totalOtherPay - $totalDeductions, 2);
                $netPay = round((float) ($row['netPay'] ?? 0), 2);

                $ytdEarnings = round((float) ($row['ytdEarnings'] ?? 0) + $earningsTotal, 2);
                $ytdIncomeTax = round((float) ($row['ytdIncomeTax'] ?? 0) + $incomeTax, 2);
                $ytdSocialSecurity = round((float) ($row['ytdSocialSecurity'] ?? 0) + $employeeSocialSecurity, 2);
                $ytdOtherAdjustments = round($netAdjustment, 2);

                $paymentMethod = $paymentMethods->get($employee?->paymentMethodId);
                $paymentMethodLabel = $this->formatPaymentMethod(
                    $paymentMethod?->name,
                    $row['accountNumber'] ?? null,
                );

                return [
                    'employeeId' => $employeeId,
                    'employeeCode' => $row['employeeCode'] ?? $employee?->code,
                    'employeeName' => $this->formatEmployeeDisplayName($employee),
                    'firstName' => $employee?->firstName,
                    'lastName' => $employee?->lastName,
                    'departmentName' => $department?->name,
                    'socialSecurityNumber' => $employee?->socialSecurityNumber,
                    'payrollNumber' => $payrollNumber,
                    'paymentMethodLabel' => $paymentMethodLabel,
                    'earningsRows' => array_values(array_filter([
                        $regularAmount > 0 || $regularHoursDisplay > 0
                            ? [
                                'label' => 'Regular',
                                'hours' => $regularHoursDisplay,
                                'rate' => $regularRate,
                                'amount' => $regularAmount,
                            ]
                            : null,
                        [
                            'label' => 'Over Time',
                            'hours' => $overtimeHours,
                            'rate' => $overtimeRate,
                            'amount' => $overtimeAmount,
                        ],
                        [
                            'label' => 'Holiday',
                            'hours' => $holidayHours,
                            'rate' => $holidayRate,
                            'amount' => $holidayAmount,
                        ],
                    ])),
                    'payPeriodEarningsTotal' => $payPeriodEarningsTotal,
                    'overtimeHours' => $overtimeHours,
                    'holidayHours' => $holidayHours,
                    'earningsTotal' => $earningsTotal,
                    'deductionRows' => $deductionRows,
                    'otherPayRows' => $otherPayRows,
                    'totalDeductions' => $totalDeductions,
                    'totalOtherPay' => $totalOtherPay,
                    'netAdjustment' => $netAdjustment,
                    'netPay' => $netPay,
                    'ytdEarnings' => $ytdEarnings,
                    'ytdIncomeTax' => $ytdIncomeTax,
                    'ytdSocialSecurity' => $ytdSocialSecurity,
                    'ytdOtherAdjustments' => $ytdOtherAdjustments,
                ];
            });

        $payDate = $schedule->pay_date ?? $schedule->end_date;

        return [
            'payrollRunId' => (string) $payrollRun->id,
            'companyName' => $company?->legalName ?? $company?->alias ?? 'Payroll',
            'companyLogoUrl' => $this->resolveLogoUrl($company?->logoPath),
            'primaryColor' => $company?->primaryColor ?: '#5E3D8E',
            'secondaryColor' => $company?->secondaryColor ?: '#F7941D',
            'payPeriodLabel' => sprintf(
                '%s to %s',
                $schedule->start_date->format('M j, Y'),
                $schedule->end_date->format('M j, Y'),
            ),
            'payDate' => $payDate?->format('d-M-Y'),
            'payDateLong' => $payDate?->format('l, F j, Y'),
            'generatedAt' => now()->format('l, F j, Y'),
            'payPeriodGroupName' => $schedule->payPeriodGroup?->name,
            'frequencyName' => $payrollRun->payrateFrequency?->name,
            'sort' => $sort,
            'payslips' => $this->sortPayslips($payslips, $sort),
        ];
    }

    private function normalizeSort(string $sort): string
    {
        return in_array($sort, ['last_name', 'department_last_name'], true)
            ? $sort
            : 'last_name';
    }

    /**
     * @param Collection<int, array<string, mixed>> $payslips
     * @return list<array<string, mixed>>
     */
    private function sortPayslips(Collection $payslips, string $sort): array
    {
        return $payslips
            ->sortBy(function (array $payslip) use ($sort) {
                $lastName = Str::lower((string) ($payslip['lastName'] ?? ''));
                $firstName = Str::lower((string) ($payslip['firstName'] ?? ''));
                $departmentName = Str::lower((string) ($payslip['departmentName'] ?? ''));

                if ($sort === 'department_last_name') {
                    return [$departmentName === '' ? 'zzz' : $departmentName, $lastName, $firstName];
                }

                return [$lastName, $firstName];
            })
            ->values()
            ->all();
    }

    private function formatEmployeeDisplayName(?Employee $employee): string
    {
        if (!$employee) {
            return 'Unknown employee';
        }

        $lastName = trim((string) $employee->lastName);
        $firstName = trim((string) $employee->firstName);

        if ($lastName !== '' && $firstName !== '') {
            return "{$lastName}, {$firstName}";
        }

        return trim("{$lastName}{$firstName}") ?: 'Unknown employee';
    }

    private function formatPaymentMethod(?string $methodName, ?string $accountNumber): string
    {
        $accountNumber = trim((string) $accountNumber);

        if ($accountNumber !== '') {
            $prefix = $methodName ? Str::upper(Str::substr($methodName, 0, 3)) : 'A/C';

            return "{$prefix} - {$accountNumber}";
        }

        return $methodName ?: '—';
    }

    private function resolveLogoUrl(?string $logoPath): ?string
    {
        $logoPath = trim((string) $logoPath);

        if ($logoPath === '') {
            return null;
        }

        if (Str::startsWith($logoPath, ['http://', 'https://'])) {
            return $logoPath;
        }

        if (Storage::disk('public')->exists($logoPath)) {
            return Storage::disk('public')->url($logoPath);
        }

        return asset('storage/' . ltrim($logoPath, '/'));
    }

    private function resolveHourlyRate(
        string $employeeId,
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $frequencyId,
    ): float {
        $timesheet = Timesheet::query()
            ->where('employeeId', $employeeId)
            ->whereRaw('UPPER("approvalStatus") = ?', ['APPROVED'])
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->when($payPeriodGroupId !== '', function ($query) use ($payPeriodGroupId) {
                $query->whereHas('employmentDetail', fn ($detailQuery) => $detailQuery
                    ->where('defaultPayPeriodGroupId', $payPeriodGroupId));
            })
            ->orderByDesc('hourlyRate')
            ->first();

        if (!$timesheet) {
            return 0.0;
        }

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

    /**
     * @param array<string, mixed> $line
     */
    private function labelAllowanceLine(
        array $line,
        Collection $defaultAllowances,
        Collection $importAllowances,
    ): string {
        if (($line['source'] ?? '') === 'default') {
            $record = $defaultAllowances->get((string) ($line['sourceId'] ?? ''));

            return $record?->allowance?->name
                ?? $record?->note
                ?? 'Allowance';
        }

        $record = $importAllowances->get((string) ($line['sourceId'] ?? ''));

        return $record?->allowance?->name
            ?? $record?->note
            ?? 'Imported allowance';
    }

    /**
     * @param array<string, mixed> $line
     */
    private function labelDeductionLine(
        array $line,
        Collection $defaultDeductions,
        Collection $importDeductions,
    ): string {
        if (($line['source'] ?? '') === 'default') {
            $record = $defaultDeductions->get((string) ($line['sourceId'] ?? ''));

            return $record?->deductionType?->name
                ?? $record?->note
                ?? 'Deduction';
        }

        $record = $importDeductions->get((string) ($line['sourceId'] ?? ''));

        return $record?->deductionType?->name
            ?? $record?->note
            ?? 'Imported deduction';
    }
}
