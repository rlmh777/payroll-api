<?php

namespace App\Modules\Payroll\Services;

use App\Enums\LeaveStatusCode;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeLeave;
use App\Models\EmploymentDetail;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Flat-period base / salary employees (e.g. BASE_NO_OT) are included on payroll
 * from employment + compensation alone — no schedule or timesheet required.
 */
class PayrollFlatBaseScopeService
{
    public function __construct(
        private readonly EmployeeCompensationResolver $compensationResolver,
    ) {
    }

    /**
     * @return list<string>
     */
    public function employeeIds(
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $frequencyId = null,
    ): array {
        if ($payPeriodGroupId === '') {
            return [];
        }

        $expected = $this->loadExpectedEmployees($startDate, $endDate, $payPeriodGroupId, $frequencyId);
        $ids = [];

        foreach ($expected as $employeeId => $context) {
            $compensation = $context['compensation'];
            if (! $this->compensationResolver->usesFlatPeriodBasePay($compensation, $frequencyId)) {
                continue;
            }

            $ids[] = $employeeId;
        }

        return $ids;
    }

    /**
     * Active PPG employment + compensation context for one employee (end-of-period).
     *
     * @return array{
     *     employmentDetailId: string|null,
     *     employeeCompensationId: string|null,
     *     departmentId: int|null
     * }|null
     */
    public function employmentContext(
        string $employeeId,
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $frequencyId = null,
    ): ?array {
        $expected = $this->loadExpectedEmployees($startDate, $endDate, $payPeriodGroupId, $frequencyId);
        $context = $expected[$employeeId] ?? null;
        if ($context === null) {
            return null;
        }

        /** @var EmploymentDetail $employmentDetail */
        $employmentDetail = $context['employmentDetail'];
        $compensation = $context['compensation'];

        return [
            'employmentDetailId' => (string) $employmentDetail->id,
            'employeeCompensationId' => $compensation?->id ? (string) $compensation->id : null,
            'departmentId' => $employmentDetail->departmentId !== null
                ? (int) $employmentDetail->departmentId
                : null,
        ];
    }

    /**
     * Vacation / leave already paid in advance — amount to attribute to vacation expense
     * (and clear against leave-advance liability) without increasing net pay.
     *
     * @return array{
     *     amount: float,
     *     days: int,
     *     periodDays: int,
     *     lines: list<array{
     *         leaveId: string,
     *         leaveTypeName: string,
     *         startDate: string,
     *         endDate: string,
     *         days: int,
     *         amount: float
     *     }>
     * }
     */
    public function alreadyPaidLeaveAttribution(
        string $employeeId,
        Carbon $startDate,
        Carbon $endDate,
        ?float $flatPeriodBasePay,
    ): array {
        $empty = [
            'amount' => 0.0,
            'days' => 0,
            'periodDays' => max(1, $startDate->diffInDays($endDate) + 1),
            'lines' => [],
        ];

        if ($flatPeriodBasePay === null || $flatPeriodBasePay <= 0) {
            return $empty;
        }

        $leaves = EmployeeLeave::query()
            ->with('leaveType')
            ->where('employeeId', $employeeId)
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::activeAbsenceStatuses(),
            )))
            ->whereDate('startDate', '<=', $endDate->toDateString())
            ->whereDate('endDate', '>=', $startDate->toDateString())
            ->whereRaw('LOWER(COALESCE("paymentTreatment", \'\')) = ?', ['already_paid'])
            ->get();

        $periodDays = max(1, $startDate->diffInDays($endDate) + 1);
        $dailyRate = round($flatPeriodBasePay / $periodDays, 4);
        $lines = [];
        $totalDays = 0;
        $totalAmount = 0.0;
        $seenDates = [];

        foreach ($leaves as $leave) {
            $cursor = Carbon::parse($leave->startDate)->max($startDate)->startOfDay();
            $leaveEnd = Carbon::parse($leave->endDate)->min($endDate)->startOfDay();
            $days = 0;

            while ($cursor->lte($leaveEnd)) {
                $day = $cursor->toDateString();
                if (! isset($seenDates[$day])) {
                    $seenDates[$day] = true;
                    $days++;
                }
                $cursor->addDay();
            }

            if ($days <= 0) {
                continue;
            }

            $amount = round($dailyRate * $days, 2);
            $totalDays += $days;
            $totalAmount = round($totalAmount + $amount, 2);

            $lines[] = [
                'leaveId' => (string) $leave->id,
                'leaveTypeName' => $leave->leaveType?->name ?? 'Leave',
                'startDate' => Carbon::parse($leave->startDate)->toDateString(),
                'endDate' => Carbon::parse($leave->endDate)->toDateString(),
                'days' => $days,
                'amount' => $amount,
            ];
        }

        return [
            'amount' => $totalAmount,
            'days' => $totalDays,
            'periodDays' => $periodDays,
            'lines' => $lines,
        ];
    }

    /**
     * Payable vacation leave in the period — still paid in cash on this run, but expense
     * should post to the vacation account instead of department wages (when enabled).
     *
     * @return array{
     *     amount: float,
     *     days: int,
     *     periodDays: int,
     *     lines: list<array{
     *         leaveId: string,
     *         leaveTypeName: string,
     *         startDate: string,
     *         endDate: string,
     *         days: int,
     *         amount: float
     *     }>
     * }
     */
    public function payableVacationLeaveAttribution(
        string $employeeId,
        Carbon $startDate,
        Carbon $endDate,
        ?float $flatPeriodBasePay,
        ?string $currentPayrollRunId = null,
    ): array {
        $empty = [
            'amount' => 0.0,
            'days' => 0,
            'periodDays' => max(1, $startDate->diffInDays($endDate) + 1),
            'lines' => [],
        ];

        if ($flatPeriodBasePay === null || $flatPeriodBasePay <= 0) {
            return $empty;
        }

        $leaves = EmployeeLeave::query()
            ->with('leaveType')
            ->where('employeeId', $employeeId)
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::activeAbsenceStatuses(),
            )))
            ->whereDate('startDate', '<=', $endDate->toDateString())
            ->whereDate('endDate', '>=', $startDate->toDateString())
            ->get();

        $periodDays = max(1, $startDate->diffInDays($endDate) + 1);
        $dailyRate = round($flatPeriodBasePay / $periodDays, 4);
        $lines = [];
        $totalDays = 0;
        $totalAmount = 0.0;
        $seenDates = [];

        foreach ($leaves as $leave) {
            if ($this->leaveIsNonPayableOnRun($leave, $currentPayrollRunId)) {
                continue;
            }

            if (! $leave->leaveType?->isVacation()) {
                continue;
            }

            $multiplier = (float) ($leave->multiplier ?? 1);
            if ($multiplier <= 0) {
                continue;
            }

            $cursor = Carbon::parse($leave->startDate)->max($startDate)->startOfDay();
            $leaveEnd = Carbon::parse($leave->endDate)->min($endDate)->startOfDay();
            $days = 0;

            while ($cursor->lte($leaveEnd)) {
                $day = $cursor->toDateString();
                if (! isset($seenDates[$day])) {
                    $seenDates[$day] = true;
                    $days++;
                }
                $cursor->addDay();
            }

            if ($days <= 0) {
                continue;
            }

            $amount = round($dailyRate * $days * $multiplier, 2);
            $totalDays += $days;
            $totalAmount = round($totalAmount + $amount, 2);

            $lines[] = [
                'leaveId' => (string) $leave->id,
                'leaveTypeName' => $leave->leaveType?->name ?? 'Vacation',
                'startDate' => Carbon::parse($leave->startDate)->toDateString(),
                'endDate' => Carbon::parse($leave->endDate)->toDateString(),
                'days' => $days,
                'amount' => $amount,
            ];
        }

        return [
            'amount' => $totalAmount,
            'days' => $totalDays,
            'periodDays' => $periodDays,
            'lines' => $lines,
        ];
    }

    /**
     * Leave calendar days in the period that must not be paid again on this run:
     * unpaid leave, already paid in advance, or leave already linked to another payroll run.
     */
    public function nonPayableLeaveDaysInPeriod(
        string $employeeId,
        Carbon $startDate,
        Carbon $endDate,
        ?string $currentPayrollRunId = null,
    ): int {
        $leaves = EmployeeLeave::query()
            ->with('leaveType')
            ->where('employeeId', $employeeId)
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::activeAbsenceStatuses(),
            )))
            ->whereDate('startDate', '<=', $endDate->toDateString())
            ->whereDate('endDate', '>=', $startDate->toDateString())
            ->get();

        $dates = [];

        foreach ($leaves as $leave) {
            if (! $this->leaveIsNonPayableOnRun($leave, $currentPayrollRunId)) {
                continue;
            }

            $cursor = Carbon::parse($leave->startDate)->max($startDate)->startOfDay();
            $leaveEnd = Carbon::parse($leave->endDate)->min($endDate)->startOfDay();

            while ($cursor->lte($leaveEnd)) {
                $dates[$cursor->toDateString()] = true;
                $cursor->addDay();
            }
        }

        return count($dates);
    }

    /**
     * @deprecated Use nonPayableLeaveDaysInPeriod()
     */
    public function unpaidLeaveDaysInPeriod(
        string $employeeId,
        Carbon $startDate,
        Carbon $endDate,
        ?string $currentPayrollRunId = null,
    ): int {
        return $this->nonPayableLeaveDaysInPeriod($employeeId, $startDate, $endDate, $currentPayrollRunId);
    }

    private function leaveIsNonPayableOnRun(EmployeeLeave $leave, ?string $currentPayrollRunId): bool
    {
        $treatment = strtolower(trim((string) ($leave->paymentTreatment ?? '')));
        if (in_array($treatment, ['unpaid', 'already_paid'], true)) {
            return true;
        }

        if ((float) ($leave->multiplier ?? 1) <= 0) {
            return true;
        }

        if ($leave->leaveType && $leave->leaveType->isPaid === false) {
            return true;
        }

        $paidOnRunId = $leave->paidInPayrollRunId ? (string) $leave->paidInPayrollRunId : null;
        if ($paidOnRunId !== null && $paidOnRunId !== '' && $paidOnRunId !== (string) $currentPayrollRunId) {
            // Leave pay was already attributed to another payroll run — do not pay again.
            return true;
        }

        return false;
    }

    /**
     * @return array<string, array{employmentDetail: EmploymentDetail, compensation: EmployeeCompensation|null}>
     */
    private function loadExpectedEmployees(
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $frequencyId,
    ): array {
        $rows = EmploymentDetail::query()
            ->with([
                'employee.employeeCompensations' => function ($query) use ($startDate, $endDate) {
                    $query
                        ->where('isActive', true)
                        ->whereDate('effectiveDate', '<=', $endDate->toDateString())
                        ->where(function ($inner) use ($startDate) {
                            $inner->whereNull('endDate')
                                ->orWhereDate('endDate', '>=', $startDate->toDateString());
                        })
                        ->orderByDesc('effectiveDate');
                },
            ])
            ->where('isActive', true)
            ->where('defaultPayPeriodGroupId', $payPeriodGroupId)
            ->whereDate('startDate', '<=', $endDate->toDateString())
            ->where(function ($query) use ($startDate) {
                $query->whereNull('endDate')
                    ->orWhereDate('endDate', '>=', $startDate->toDateString());
            })
            ->whereHas('employee', function ($employeeQuery) use ($frequencyId) {
                $employeeQuery->whereHas('employeeStatus', function ($statusQuery) {
                    $statusQuery->whereRaw('LOWER(name) = ?', ['active']);
                });

                if ($frequencyId !== null) {
                    $employeeQuery->where('payrateFrequencyId', $frequencyId);
                }
            })
            ->whereHas('employee.employeeCompensations', function ($compensationQuery) use ($startDate, $endDate) {
                $compensationQuery
                    ->where('isActive', true)
                    ->whereDate('effectiveDate', '<=', $endDate->toDateString())
                    ->where(function ($inner) use ($startDate) {
                        $inner->whereNull('endDate')
                            ->orWhereDate('endDate', '>=', $startDate->toDateString());
                    });
            })
            ->orderByDesc('startDate')
            ->get();

        $expected = [];

        foreach ($rows as $employmentDetail) {
            $employeeId = (string) $employmentDetail->employeeId;

            if (isset($expected[$employeeId])) {
                continue;
            }

            $compensation = $employmentDetail->employee?->employeeCompensations?->first();

            $expected[$employeeId] = [
                'employmentDetail' => $employmentDetail,
                'compensation' => $compensation instanceof EmployeeCompensation ? $compensation : null,
            ];
        }

        return $expected;
    }
}
