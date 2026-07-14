<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\CompensationMethod;
use App\Models\Timesheet;
use Carbon\Carbon;

class TimesheetOvernightShiftDistributor
{
    public function __construct(
        private readonly TimesheetOvernightShiftBuilder $overnightShiftBuilder,
        private readonly TimesheetLunchBreakResolver $lunchBreakResolver,
        private readonly TimesheetOvernightShiftModeResolver $overnightShiftModeResolver,
        private readonly TimesheetCompensationPayService $compensationPayService,
        private readonly PublicHolidayPayResolver $holidayPayResolver,
    ) {
    }

    public function spansMultipleDays(Carbon $roundOffIn, Carbon $roundOffOut): bool
    {
        return !$roundOffIn->isSameDay($roundOffOut);
    }

    /**
     * @return array{
     *   roundOffClockInTime:?string,
     *   roundOffClockOutTime:?string,
     *   hoursWorked:float,
     *   regularHours:float,
     *   overtimeHours:float,
     *   holidayHours:float,
     *   unpaidHours:float,
     *   isPaid:bool,
     *   paidHours:float,
     *   workingStatus:string
     * }
     */
    public function distributeAndRecalculate(
        Timesheet $timesheet,
        Carbon $roundOffIn,
        Carbon $roundOffOut,
        bool $finalizeWeek = true,
    ): array {
        $lunchMinutes = $this->lunchBreakResolver->breakMinutesForTimesheet($timesheet);
        $shift = $this->overnightShiftBuilder->buildManualShiftSegments(
            $roundOffIn,
            $roundOffOut,
            $lunchMinutes,
            $this->overnightShiftModeResolver->forTimesheet($timesheet),
        );

        $affectedDates = [];
        $primaryDate = Carbon::parse($timesheet->date)->toDateString();

        foreach ($shift['segments'] as $segmentIndex => $segment) {
            $segmentDate = $segment['date'];
            $target = $this->resolveTargetTimesheet($timesheet, $segmentDate, $segmentIndex);
            $holidayPayMultiplier = $this->holidayPayResolver->payMultiplierForDate($segmentDate);

            $target->roundOffClockInTime = $segment['start']->format('Y-m-d H:i:s');
            $target->roundOffClockOutTime = $segment['end']->format('Y-m-d H:i:s');
            $target->clockedHoursWorked = $segment['hours'];
            $target->includeLunchHour = $segmentIndex === 0 && $lunchMinutes > 0;
            $target->lunchHourHours = $segmentIndex === 0
                ? round($lunchMinutes / 60, 2)
                : 0.0;

            $payHours = $this->compensationPayService->applyToTimesheet(
                $target,
                $segment['payableHours'],
                $segment['start'],
                $segment['end'],
                $holidayPayMultiplier,
            );

            $method = CompensationMethod::fromStored($target->payType);

            $target->hoursWorked = $payHours['hoursWorked'];
            $target->holidayHours = $payHours['holidayHours'];
            $target->unpaidHours = $payHours['unpaidHours'];
            $target->isPaid = (bool) ($payHours['isPaid'] ?? true);

            if ($method->allowsOvertime()) {
                // Defer regular/OT to the week-wide overtime allocator.
            } else {
                $target->regularHours = $payHours['regularHours'];
                $target->overtimeHours = $payHours['overtimeHours'];
                $target->paidHours = round((float) ($payHours['paidHours'] ?? 0), 2);
                $target->workingStatus = $payHours['workingStatus'];
            }

            $target->save();

            $affectedDates[$segmentDate] = Carbon::parse($segmentDate);
        }

        if ($finalizeWeek && $affectedDates !== []) {
            $weekAnchor = reset($affectedDates);
            $this->compensationPayService->finalizeTimesheetPay($timesheet, $weekAnchor);
        }

        $timesheet->refresh();

        if ($primaryDate !== $timesheet->date?->format('Y-m-d')) {
            $primary = Timesheet::query()
                ->where('employeeId', $timesheet->employeeId)
                ->whereDate('date', $primaryDate)
                ->where('slotIndex', $timesheet->slotIndex)
                ->first();

            if ($primary) {
                $timesheet = $primary;
            }
        }

        return [
            'roundOffClockInTime' => $timesheet->roundOffClockInTime?->format('Y-m-d H:i:s'),
            'roundOffClockOutTime' => $timesheet->roundOffClockOutTime?->format('Y-m-d H:i:s'),
            'hoursWorked' => (float) $timesheet->hoursWorked,
            'regularHours' => round((float) $timesheet->regularHours, 2),
            'overtimeHours' => (float) $timesheet->overtimeHours,
            'holidayHours' => (float) ($timesheet->holidayHours ?? 0),
            'unpaidHours' => (float) ($timesheet->unpaidHours ?? 0),
            'isPaid' => (bool) ($timesheet->isPaid ?? true),
            'paidHours' => (float) ($timesheet->paidHours ?? 0),
            'workingStatus' => strtoupper((string) $timesheet->workingStatus),
        ];
    }

    private function resolveTargetTimesheet(
        Timesheet $source,
        string $segmentDate,
        int $segmentIndex,
    ): Timesheet {
        $sourceDate = $source->date?->format('Y-m-d');

        if ($segmentDate === $sourceDate && $segmentIndex === 0) {
            return $source;
        }

        $slotIndex = $segmentDate === $sourceDate
            ? (int) $source->slotIndex
            : $this->nextOvernightSlotIndex((string) $source->employeeId, $segmentDate, $sourceDate);

        $timesheet = Timesheet::query()->firstOrNew([
            'employeeId' => $source->employeeId,
            'date' => $segmentDate,
            'slotIndex' => $slotIndex,
        ]);

        if (!$timesheet->exists) {
            $timesheet->approvalStatus = $source->approvalStatus ?? 'PENDING';
            $timesheet->departmentId = $source->departmentId;
            $timesheet->worksiteId = $source->worksiteId;
            $timesheet->payType = $source->payType;
            $timesheet->hourlyRate = $source->hourlyRate;
            $timesheet->weeklySalary = $source->weeklySalary;
            $timesheet->baseSalary = $source->baseSalary;
            $timesheet->isPaid = $source->isPaid;
        }

        return $timesheet;
    }

    private function nextOvernightSlotIndex(
        string $employeeId,
        string $segmentDate,
        ?string $sourceDate,
    ): int {
        if ($segmentDate === $sourceDate) {
            return 0;
        }

        $maxSlot = Timesheet::query()
            ->where('employeeId', $employeeId)
            ->whereDate('date', $segmentDate)
            ->max('slotIndex');

        return $maxSlot === null ? 0 : ((int) $maxSlot + 1);
    }
}
