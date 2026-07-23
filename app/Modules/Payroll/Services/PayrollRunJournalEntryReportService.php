<?php

namespace App\Modules\Payroll\Services;

use App\Models\Account;
use App\Models\Department;
use App\Models\EmployeeDefaultAllowance;
use App\Models\EmployeeDefaultDeduction;
use App\Models\HistoricalEmployeeAllowance;
use App\Models\HistoricalEmployeeDeduction;
use App\Models\PayPeriodSchedule;
use App\Models\PayrollAccountMapping;
use App\Models\PayrollEarningCode;
use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PayrollRunJournalEntryReportService
{
    public function __construct(
        private readonly PayrollRunCalculationService $payrollRunCalculationService,
        private readonly PayrollRunFrequencyResolver $payrollRunFrequencyResolver,
        private readonly PayrollAccountMappingService $payrollAccountMappingService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRun $payrollRun): array
    {
        if (strtolower((string) $payrollRun->status) !== 'posted') {
            throw new InvalidArgumentException('Journal entries are only available for processed payroll runs.');
        }

        $payrollRun->load(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency']);
        $schedule = $payrollRun->payPeriodSchedule;

        if (! $schedule?->start_date || ! $schedule?->end_date) {
            throw new InvalidArgumentException('Pay period schedule is missing for this payroll run.');
        }

        $startDate = Carbon::parse($schedule->start_date)->startOfDay();
        $endDate = Carbon::parse($schedule->end_date)->startOfDay();
        $payPeriodGroupId = (string) $schedule->pay_period_group_id;
        $frequencyId = $this->payrollRunFrequencyResolver->resolveForRun($payrollRun, $schedule);
        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);

        $summary = $this->payrollRunCalculationService->calculate($payrollRun, false, true);
        $rows = collect($summary['rows'] ?? []);

        if ($rows->isEmpty()) {
            throw new InvalidArgumentException('No payroll data is available for this payroll run.');
        }

        $earningAccounts = $this->earningAccountsByCode();
        $wagesPayableAccountId = $this->payrollAccountMappingService->journalAccountKey('WAGES_PAYABLE');
        $deductionsPayableAccountId = $this->payrollAccountMappingService->journalAccountKey('DEDUCTIONS_PAYABLE');
        $incomeTaxAccountId = $this->payrollAccountMappingService->journalAccountKey('INCOME_TAX_PAYABLE');
        $socialSecurityAccountId = $this->payrollAccountMappingService->journalAccountKey('EMPLOYEE_SOCIAL_SECURITY_PAYABLE');
        $employerSocialSecurityAccountId = $this->payrollAccountMappingService->journalAccountKey('EMPLOYER_SOCIAL_SECURITY_EXPENSE');
        $departmentWagesFallback = $this->payrollAccountMappingService->journalAccountKey('DEPARTMENT_WAGES');
        $allowancesFallback = $this->payrollAccountMappingService->journalAccountKey('ALLOWANCES');

        $departmentIds = $rows
            ->pluck('departmentId')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $departmentsById = $departmentIds->isEmpty()
            ? collect()
            : Department::query()->whereIn('id', $departmentIds)->get(['id', 'accountId'])->keyBy('id');

        /** @var array<string, array{accountId:string,accountNumber:string,accountDescription:string,debit:float,credit:float}> $lines */
        $lines = [];

        $existingEarningLines = PayrollEarningLine::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->whereNotNull('accountId')
            ->get(['accountId', 'amount']);

        if ($existingEarningLines->isNotEmpty()) {
            foreach ($existingEarningLines as $earningLine) {
                $this->addDebit(
                    $lines,
                    (string) $earningLine->accountId,
                    (float) $earningLine->amount,
                );
            }
        } else {
            foreach ($rows as $row) {
                $employeeId = (string) ($row['employeeId'] ?? '');
                $departmentId = isset($row['departmentId']) ? (int) $row['departmentId'] : null;
                $baseEarnings = round((float) ($row['baseEarnings'] ?? 0), 2);
                $overtimeHours = round((float) ($row['overtimeHours'] ?? 0), 2);
                $holidayHours = round((float) ($row['holidayHours'] ?? 0), 2);
                $hourlyRate = $this->resolveHourlyRate($employeeId, $startDate, $endDate, $payPeriodGroupId, $frequencyId);

                $overtimeAmount = round($overtimeHours * $hourlyRate * $otMultiplier, 2);
                $holidayAmount = round($holidayHours * $hourlyRate, 2);
                $regularAmount = round(max(0, $baseEarnings - $overtimeAmount - $holidayAmount), 2);

                if ($regularAmount > 0) {
                    $this->addDebit(
                        $lines,
                        $this->resolveDepartmentWageAccountId(
                            $departmentId,
                            $departmentsById,
                            $earningAccounts['REGULAR'] ?? $departmentWagesFallback,
                        ),
                        $regularAmount,
                    );
                }

                if ($overtimeAmount > 0) {
                    $this->addDebit(
                        $lines,
                        $this->resolveDepartmentWageAccountId(
                            $departmentId,
                            $departmentsById,
                            $earningAccounts['OVERTIME'] ?? $departmentWagesFallback,
                        ),
                        $overtimeAmount,
                    );
                }

                if ($holidayAmount > 0) {
                    $this->addDebit(
                        $lines,
                        $this->resolveDepartmentWageAccountId(
                            $departmentId,
                            $departmentsById,
                            $earningAccounts['HOLIDAY'] ?? $departmentWagesFallback,
                        ),
                        $holidayAmount,
                    );
                }

                $allowanceData = $row['_calculation']['allowances'] ?? [];
                foreach ($allowanceData['lines'] ?? [] as $allowanceLine) {
                    $amount = round((float) ($allowanceLine['amount'] ?? 0), 2);
                    if ($amount <= 0) {
                        continue;
                    }

                    $accountId = $this->resolveAllowanceAccountId(
                        $allowanceLine,
                        $earningAccounts['ALLOWANCE'] ?? $allowancesFallback,
                    );
                    $this->addDebit($lines, $accountId, $amount);
                }
            }
        }

        foreach ($rows as $row) {
            $employerSocialSecurity = round((float) ($row['employerSocialSecurity'] ?? 0), 2);
            if ($employerSocialSecurity > 0) {
                $this->addDebit($lines, $employerSocialSecurityAccountId, $employerSocialSecurity);
            }

            $incomeTax = round((float) ($row['incomeTax'] ?? 0), 2);
            if ($incomeTax > 0) {
                $this->addCredit($lines, $incomeTaxAccountId, $incomeTax);
            }

            $employeeSocialSecurity = round((float) ($row['employeeSocialSecurity'] ?? 0), 2);
            if ($employeeSocialSecurity > 0) {
                $this->addCredit($lines, $socialSecurityAccountId, $employeeSocialSecurity);
            }

            $deductionData = $row['_calculation']['deductions'] ?? [];
            foreach ($deductionData['lines'] ?? [] as $deductionLine) {
                $amount = round((float) ($deductionLine['appliedAmount'] ?? $deductionLine['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }

                $accountId = $this->resolveDeductionAccountId($deductionLine, $deductionsPayableAccountId);
                $this->addCredit($lines, $accountId, $amount);
            }

            $netPay = round((float) ($row['netPay'] ?? 0), 2);
            if ($netPay > 0) {
                $this->addCredit($lines, $wagesPayableAccountId, $netPay);
            }
        }

        $accountIds = collect(array_keys($lines))
            ->filter(fn (string $key) => ! str_starts_with($key, 'mapping:'))
            ->values()
            ->all();

        $accounts = Account::query()
            ->whereIn('id', $accountIds)
            ->get(['id', 'name', 'description', 'code1', 'code2'])
            ->keyBy('id');

        $mappingsByCode = PayrollAccountMapping::query()
            ->get(['code', 'name'])
            ->keyBy(fn (PayrollAccountMapping $mapping) => strtoupper((string) $mapping->code));

        $reportRows = collect($lines)
            ->map(function (array $line, string $accountKey) use ($accounts, $mappingsByCode) {
                if (str_starts_with($accountKey, 'mapping:')) {
                    $code = strtoupper(substr($accountKey, 8));
                    $mapping = $mappingsByCode->get($code);

                    return [
                        'accountId' => null,
                        'accountNumber' => $code,
                        'accountDescription' => $mapping?->name ?? "Unmapped {$code}",
                        'debit' => round($line['debit'], 2),
                        'credit' => round($line['credit'], 2),
                    ];
                }

                $account = $accounts->get($accountKey);

                return [
                    'accountId' => $accountKey,
                    'accountNumber' => $account ? $this->formatAccountNumber($account) : '—',
                    'accountDescription' => $account?->name ?? $account?->description ?? 'Unknown account',
                    'debit' => round($line['debit'], 2),
                    'credit' => round($line['credit'], 2),
                ];
            })
            ->sortBy(['accountNumber', 'accountDescription'])
            ->values()
            ->all();

        $totalDebit = round(collect($reportRows)->sum('debit'), 2);
        $totalCredit = round(collect($reportRows)->sum('credit'), 2);

        return [
            'payrollRunId' => (string) $payrollRun->id,
            'payPeriodGroupId' => $payPeriodGroupId,
            'payPeriodGroupName' => $schedule->payPeriodGroup?->name,
            'payPeriodStartDate' => $startDate->toDateString(),
            'payPeriodEndDate' => $endDate->toDateString(),
            'payPeriodNumber' => $this->resolvePayPeriodNumber($schedule),
            'rows' => $reportRows,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
            ],
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

        if (! $timesheet) {
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
     * @return array<string, string>
     */
    private function earningAccountsByCode(): array
    {
        return PayrollEarningCode::query()
            ->whereNotNull('account_id')
            ->get(['code', 'account_id'])
            ->mapWithKeys(fn (PayrollEarningCode $code) => [
                strtoupper((string) $code->code) => (string) $code->account_id,
            ])
            ->all();
    }

    /**
     * @param  array<string, array{accountId:string,accountNumber:string,accountDescription:string,debit:float,credit:float}>  $lines
     */
    private function addDebit(array &$lines, string $accountId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $lines[$accountId] ??= [
            'accountId' => $accountId,
            'accountNumber' => '',
            'accountDescription' => '',
            'debit' => 0.0,
            'credit' => 0.0,
        ];
        $lines[$accountId]['debit'] = round($lines[$accountId]['debit'] + $amount, 2);
    }

    /**
     * @param  array<string, array{accountId:string,accountNumber:string,accountDescription:string,debit:float,credit:float}>  $lines
     */
    private function addCredit(array &$lines, string $accountId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $lines[$accountId] ??= [
            'accountId' => $accountId,
            'accountNumber' => '',
            'accountDescription' => '',
            'debit' => 0.0,
            'credit' => 0.0,
        ];
        $lines[$accountId]['credit'] = round($lines[$accountId]['credit'] + $amount, 2);
    }

    /**
     * @param  Collection<int, Department>  $departmentsById
     */
    private function resolveDepartmentWageAccountId(
        ?int $departmentId,
        Collection $departmentsById,
        string $fallbackAccountId,
    ): string {
        if ($departmentId !== null) {
            $departmentAccountId = $departmentsById->get($departmentId)?->accountId;
            if (filled($departmentAccountId)) {
                return (string) $departmentAccountId;
            }
        }

        return $fallbackAccountId;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolveAllowanceAccountId(array $line, string $fallbackAccountId): string
    {
        if (filled($line['accountId'] ?? null)) {
            return (string) $line['accountId'];
        }

        if (($line['source'] ?? '') === 'default') {
            $record = EmployeeDefaultAllowance::query()->find($line['sourceId'] ?? null);

            return filled($record?->accountId) ? (string) $record->accountId : $fallbackAccountId;
        }

        $record = HistoricalEmployeeAllowance::query()->find($line['sourceId'] ?? null);

        return filled($record?->accountId) ? (string) $record->accountId : $fallbackAccountId;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function resolveDeductionAccountId(array $line, string $fallbackAccountId): string
    {
        if (filled($line['accountId'] ?? null)) {
            return (string) $line['accountId'];
        }

        if (($line['source'] ?? '') === 'default') {
            $record = EmployeeDefaultDeduction::query()->find($line['sourceId'] ?? null);

            return filled($record?->accountId) ? (string) $record->accountId : $fallbackAccountId;
        }

        $record = HistoricalEmployeeDeduction::query()->find($line['sourceId'] ?? null);

        return filled($record?->accountId) ? (string) $record->accountId : $fallbackAccountId;
    }

    private function formatAccountNumber(Account $account): string
    {
        $code1 = trim((string) ($account->code1 ?? ''));
        $code2 = trim((string) ($account->code2 ?? ''));

        if ($code1 === '') {
            return '—';
        }

        return $code2 !== '' ? "{$code1}-{$code2}" : $code1;
    }
}
