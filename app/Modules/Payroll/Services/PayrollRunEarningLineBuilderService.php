<?php

namespace App\Modules\Payroll\Services;

use App\Models\Department;
use App\Models\Payroll;
use App\Models\PayrollEarningCode;
use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PayrollRunEarningLineBuilderService
{
    public function __construct(
        private readonly PayrollRunFrequencyResolver $payrollRunFrequencyResolver,
        private readonly PayrollAccountMappingService $payrollAccountMappingService,
    ) {
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function syncForRun(PayrollRun $payrollRun, array $summary): void
    {
        $payrollRun->load(['payPeriodSchedule']);
        $schedule = $payrollRun->payPeriodSchedule;

        if (!$schedule?->start_date || !$schedule?->end_date) {
            return;
        }

        $rows = collect($summary['rows'] ?? []);
        if ($rows->isEmpty()) {
            PayrollEarningLine::query()->where('payroll_run_id', $payrollRun->id)->delete();

            return;
        }

        $startDate = Carbon::parse($schedule->start_date)->startOfDay();
        $endDate = Carbon::parse($schedule->end_date)->startOfDay();
        $payPeriodGroupId = (string) $schedule->pay_period_group_id;
        $frequencyId = $this->payrollRunFrequencyResolver->resolveForRun($payrollRun, $schedule);
        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);

        $earningCodes = PayrollEarningCode::query()
            ->whereIn('code', ['REGULAR', 'OVERTIME', 'HOLIDAY', 'ALLOWANCE', 'VACATION'])
            ->get()
            ->keyBy(fn (PayrollEarningCode $code) => strtoupper((string) $code->code));

        $payrollsByEmployee = Payroll::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->get(['id', 'employeeId'])
            ->keyBy('employeeId');

        $departmentIds = $rows
            ->pluck('departmentId')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $departmentsById = $departmentIds->isEmpty()
            ? collect()
            : Department::query()->whereIn('id', $departmentIds)->get(['id', 'accountId'])->keyBy('id');

        PayrollEarningLine::query()->where('payroll_run_id', $payrollRun->id)->delete();

        foreach ($rows as $row) {
            $employeeId = (string) ($row['employeeId'] ?? '');
            $departmentId = isset($row['departmentId']) ? (int) $row['departmentId'] : null;
            $payrollId = $payrollsByEmployee->get($employeeId)?->id;
            $hourlyRate = $this->resolveHourlyRate(
                $employeeId,
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $frequencyId,
            );

            $baseEarnings = round((float) ($row['baseEarnings'] ?? 0), 2);
            $overtimeHours = round((float) ($row['overtimeHours'] ?? 0), 2);
            $holidayHours = round((float) ($row['holidayHours'] ?? 0), 2);
            $overtimeAmount = round($overtimeHours * $hourlyRate * $otMultiplier, 2);
            $holidayAmount = round($holidayHours * $hourlyRate, 2);
            $vacationPay = $row['_calculation']['vacationPay'] ?? [];
            $vacationPayAmount = round((float) ($vacationPay['amount'] ?? 0), 2);
            $alreadyPaidLeave = $row['_calculation']['alreadyPaidLeave'] ?? [];
            $alreadyPaidAmount = round((float) ($alreadyPaidLeave['amount'] ?? 0), 2);
            $regularAmount = round(max(0, $baseEarnings - $overtimeAmount - $holidayAmount - $vacationPayAmount), 2);

            $this->createLine(
                $payrollRun,
                $employeeId,
                $payrollId,
                $departmentId,
                $earningCodes->get('REGULAR'),
                null,
                null,
                $regularAmount,
                $this->resolveDepartmentWageAccountId($departmentId, $departmentsById, $earningCodes->get('REGULAR')?->account_id),
            );

            $this->createLine(
                $payrollRun,
                $employeeId,
                $payrollId,
                $departmentId,
                $earningCodes->get('OVERTIME'),
                $overtimeHours > 0 ? $overtimeHours : null,
                $overtimeHours > 0 ? $hourlyRate * $otMultiplier : null,
                $overtimeAmount,
                $this->resolveDepartmentWageAccountId($departmentId, $departmentsById, $earningCodes->get('OVERTIME')?->account_id),
            );

            $this->createLine(
                $payrollRun,
                $employeeId,
                $payrollId,
                $departmentId,
                $earningCodes->get('HOLIDAY'),
                $holidayHours > 0 ? $holidayHours : null,
                $holidayHours > 0 ? $hourlyRate : null,
                $holidayAmount,
                $this->resolveDepartmentWageAccountId($departmentId, $departmentsById, $earningCodes->get('HOLIDAY')?->account_id),
            );

            $allowanceData = $row['_calculation']['allowances'] ?? [];
            foreach ($allowanceData['lines'] ?? [] as $allowanceLine) {
                $amount = round((float) ($allowanceLine['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }

                $earningCode = $earningCodes->get('ALLOWANCE');
                $poolEarningCodeId = $allowanceLine['payrollEarningCodeId'] ?? null;
                if (($allowanceLine['source'] ?? null) === 'pool' && $poolEarningCodeId) {
                    $poolCode = PayrollEarningCode::query()->find($poolEarningCodeId);
                    if ($poolCode) {
                        $earningCode = $poolCode;
                    }
                }

                $accountId = $this->resolveAllowanceAccountId(
                    $allowanceLine,
                    $earningCode?->account_id ?? $earningCodes->get('ALLOWANCE')?->account_id,
                );

                $this->createLine(
                    $payrollRun,
                    $employeeId,
                    $payrollId,
                    $departmentId,
                    $earningCode,
                    null,
                    null,
                    $amount,
                    $accountId,
                    (string) ($allowanceLine['source'] ?? 'CALCULATED'),
                    isset($allowanceLine['sourceId']) ? (string) $allowanceLine['sourceId'] : null,
                );
            }

            $vacationCode = $earningCodes->get('VACATION');
            $vacationAccountId = filled($vacationCode?->account_id)
                ? (string) $vacationCode->account_id
                : $this->payrollAccountMappingService->accountIdFor('VACATION_PAY');

            if ($vacationPayAmount > 0) {
                $this->createLine(
                    $payrollRun,
                    $employeeId,
                    $payrollId,
                    $departmentId,
                    $vacationCode,
                    isset($vacationPay['days']) && (float) $vacationPay['days'] > 0
                        ? (float) $vacationPay['days']
                        : null,
                    null,
                    $vacationPayAmount,
                    $vacationAccountId,
                    'VACATION_PAY',
                    null,
                    'Vacation leave pay',
                );
            }

            if ($alreadyPaidAmount > 0) {
                $this->createLine(
                    $payrollRun,
                    $employeeId,
                    $payrollId,
                    $departmentId,
                    $vacationCode,
                    isset($alreadyPaidLeave['days']) && (float) $alreadyPaidLeave['days'] > 0
                        ? (float) $alreadyPaidLeave['days']
                        : null,
                    null,
                    $alreadyPaidAmount,
                    $vacationAccountId,
                    'ALREADY_PAID_LEAVE',
                    null,
                    'Already paid in advance',
                );
            }
        }
    }

    private function createLine(
        PayrollRun $payrollRun,
        string $employeeId,
        ?string $payrollId,
        ?int $departmentId,
        ?PayrollEarningCode $earningCode,
        ?float $hours,
        ?float $rate,
        float $amount,
        ?string $accountId,
        string $sourceType = 'CALCULATED',
        ?string $sourceId = null,
        ?string $note = null,
    ): void {
        if ($amount <= 0 || !$earningCode) {
            return;
        }

        PayrollEarningLine::create([
            'id' => (string) Str::uuid(),
            'payroll_run_id' => $payrollRun->id,
            'employeeId' => $employeeId,
            'payroll_id' => $payrollId,
            'departmentId' => $departmentId,
            'payroll_earning_code_id' => $earningCode->id,
            'hours' => $hours,
            'rate' => $rate,
            'amount' => $amount,
            'accountId' => $accountId,
            'is_taxable' => (bool) $earningCode->is_taxable,
            'is_ss_subject' => (bool) $earningCode->is_ss_subject,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $note,
        ]);
    }

    /**
     * @param Collection<int, Department> $departmentsById
     */
    private function resolveDepartmentWageAccountId(
        ?int $departmentId,
        Collection $departmentsById,
        ?string $fallbackAccountId,
    ): ?string {
        if ($departmentId !== null) {
            $departmentAccountId = $departmentsById->get($departmentId)?->accountId;
            if (filled($departmentAccountId)) {
                return (string) $departmentAccountId;
            }
        }

        return filled($fallbackAccountId) ? (string) $fallbackAccountId : null;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function resolveAllowanceAccountId(array $line, ?string $fallbackAccountId): ?string
    {
        if (filled($line['accountId'] ?? null)) {
            return (string) $line['accountId'];
        }

        return filled($fallbackAccountId) ? (string) $fallbackAccountId : null;
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
}
