<?php

namespace App\Modules\Payroll\Services;

use App\Enums\CompensationMethod;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeDayWork;
use App\Models\PayrollSetting;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TimesheetGrossPayService
{
    public function __construct(
        private readonly PayrollTimesheetScopeService $payrollTimesheetScopeService,
        private readonly EmployeeCompensationResolver $compensationResolver,
        private readonly PayrollFlatBaseScopeService $payrollFlatBaseScopeService,
    ) {
    }

    /**
     * @return array{
     *     baseEarnings: float,
     *     regularHours: float,
     *     overtimeHours: float,
     *     holidayHours: float,
     *     employmentDetailId: string|null,
     *     employeeCompensationId: string|null,
     *     departmentId: int|null,
     *     dayWorkByDepartment: list<array{departmentId: int|null, amount: float}>,
     *     alreadyPaidLeave: array{
     *         amount: float,
     *         days: int,
     *         periodDays: int,
     *         lines: list<array<string, mixed>>
     *     },
     *     vacationPay: array{
     *         amount: float,
     *         days: int,
     *         periodDays: int,
     *         lines: list<array<string, mixed>>
     *     }
     * }
     */
    public function forEmployee(
        string $employeeId,
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $payrateFrequencyId = null,
        ?string $payrollRunId = null,
    ): array {
        $timesheets = $this->payrollTimesheetScopeService
            ->apply(
                Timesheet::query()->where('employeeId', $employeeId),
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $payrateFrequencyId,
            )
            ->whereRaw('UPPER("approvalStatus") = ?', ['APPROVED'])
            ->orderBy('date')
            ->get();

        $regularHours = 0.0;
        $overtimeHours = 0.0;
        $holidayHours = 0.0;
        $employmentDetailId = null;
        $employeeCompensationId = null;
        $departmentId = null;

        foreach ($timesheets as $timesheet) {
            $regularHours = round($regularHours + (float) $timesheet->regularHours, 2);
            $overtimeHours = round($overtimeHours + (float) $timesheet->overtimeHours, 2);
            $holidayHours = round($holidayHours + (float) $timesheet->holidayHours, 2);
            $employmentDetailId ??= $timesheet->employmentDetailId ? (string) $timesheet->employmentDetailId : null;
            $employeeCompensationId ??= $timesheet->employeeCompensationId ? (string) $timesheet->employeeCompensationId : null;
            $departmentId ??= $timesheet->departmentId !== null ? (int) $timesheet->departmentId : null;
        }

        $compensation = $this->resolveCompensation(
            $employeeId,
            $endDate,
            $employmentDetailId,
            $employeeCompensationId,
        );
        $flatPeriodBasePay = $this->compensationResolver->flatPeriodBasePay($compensation, $payrateFrequencyId);
        $leaveDailyRate = $this->compensationResolver->leaveDailyRate(
            $compensation,
            $payrateFrequencyId,
            $startDate,
            $endDate,
        );
        $alreadyPaidLeave = $this->payrollFlatBaseScopeService->alreadyPaidLeaveAttribution(
            $employeeId,
            $startDate,
            $endDate,
            $leaveDailyRate,
        );
        $dayWorkEarnings = $this->dayWorkEarningsForEmployee(
            $employeeId,
            $startDate,
            $endDate,
            $payPeriodGroupId,
            $payrateFrequencyId,
        );

        if ($flatPeriodBasePay !== null) {
            $periodDays = max(1, $startDate->diffInDays($endDate) + 1);
            $nonPayableLeaveDays = $this->payrollFlatBaseScopeService->nonPayableLeaveDaysInPeriod(
                $employeeId,
                $startDate,
                $endDate,
                $payrollRunId,
            );
            $payableDays = max(0, $periodDays - $nonPayableLeaveDays);
            $adjustedFlatPay = $nonPayableLeaveDays > 0
                ? round($flatPeriodBasePay * ($payableDays / $periodDays), 2)
                : $flatPeriodBasePay;

            $vacationPay = [
                'amount' => 0.0,
                'days' => 0,
                'periodDays' => $periodDays,
                'lines' => [],
            ];
            if (PayrollSetting::postVacationPayToVacationAccount()) {
                $vacationPay = $this->payrollFlatBaseScopeService->payableVacationLeaveAttribution(
                    $employeeId,
                    $startDate,
                    $endDate,
                    $flatPeriodBasePay,
                    $payrollRunId,
                );
            }

            $employmentContext = null;
            if ($employmentDetailId === null || $employeeCompensationId === null || $departmentId === null) {
                $employmentContext = $this->payrollFlatBaseScopeService->employmentContext(
                    $employeeId,
                    $startDate,
                    $endDate,
                    $payPeriodGroupId,
                    $payrateFrequencyId,
                );
            }

            return [
                'baseEarnings' => round($adjustedFlatPay + $dayWorkEarnings['baseEarnings'], 2),
                'regularHours' => $regularHours,
                'overtimeHours' => $overtimeHours,
                'holidayHours' => $holidayHours,
                'employmentDetailId' => $employmentDetailId
                    ?? $dayWorkEarnings['employmentDetailId']
                    ?? $employmentContext['employmentDetailId']
                    ?? null,
                'employeeCompensationId' => $employeeCompensationId
                    ?? $dayWorkEarnings['employeeCompensationId']
                    ?? $employmentContext['employeeCompensationId']
                    ?? null,
                'departmentId' => $departmentId
                    ?? $dayWorkEarnings['departmentId']
                    ?? $employmentContext['departmentId']
                    ?? null,
                'dayWorkByDepartment' => $dayWorkEarnings['byDepartment'],
                'alreadyPaidLeave' => $alreadyPaidLeave,
                'vacationPay' => $vacationPay,
            ];
        }

        $baseEarnings = 0.0;
        foreach ($timesheets as $timesheet) {
            $baseEarnings = round($baseEarnings + $this->payForTimesheet($timesheet), 2);
        }

        return [
            'baseEarnings' => round($baseEarnings + $dayWorkEarnings['baseEarnings'], 2),
            'regularHours' => $regularHours,
            'overtimeHours' => $overtimeHours,
            'holidayHours' => $holidayHours,
            'employmentDetailId' => $employmentDetailId ?? $dayWorkEarnings['employmentDetailId'],
            'employeeCompensationId' => $employeeCompensationId ?? $dayWorkEarnings['employeeCompensationId'],
            'departmentId' => $departmentId ?? $dayWorkEarnings['departmentId'],
            'dayWorkByDepartment' => $dayWorkEarnings['byDepartment'],
            'alreadyPaidLeave' => $alreadyPaidLeave,
            'vacationPay' => [
                'amount' => 0.0,
                'days' => 0,
                'periodDays' => max(1, $startDate->diffInDays($endDate) + 1),
                'lines' => [],
            ],
        ];
    }

    /**
     * @param Collection<int, string> $employeeIds
     * @return array<string, array<string, mixed>>
     */
    public function forEmployees(
        Collection $employeeIds,
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $payrateFrequencyId = null,
        ?string $payrollRunId = null,
    ): array {
        $results = [];

        foreach ($employeeIds as $employeeId) {
            $results[(string) $employeeId] = $this->forEmployee(
                (string) $employeeId,
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $payrateFrequencyId,
                $payrollRunId,
            );
        }

        return $results;
    }

    public function payForTimesheet(Timesheet $timesheet): float
    {
        if (!(bool) $timesheet->isPaid) {
            return 0.0;
        }

        $method = CompensationMethod::fromStored($timesheet->payType);
        $hourlyRate = $this->resolveHourlyRate($timesheet);

        if ($hourlyRate <= 0) {
            return 0.0;
        }

        $regular = (float) $timesheet->regularHours;
        $overtime = (float) $timesheet->overtimeHours;
        $holiday = (float) $timesheet->holidayHours;
        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);

        if ($method->allowsOvertime()) {
            return round(
                ($regular * $hourlyRate)
                + ($overtime * $hourlyRate * $otMultiplier)
                + ($holiday * $hourlyRate),
                2,
            );
        }

        return round(($regular * $hourlyRate) + ($holiday * $hourlyRate), 2);
    }

    private function resolveHourlyRate(Timesheet $timesheet): float
    {
        $hourlyRate = (float) ($timesheet->hourlyRate ?? 0);

        if ($hourlyRate > 0) {
            return $hourlyRate;
        }

        $baseSalary = (float) ($timesheet->baseSalary ?? 0);

        if ($baseSalary > 0) {
            return $this->compensationResolver->hourlyRateFromBaseSalary($baseSalary);
        }

        return 0.0;
    }

    /**
     * @return array{
     *     baseEarnings: float,
     *     employmentDetailId: string|null,
     *     employeeCompensationId: string|null,
     *     departmentId: int|null,
     *     byDepartment: list<array{departmentId: int|null, amount: float}>
     * }
     */
    public function dayWorkEarningsForEmployee(
        string $employeeId,
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $payrateFrequencyId = null,
    ): array {
        $entries = $this->dayWorkScopeQuery(
            $startDate,
            $endDate,
            $payPeriodGroupId,
            $payrateFrequencyId,
        )
            ->where('employeeId', $employeeId)
            ->whereRaw('UPPER("approvalStatus") = ?', ['APPROVED'])
            ->with(['employmentDetail:id,departmentId'])
            ->orderBy('date')
            ->get();

        $baseEarnings = 0.0;
        $employmentDetailId = null;
        $employeeCompensationId = null;
        $homeDepartmentId = null;
        $amountsByDepartment = [];

        foreach ($entries as $entry) {
            $amount = round((float) $entry->amount, 2);
            $baseEarnings = round($baseEarnings + $amount, 2);
            $employmentDetailId ??= $entry->employmentDetailId ? (string) $entry->employmentDetailId : null;
            $employeeCompensationId ??= $entry->employeeCompensationId ? (string) $entry->employeeCompensationId : null;

            $contractDepartmentId = $entry->employmentDetail?->departmentId;
            if ($homeDepartmentId === null && $contractDepartmentId !== null) {
                $homeDepartmentId = (int) $contractDepartmentId;
            }

            $postingDepartmentId = $entry->departmentId !== null
                ? (int) $entry->departmentId
                : ($contractDepartmentId !== null ? (int) $contractDepartmentId : null);
            if ($amount <= 0) {
                continue;
            }

            $key = $postingDepartmentId ?? 0;
            $amountsByDepartment[$key] = round(($amountsByDepartment[$key] ?? 0) + $amount, 2);
        }

        $byDepartment = [];
        foreach ($amountsByDepartment as $departmentKey => $amount) {
            $byDepartment[] = [
                'departmentId' => (int) $departmentKey > 0 ? (int) $departmentKey : null,
                'amount' => $amount,
            ];
        }

        return [
            'baseEarnings' => $baseEarnings,
            'employmentDetailId' => $employmentDetailId,
            'employeeCompensationId' => $employeeCompensationId,
            'departmentId' => $homeDepartmentId ?? ($byDepartment[0]['departmentId'] ?? null),
            'byDepartment' => $byDepartment,
        ];
    }

    /**
     * @return list<string>
     */
    public function dayWorkEmployeeIds(
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $payrateFrequencyId = null,
    ): array {
        return $this->dayWorkScopeQuery($startDate, $endDate, $payPeriodGroupId, $payrateFrequencyId)
            ->whereRaw('UPPER("approvalStatus") = ?', ['APPROVED'])
            ->distinct()
            ->pluck('employeeId')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();
    }

    private function dayWorkScopeQuery(
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $payrateFrequencyId = null,
    ): Builder {
        $query = EmployeeDayWork::query()
            ->whereDate('date', '>=', $startDate->toDateString())
            ->whereDate('date', '<=', $endDate->toDateString())
            ->whereHas('employmentDetail', function (Builder $employmentQuery) use ($payPeriodGroupId, $startDate, $endDate) {
                $employmentQuery
                    ->where('defaultPayPeriodGroupId', $payPeriodGroupId)
                    ->where('isActive', true)
                    ->whereDate('startDate', '<=', $endDate->toDateString())
                    ->where(function (Builder $inner) use ($startDate) {
                        $inner->whereNull('endDate')
                            ->orWhereDate('endDate', '>=', $startDate->toDateString());
                    });
            });

        if ($payrateFrequencyId !== null) {
            $query->whereHas('employee', function (Builder $employeeQuery) use ($payrateFrequencyId) {
                $employeeQuery->where('payrateFrequencyId', $payrateFrequencyId);
            });
        }

        return $query;
    }

    private function resolveCompensation(
        string $employeeId,
        Carbon $asOfDate,
        ?string $employmentDetailId,
        ?string $employeeCompensationId,
    ): ?EmployeeCompensation {
        if ($employeeCompensationId) {
            $record = EmployeeCompensation::query()->find($employeeCompensationId);
            if ($record) {
                return $record;
            }
        }

        $records = EmployeeCompensation::query()
            ->where('employeeId', $employeeId)
            ->orderByDesc('effectiveDate')
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        return $this->compensationResolver->compensationForDate(
            $records,
            $asOfDate,
            $employmentDetailId,
        );
    }
}
