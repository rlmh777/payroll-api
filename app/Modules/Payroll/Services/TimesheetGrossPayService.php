<?php

namespace App\Modules\Payroll\Services;

use App\Enums\CompensationMethod;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeDayWork;
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
     *     departmentId: int|null
     * }
     */
    public function forEmployee(
        string $employeeId,
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $payrateFrequencyId = null,
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
        $dayWorkEarnings = $this->dayWorkEarningsForEmployee(
            $employeeId,
            $startDate,
            $endDate,
            $payPeriodGroupId,
            $payrateFrequencyId,
        );

        if ($flatPeriodBasePay !== null) {
            return [
                'baseEarnings' => round($flatPeriodBasePay + $dayWorkEarnings['baseEarnings'], 2),
                'regularHours' => $regularHours,
                'overtimeHours' => $overtimeHours,
                'holidayHours' => $holidayHours,
                'employmentDetailId' => $employmentDetailId ?? $dayWorkEarnings['employmentDetailId'],
                'employeeCompensationId' => $employeeCompensationId ?? $dayWorkEarnings['employeeCompensationId'],
                'departmentId' => $departmentId ?? $dayWorkEarnings['departmentId'],
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
    ): array {
        $results = [];

        foreach ($employeeIds as $employeeId) {
            $results[(string) $employeeId] = $this->forEmployee(
                (string) $employeeId,
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $payrateFrequencyId,
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
     *     departmentId: int|null
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
            ->orderBy('date')
            ->get();

        $baseEarnings = 0.0;
        $employmentDetailId = null;
        $employeeCompensationId = null;
        $departmentId = null;

        foreach ($entries as $entry) {
            $baseEarnings = round($baseEarnings + (float) $entry->amount, 2);
            $employmentDetailId ??= $entry->employmentDetailId ? (string) $entry->employmentDetailId : null;
            $employeeCompensationId ??= $entry->employeeCompensationId ? (string) $entry->employeeCompensationId : null;
            $departmentId ??= $entry->departmentId !== null ? (int) $entry->departmentId : null;
        }

        return [
            'baseEarnings' => $baseEarnings,
            'employmentDetailId' => $employmentDetailId,
            'employeeCompensationId' => $employeeCompensationId,
            'departmentId' => $departmentId,
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
