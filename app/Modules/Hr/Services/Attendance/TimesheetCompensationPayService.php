<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\CompensationMethod;
use App\Models\Employee;
use App\Models\EmploymentDetail;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetCompensationPayService
{
    public function __construct(
        private readonly CompensationTimesheetHoursService $compensationHoursService,
        private readonly TimesheetScheduledHoursResolver $scheduledHoursResolver,
        private readonly EmployeeCompensationResolver $compensationResolver,
        private readonly TimesheetOvertimeAllocator $overtimeAllocator,
        private readonly PublicHolidayPayResolver $holidayPayResolver,
    ) {
    }

    public function syncPayContext(Timesheet $timesheet): void
    {
        if (!$timesheet->employeeId || !$timesheet->date) {
            return;
        }

        $employee = Employee::query()
            ->with(['employeeCompensations', 'employmentDetails'])
            ->find($timesheet->employeeId);

        if (!$employee) {
            return;
        }

        $workDate = Carbon::parse($timesheet->date);
        $employmentDetail = $this->employmentDetailForDate(
            $employee->employmentDetails,
            $workDate,
            $timesheet->employmentDetailId ? (string) $timesheet->employmentDetailId : null,
            $timesheet->departmentId ? (int) $timesheet->departmentId : null,
        );
        $compensation = $this->compensationResolver->compensationForDate(
            $employee->employeeCompensations,
            $workDate,
            $employmentDetail?->id ? (string) $employmentDetail->id : null,
        );
        $snapshot = $this->compensationResolver->payrollSnapshot($compensation);

        $timesheet->payType = $snapshot['payType'] ?? $timesheet->payType;
        $timesheet->hourlyRate = $snapshot['hourlyRate'] ?? $timesheet->hourlyRate;
        $timesheet->weeklySalary = $snapshot['weeklySalary'] ?? $timesheet->weeklySalary;
        $timesheet->baseSalary = $snapshot['baseSalary'] ?? $timesheet->baseSalary;

        if ($employmentDetail?->departmentId) {
            $timesheet->employmentDetailId = $employmentDetail->id;
            $timesheet->departmentId = $employmentDetail->departmentId;
        }
        $timesheet->employeeCompensationId = $compensation?->id;

        if ($employmentDetail?->worksiteId) {
            $timesheet->worksiteId = $employmentDetail->worksiteId;
        }
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    public function syncPayContextForCollection(Collection $timesheets): void
    {
        $timesheets->each(function (Timesheet $timesheet) {
            $this->syncPayContext($timesheet);
            $timesheet->save();
        });
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    public function redistributeCollection(Collection $timesheets): void
    {
        $this->overtimeAllocator->redistributeCollection($timesheets);
    }

    /**
     * @return array{
     *   hoursWorked:float,
     *   regularHours:float,
     *   overtimeHours:float,
     *   holidayHours:float,
     *   unpaidHours:float,
     *   workingStatus:string,
     *   clockedHoursWorked:float
     * }
     */
    public function applyToTimesheet(
        Timesheet $timesheet,
        float $clockedHours,
        ?Carbon $roundOffIn = null,
        ?Carbon $roundOffOut = null,
        ?float $holidayPayMultiplier = null,
        bool $isUnpaidLeave = false,
    ): array {
        $this->syncPayContext($timesheet);

        $method = CompensationMethod::fromStored($timesheet->payType);
        $dailyScheduledHours = $this->scheduledHoursResolver->forTimesheet($timesheet);
        $slotScheduledHours = $this->scheduledHoursResolver->forTimesheetSlot($timesheet);
        $requiresClocking = $this->resolveRequiresClocking($timesheet, $method);
        $hasClockTimes = $roundOffIn && $roundOffOut && $roundOffOut->gt($roundOffIn);
        $holidayPayMultiplier ??= $this->holidayPayResolver->payMultiplierForDate(Carbon::parse($timesheet->date));

        if (!$hasClockTimes) {
            $payHours = $this->compensationHoursService->buildNoPunchDay(
                $method,
                $requiresClocking,
                $dailyScheduledHours,
                $holidayPayMultiplier,
                $isUnpaidLeave,
            );

            return array_merge($payHours, [
                'clockedHoursWorked' => 0.0,
            ]);
        }

        $slot = $this->compensationHoursService->applyToSlot(
            [
                'clockInTime' => $roundOffIn->format('Y-m-d H:i:s'),
                'clockOutTime' => $roundOffOut->format('Y-m-d H:i:s'),
                'clockedHoursWorked' => $clockedHours,
                'hoursWorked' => $clockedHours,
            ],
            $method,
            $requiresClocking,
            $method->isBaseBased() ? $dailyScheduledHours : $slotScheduledHours,
            $holidayPayMultiplier,
            $isUnpaidLeave,
        );

        return [
            'hoursWorked' => round((float) ($slot['hoursWorked'] ?? 0), 2),
            'regularHours' => round((float) ($slot['regularHours'] ?? 0), 2),
            'overtimeHours' => round((float) ($slot['overtimeHours'] ?? 0), 2),
            'holidayHours' => round((float) ($slot['holidayHours'] ?? 0), 2),
            'unpaidHours' => round((float) ($slot['unpaidHours'] ?? 0), 2),
            'isPaid' => (bool) ($slot['isPaid'] ?? true),
            'paidHours' => round((float) ($slot['paidHours'] ?? 0), 2),
            'workingStatus' => (string) ($slot['workingStatus'] ?? 'REGULAR'),
            'clockedHoursWorked' => round((float) ($slot['clockedHoursWorked'] ?? $clockedHours), 2),
        ];
    }

    public function finalizeTimesheetPay(Timesheet $timesheet, Carbon $workDate): void
    {
        if (!$timesheet->employeeId) {
            return;
        }

        $weekStart = $workDate->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $workDate->copy()->endOfWeek(Carbon::SUNDAY);

        Timesheet::query()
            ->where('employeeId', $timesheet->employeeId)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get()
            ->each(function (Timesheet $weekTimesheet) {
                $this->syncPayContext($weekTimesheet);
                $weekTimesheet->save();
            });

        $this->overtimeAllocator->redistributeEmployeeWeek((string) $timesheet->employeeId, $workDate);
        $timesheet->refresh();
    }

    /**
     * @param Collection<int, EmploymentDetail> $details
     */
    private function employmentDetailForDate(
        Collection $details,
        Carbon $date,
        ?string $employmentDetailId = null,
        ?int $departmentId = null,
    ): ?EmploymentDetail {
        $matching = $details->filter(function (EmploymentDetail $detail) use ($date) {
            $startDate = Carbon::parse($detail->startDate)->startOfDay();
            $endDate = $detail->endDate ? Carbon::parse($detail->endDate)->startOfDay() : null;

            return $startDate->lte($date) && (!$endDate || $endDate->gte($date));
        });

        if ($employmentDetailId) {
            $byId = $matching->first(fn (EmploymentDetail $detail) => (string) $detail->id === $employmentDetailId);
            if ($byId) {
                return $byId;
            }
        }

        if ($departmentId) {
            $byDepartment = $matching->first(fn (EmploymentDetail $detail) => (int) $detail->departmentId === $departmentId);
            if ($byDepartment) {
                return $byDepartment;
            }
        }

        return $matching
            ->sort(function (EmploymentDetail $left, EmploymentDetail $right) {
                if ($left->isActive !== $right->isActive) {
                    return $right->isActive <=> $left->isActive;
                }

                return Carbon::parse($right->startDate)->timestamp
                    <=> Carbon::parse($left->startDate)->timestamp;
            })
            ->first();
    }

    private function resolveRequiresClocking(Timesheet $timesheet, CompensationMethod $method): bool
    {
        $employee = Employee::query()
            ->with(['employeeCompensations', 'employmentDetails'])
            ->find($timesheet->employeeId);

        if (!$employee) {
            return $method->defaultRequiresClocking();
        }

        $employmentDetail = $this->employmentDetailForDate(
            $employee->employmentDetails,
            Carbon::parse($timesheet->date),
            $timesheet->employmentDetailId ? (string) $timesheet->employmentDetailId : null,
            $timesheet->departmentId ? (int) $timesheet->departmentId : null,
        );
        $compensation = $this->compensationResolver->compensationForDate(
            $employee->employeeCompensations,
            Carbon::parse($timesheet->date),
            $employmentDetail?->id ? (string) $employmentDetail->id : null,
        );

        return $this->compensationResolver->requiresClocking($compensation);
    }
}
