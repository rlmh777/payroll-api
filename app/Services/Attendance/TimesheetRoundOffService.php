<?php

namespace App\Services\Attendance;

use App\Enums\CompensationMethod;
use App\Models\Timesheet;
use Carbon\Carbon;

class TimesheetRoundOffService
{
    public function __construct(
        private readonly TimesheetLunchBreakResolver $lunchBreakResolver,
        private readonly TimesheetOvernightShiftModeResolver $overnightShiftModeResolver,
        private readonly TimesheetCompensationPayService $compensationPayService,
        private readonly TimesheetOvernightShiftDistributor $overnightShiftDistributor,
    ) {
    }

    /**
     * @return array{
     *   roundOffClockInTime:?string,
     *   roundOffClockOutTime:?string,
     *   hoursWorked:float,
     *   regularHours:float,
     *   overtimeHours:float,
     *   workingStatus:string
     * }
     */
    public function recalculate(
        Timesheet $timesheet,
        ?Carbon $roundOffClockIn,
        ?Carbon $roundOffClockOut,
        bool $finalizeWeek = true,
    ): array {
        $this->compensationPayService->syncPayContext($timesheet);

        if (
            $roundOffClockIn
            && $roundOffClockOut
            && $roundOffClockOut->gt($roundOffClockIn)
            && $this->overnightShiftDistributor->spansMultipleDays($roundOffClockIn, $roundOffClockOut)
            && $this->overnightShiftModeResolver->shouldSplitAtMidnight($timesheet)
        ) {
            return $this->overnightShiftDistributor->distributeAndRecalculate(
                $timesheet,
                $roundOffClockIn,
                $roundOffClockOut,
                $finalizeWeek,
            );
        }

        $clockedHours = 0.0;
        $grossClockedHours = 0.0;

        if ($roundOffClockIn && $roundOffClockOut && $roundOffClockOut->gt($roundOffClockIn)) {
            $grossClockedHours = round($roundOffClockIn->diffInSeconds($roundOffClockOut) / 3600, 2);
            $lunchMinutes = $this->lunchBreakResolver->breakMinutesForTimesheet($timesheet);
            $clockedHours = LunchBreakHelper::applyBreakToHours($grossClockedHours, $lunchMinutes);
        }

        $timesheet->roundOffClockInTime = $roundOffClockIn?->format('Y-m-d H:i:s');
        $timesheet->roundOffClockOutTime = $roundOffClockOut?->format('Y-m-d H:i:s');
        $timesheet->clockedHoursWorked = $grossClockedHours;

        $payHours = $this->compensationPayService->applyToTimesheet(
            $timesheet,
            $clockedHours,
            $roundOffClockIn,
            $roundOffClockOut,
        );

        $method = CompensationMethod::fromStored($timesheet->payType);

        $timesheet->hoursWorked = $payHours['hoursWorked'];
        $timesheet->holidayHours = $payHours['holidayHours'];
        $timesheet->unpaidHours = $payHours['unpaidHours'];
        $timesheet->isPaid = (bool) ($payHours['isPaid'] ?? true);

        if ($method->allowsOvertime()) {
            // Defer regular/OT to the week-wide overtime allocator.
        } else {
            $timesheet->regularHours = $payHours['regularHours'];
            $timesheet->overtimeHours = $payHours['overtimeHours'];
            $timesheet->paidHours = round((float) ($payHours['paidHours'] ?? 0), 2);
            $timesheet->workingStatus = $payHours['workingStatus'];
        }

        $timesheet->save();

        if ($finalizeWeek) {
            $this->compensationPayService->finalizeTimesheetPay(
                $timesheet,
                Carbon::parse($timesheet->date),
            );
        }

        $timesheet->refresh();

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
}
