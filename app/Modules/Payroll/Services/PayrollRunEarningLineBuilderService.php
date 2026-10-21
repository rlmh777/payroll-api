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
        private readonly PayrollTimesheetScopeService $payrollTimesheetScopeService,
        private readonly TimesheetDepartmentCostAllocator $timesheetDepartmentCostAllocator,
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
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $codesById = $earningCodes->keyBy('id');
        $codesBySource = $earningCodes
            ->filter(fn (PayrollEarningCode $code) => filled($code->source))
            ->keyBy(fn (PayrollEarningCode $code) => (string) $code->source);

        $payrollsByEmployee = Payroll::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->get(['id', 'employeeId'])
            ->keyBy('employeeId');

        $employeeIds = $rows
            ->pluck('employeeId')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        $timesheets = $employeeIds->isEmpty()
            ? collect()
            : $this->payrollTimesheetScopeService
                ->baseQuery($startDate, $endDate, $payPeriodGroupId, $frequencyId)
                ->whereIn('employeeId', $employeeIds->all())
                ->whereRaw('UPPER("approvalStatus") = ?', ['APPROVED'])
                ->get(['employeeId', 'departmentId', 'regularHours', 'overtimeHours', 'holidayHours']);

        $dayWorkDepartmentIds = $rows->flatMap(function ($row) {
            $slices = is_array($row['_calculation']['dayWorkByDepartment'] ?? null)
                ? $row['_calculation']['dayWorkByDepartment']
                : [];

            return collect($slices)->pluck('departmentId');
        });

        $departmentIds = $rows
            ->pluck('departmentId')
            ->merge($timesheets->pluck('departmentId'))
            ->merge($dayWorkDepartmentIds)
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
            $employeeTimesheets = $timesheets->where('employeeId', $employeeId);
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
            $dayWorkSlices = $this->dayWorkSlicesForRegular($row, $regularAmount);
            $dayWorkTotal = round(array_sum(array_map(
                fn (array $slice) => (float) $slice['amount'],
                $dayWorkSlices,
            )), 2);
            $regularRemainder = round(max(0, $regularAmount - $dayWorkTotal), 2);

            $regularCode = $codesBySource->get(PayrollEarningCode::SOURCE_TIMESHEET_REGULAR);
            $overtimeCode = $codesBySource->get(PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME);
            $holidayCode = $codesBySource->get(PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY);
            $vacationCode = $codesBySource->get(PayrollEarningCode::SOURCE_VACATION);
            $allowanceFallback = $codesBySource->get(PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK);

            $this->createDistributedLine(
                $payrollRun,
                $employeeId,
                $payrollId,
                $departmentId,
                $regularCode,
                $departmentsById,
                null,
                null,
                $regularRemainder,
                $this->hoursByDepartment($employeeTimesheets, 'regularHours', $departmentId),
            );

            foreach ($dayWorkSlices as $slice) {
                $this->createLine(
                    $payrollRun,
                    $employeeId,
                    $payrollId,
                    $slice['departmentId'] ?? $departmentId,
                    $regularCode,
                    $departmentsById,
                    null,
                    null,
                    (float) $slice['amount'],
                    'DAY_WORK',
                    null,
                    'Day / trip work',
                );
            }

            $this->createDistributedLine(
                $payrollRun,
                $employeeId,
                $payrollId,
                $departmentId,
                $overtimeCode,
                $departmentsById,
                $overtimeHours > 0 ? $overtimeHours : null,
                $overtimeHours > 0 ? $hourlyRate * $otMultiplier : null,
                $overtimeAmount,
                $this->hoursByDepartment($employeeTimesheets, 'overtimeHours', $departmentId),
            );

            $this->createDistributedLine(
                $payrollRun,
                $employeeId,
                $payrollId,
                $departmentId,
                $holidayCode,
                $departmentsById,
                $holidayHours > 0 ? $holidayHours : null,
                $holidayHours > 0 ? $hourlyRate : null,
                $holidayAmount,
                $this->hoursByDepartment($employeeTimesheets, 'holidayHours', $departmentId),
            );

            $allowanceData = $row['_calculation']['allowances'] ?? [];
            foreach ($allowanceData['lines'] ?? [] as $allowanceLine) {
                $amount = round((float) ($allowanceLine['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }

                $assignedCodeId = $allowanceLine['payrollEarningCodeId'] ?? null;
                $earningCode = $assignedCodeId
                    ? ($codesById->get((int) $assignedCodeId) ?? PayrollEarningCode::query()->find($assignedCodeId))
                    : null;
                $earningCode ??= $allowanceFallback;

                $this->createLine(
                    $payrollRun,
                    $employeeId,
                    $payrollId,
                    $departmentId,
                    $earningCode instanceof PayrollEarningCode ? $earningCode : null,
                    $departmentsById,
                    null,
                    null,
                    $amount,
                    (string) ($allowanceLine['source'] ?? 'CALCULATED'),
                    isset($allowanceLine['sourceId']) ? (string) $allowanceLine['sourceId'] : null,
                    null,
                    filled($allowanceLine['accountId'] ?? null) ? (string) $allowanceLine['accountId'] : null,
                );
            }

            if ($vacationPayAmount > 0) {
                $this->createLine(
                    $payrollRun,
                    $employeeId,
                    $payrollId,
                    $departmentId,
                    $vacationCode,
                    $departmentsById,
                    isset($vacationPay['days']) && (float) $vacationPay['days'] > 0
                        ? (float) $vacationPay['days']
                        : null,
                    null,
                    $vacationPayAmount,
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
                    $departmentsById,
                    isset($alreadyPaidLeave['days']) && (float) $alreadyPaidLeave['days'] > 0
                        ? (float) $alreadyPaidLeave['days']
                        : null,
                    null,
                    $alreadyPaidAmount,
                    'ALREADY_PAID_LEAVE',
                    null,
                    'Vacation advance (already paid — not additional pay)',
                    $this->payrollAccountMappingService->accountIdFor('VACATION_PAY'),
                );
            }
        }
    }

    /**
     * Day / trip amounts stay on the department recorded for each entry.
     * They are capped so regular earning lines still add up to the regular amount.
     *
     * @param array<string, mixed> $row
     * @return list<array{departmentId: int|null, amount: float}>
     */
    private function dayWorkSlicesForRegular(array $row, float $regularAmount): array
    {
        $raw = $row['_calculation']['dayWorkByDepartment'] ?? [];
        if (! is_array($raw) || $regularAmount <= 0) {
            return [];
        }

        $slices = [];
        $total = 0.0;
        foreach ($raw as $slice) {
            if (! is_array($slice)) {
                continue;
            }

            $amount = round((float) ($slice['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $departmentId = isset($slice['departmentId']) && $slice['departmentId'] !== null
                ? (int) $slice['departmentId']
                : null;
            $slices[] = ['departmentId' => $departmentId, 'amount' => $amount];
            $total = round($total + $amount, 2);
        }

        if ($slices === [] || $total <= 0) {
            return [];
        }

        if ($total <= $regularAmount) {
            return $slices;
        }

        $scaled = [];
        $assigned = 0.0;
        $lastIndex = count($slices) - 1;
        foreach ($slices as $index => $slice) {
            if ($index === $lastIndex) {
                $amount = round($regularAmount - $assigned, 2);
            } else {
                $amount = round($regularAmount * ($slice['amount'] / $total), 2);
                $assigned = round($assigned + $amount, 2);
            }

            if ($amount > 0) {
                $scaled[] = ['departmentId' => $slice['departmentId'], 'amount' => $amount];
            }
        }

        return $scaled;
    }

    /**
     * One earning line, or one line per timesheet department when the earning code opts in.
     *
     * @param Collection<int, Department> $departmentsById
     * @param array<int, float> $hoursByDepartment
     */
    private function createDistributedLine(
        PayrollRun $payrollRun,
        string $employeeId,
        ?string $payrollId,
        ?int $fallbackDepartmentId,
        ?PayrollEarningCode $earningCode,
        Collection $departmentsById,
        ?float $hours,
        ?float $rate,
        float $amount,
        array $hoursByDepartment,
    ): void {
        if ($amount <= 0 || ! $earningCode) {
            return;
        }

        $slices = $earningCode->splitsCostByTimesheetDepartment()
            ? $this->timesheetDepartmentCostAllocator->allocate($amount, $hours, $hoursByDepartment, $fallbackDepartmentId)
            : [[
                'departmentId' => $fallbackDepartmentId,
                'hours' => $hours,
                'amount' => $amount,
            ]];

        foreach ($slices as $slice) {
            $sliceHours = $slice['hours'];
            $this->createLine(
                $payrollRun,
                $employeeId,
                $payrollId,
                $slice['departmentId'],
                $earningCode,
                $departmentsById,
                $sliceHours,
                $sliceHours !== null && $sliceHours > 0 ? $rate : null,
                (float) $slice['amount'],
            );
        }
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     * @return array<int, float>
     */
    private function hoursByDepartment(Collection $timesheets, string $field, ?int $fallbackDepartmentId): array
    {
        $weights = [];

        foreach ($timesheets as $timesheet) {
            $hours = round((float) ($timesheet->{$field} ?? 0), 2);
            if ($hours <= 0) {
                continue;
            }

            $departmentId = $timesheet->departmentId !== null
                ? (int) $timesheet->departmentId
                : $fallbackDepartmentId;
            if ($departmentId === null) {
                continue;
            }

            $weights[$departmentId] = round(($weights[$departmentId] ?? 0) + $hours, 2);
        }

        return $weights;
    }

    /**
     * @param Collection<int, Department> $departmentsById
     */
    private function createLine(
        PayrollRun $payrollRun,
        string $employeeId,
        ?string $payrollId,
        ?int $departmentId,
        ?PayrollEarningCode $earningCode,
        Collection $departmentsById,
        ?float $hours,
        ?float $rate,
        float $amount,
        string $sourceType = 'CALCULATED',
        ?string $sourceId = null,
        ?string $note = null,
        ?string $accountOverride = null,
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
            'accountId' => $this->resolvePostingAccountId(
                $earningCode,
                $departmentId,
                $departmentsById,
                $accountOverride,
            ),
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
    private function resolvePostingAccountId(
        PayrollEarningCode $earningCode,
        ?int $departmentId,
        Collection $departmentsById,
        ?string $accountOverride,
    ): ?string {
        if (filled($accountOverride)) {
            return $accountOverride;
        }

        if ($earningCode->usesDepartmentAccount() && $departmentId !== null) {
            $departmentAccountId = $departmentsById->get($departmentId)?->accountId;
            if (filled($departmentAccountId)) {
                return (string) $departmentAccountId;
            }
        }

        if (filled($earningCode->account_id)) {
            return (string) $earningCode->account_id;
        }

        if ($earningCode->source === PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK) {
            return $this->payrollAccountMappingService->accountIdFor('ALLOWANCES');
        }

        if ($earningCode->usesDepartmentAccount() || $earningCode->source === PayrollEarningCode::SOURCE_VACATION) {
            return $this->payrollAccountMappingService->accountIdFor('DEPARTMENT_WAGES')
                ?? $this->payrollAccountMappingService->accountIdFor('VACATION_PAY');
        }

        return null;
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
