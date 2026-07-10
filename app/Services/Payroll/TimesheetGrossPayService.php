<?php

namespace App\Services\Payroll;

use App\Enums\CompensationMethod;
use App\Models\EmployeeCompensation;
use App\Models\Timesheet;
use App\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
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

        if ($flatPeriodBasePay !== null) {
            return [
                'baseEarnings' => $flatPeriodBasePay,
                'regularHours' => $regularHours,
                'overtimeHours' => $overtimeHours,
                'holidayHours' => $holidayHours,
                'employmentDetailId' => $employmentDetailId,
                'employeeCompensationId' => $employeeCompensationId,
                'departmentId' => $departmentId,
            ];
        }

        $baseEarnings = 0.0;
        foreach ($timesheets as $timesheet) {
            $baseEarnings = round($baseEarnings + $this->payForTimesheet($timesheet), 2);
        }

        return [
            'baseEarnings' => $baseEarnings,
            'regularHours' => $regularHours,
            'overtimeHours' => $overtimeHours,
            'holidayHours' => $holidayHours,
            'employmentDetailId' => $employmentDetailId,
            'employeeCompensationId' => $employeeCompensationId,
            'departmentId' => $departmentId,
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
