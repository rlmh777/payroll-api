<?php

namespace App\Services\Attendance;

use App\Enums\OvertimeThresholdMode;
use App\Enums\CompensationMethod;
use App\Models\Department;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetOvertimeAllocator
{
    public function __construct(
        private readonly PublicHolidayPayResolver $holidayPayResolver,
        private readonly TimesheetLunchBreakResolver $lunchBreakResolver,
    ) {
    }

    /**
     * Reallocate regular and overtime hours for every slot in the employee's ISO week.
     */
    public function redistributeEmployeeWeek(string $employeeId, Carbon $date): void
    {
        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $date->copy()->endOfWeek(Carbon::SUNDAY);

        $timesheets = Timesheet::query()
            ->where('employeeId', $employeeId)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('date')
            ->orderBy('slotIndex')
            ->get();

        if ($timesheets->isEmpty()) {
            return;
        }

        $this->redistributeCollection($timesheets);
    }

    /**
     * Reallocate overtime for every employee-week that has timesheets in the department.
     */
    public function redistributeDepartmentTimesheets(
        int $departmentId,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null,
    ): void {
        $query = Timesheet::query()
            ->where('departmentId', $departmentId)
            ->select(['employeeId', 'date']);

        if ($fromDate) {
            $query->whereDate('date', '>=', $fromDate->toDateString());
        }

        if ($toDate) {
            $query->whereDate('date', '<=', $toDate->toDateString());
        }

        $weekKeys = [];

        foreach ($query->get() as $timesheet) {
            if (!$timesheet->employeeId || !$timesheet->date) {
                continue;
            }

            $weekStart = Carbon::parse($timesheet->date)->startOfWeek(Carbon::MONDAY);
            $weekKeys[(string) $timesheet->employeeId.'|'.$weekStart->toDateString()] = [
                (string) $timesheet->employeeId,
                $weekStart,
            ];
        }

        foreach ($weekKeys as [$employeeId, $weekStart]) {
            $this->redistributeEmployeeWeek($employeeId, $weekStart);
        }
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    public function redistributeCollection(Collection $timesheets): void
    {
        if ($timesheets->isEmpty()) {
            return;
        }

        foreach ($timesheets->groupBy(
            fn (Timesheet $timesheet) => CompensationMethod::fromStored($timesheet->payType)->value
        ) as $groupedTimesheets) {
            $group = $groupedTimesheets instanceof Collection
                ? $groupedTimesheets
                : collect($groupedTimesheets);
            $compensationMethod = CompensationMethod::fromStored($group->first()?->payType);

            if (!$compensationMethod->allowsOvertime()) {
                $this->redistributeWithoutOvertime($group);

                continue;
            }

            if ($compensationMethod === CompensationMethod::HourlyOt) {
                $this->redistributeHourlyOtCollection($group);

                continue;
            }

            if ($compensationMethod === CompensationMethod::BaseOt) {
                $this->redistributeBaseOtCollection($group);
            }
        }
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function redistributeHourlyOtCollection(Collection $timesheets): void
    {
        $departmentIds = $timesheets->pluck('departmentId')->filter()->unique()->values();
        $departments = Department::query()
            ->whereIn('id', $departmentIds)
            ->get()
            ->keyBy('id');

        $groupedByDepartment = $timesheets->groupBy(
            fn (Timesheet $timesheet) => (string) ($timesheet->departmentId ?? 'none')
        );

        foreach ($groupedByDepartment as $departmentKey => $departmentTimesheets) {
            $department = $departmentKey === 'none'
                ? null
                : $departments->get((int) $departmentKey);

            $this->applyOvertimeAllocation($departmentTimesheets, $department);
        }
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function redistributeBaseOtCollection(Collection $timesheets): void
    {
        $departmentIds = $timesheets->pluck('departmentId')->filter()->unique()->values();
        $departments = Department::query()
            ->whereIn('id', $departmentIds)
            ->get()
            ->keyBy('id');

        $groupedByDepartment = $timesheets->groupBy(
            fn (Timesheet $timesheet) => (string) ($timesheet->departmentId ?? 'none')
        );

        foreach ($groupedByDepartment as $departmentKey => $departmentTimesheets) {
            $department = $departmentKey === 'none'
                ? null
                : $departments->get((int) $departmentKey);

            $this->applyOvertimeAllocation($departmentTimesheets, $department);
        }
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function applyOvertimeAllocation(
        Collection $timesheets,
        ?Department $department,
    ): void {
        $mode = OvertimeThresholdMode::fromStored($department?->overtimeThresholdMode);

        if ($mode->usesWeeklyThreshold() && !$mode->usesDailyThreshold()) {
            $this->applyWeeklyOnlyAllocation($timesheets, $department);

            return;
        }

        if ($mode->usesDailyThreshold()) {
            $this->applyDailyAllocation($timesheets, $department);
        }

        if ($mode->usesWeeklyThreshold()) {
            $this->applyWeeklyAllocation($timesheets, $department);
        }
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function redistributeWithoutOvertime(Collection $timesheets): void
    {
        $compensationMethod = CompensationMethod::fromStored($timesheets->first()?->payType);

        if ($compensationMethod === CompensationMethod::BaseNoOt) {
            $this->redistributeBaseNoOvertimeCollection($timesheets);

            return;
        }

        foreach ($timesheets->groupBy(fn (Timesheet $row) => $this->timesheetDateKey($row)) as $dayTimesheets) {
            $orderedDayTimesheets = $dayTimesheets->sortBy('slotIndex')->values();

            foreach ($orderedDayTimesheets as $timesheet) {
                if ($this->applyHolidayIfNeeded($timesheet, $orderedDayTimesheets)) {
                    continue;
                }

                $hoursWorked = $this->netHoursForTimesheet($timesheet, $orderedDayTimesheets);
                $this->persistHours($timesheet, $hoursWorked, 0.0, $hoursWorked);
            }
        }
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function redistributeBaseNoOvertimeCollection(Collection $timesheets): void
    {
        $departmentIds = $timesheets->pluck('departmentId')->filter()->unique()->values();
        $departments = Department::query()
            ->whereIn('id', $departmentIds)
            ->get()
            ->keyBy('id');

        $groupedByDepartment = $timesheets->groupBy(
            fn (Timesheet $timesheet) => (string) ($timesheet->departmentId ?? 'none')
        );

        foreach ($groupedByDepartment as $departmentKey => $departmentTimesheets) {
            $department = $departmentKey === 'none'
                ? null
                : $departments->get((int) $departmentKey);

            foreach ($departmentTimesheets->groupBy(fn (Timesheet $row) => $this->timesheetDateKey($row)) as $dayTimesheets) {
                $orderedDayTimesheets = $dayTimesheets->sortBy('slotIndex')->values();
                $dailyPayableRemaining = $this->effectiveDailyThreshold($orderedDayTimesheets, $department);
                $netHoursByIndex = [];
                $eligibleIndexes = [];
                $totalEligibleNetHours = 0.0;

                foreach ($orderedDayTimesheets as $index => $timesheet) {
                    if ($this->isUnpaidTimesheet($timesheet)) {
                        continue;
                    }

                    $hoursWorked = $this->netHoursForTimesheet($timesheet, $orderedDayTimesheets);
                    $netHoursByIndex[$index] = $hoursWorked;
                    $eligibleIndexes[] = $index;
                    $totalEligibleNetHours = round($totalEligibleNetHours + $hoursWorked, 2);
                }

                $lastEligibleIndex = $eligibleIndexes === [] ? null : end($eligibleIndexes);
                $baseHoursShortfall = $eligibleIndexes === []
                    ? 0.0
                    : round(max(0.0, $dailyPayableRemaining - $totalEligibleNetHours), 2);

                foreach ($orderedDayTimesheets as $index => $timesheet) {
                    if ($this->isUnpaidTimesheet($timesheet)) {
                        continue;
                    }

                    $hoursWorked = $netHoursByIndex[$index] ?? 0.0;
                    $payableBasis = $hoursWorked + ($index === $lastEligibleIndex ? $baseHoursShortfall : 0.0);
                    $payableHours = round(min($payableBasis, max(0.0, $dailyPayableRemaining)), 2);
                    $unpaidHours = round(max(0.0, $hoursWorked - $payableHours), 2);
                    $dailyPayableRemaining = round(max(0.0, $dailyPayableRemaining - $payableHours), 2);

                    $payMultiplier = $timesheet->date
                        ? $this->holidayPayResolver->payMultiplierForDate($timesheet->date)
                        : null;

                    $this->persistBaseNoOvertimeHours(
                        $timesheet,
                        $payableHours,
                        $unpaidHours,
                        $payMultiplier,
                    );
                }
            }
        }
    }

    private function isUnpaidTimesheet(Timesheet $timesheet): bool
    {
        $status = strtoupper((string) ($timesheet->workingStatus ?? ''));

        return $status === 'UNPAID'
            || (
                !(bool) ($timesheet->isPaid ?? true)
                && (float) ($timesheet->paidHours ?? 0) <= 0
                && (float) ($timesheet->unpaidHours ?? 0) > 0
            );
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function applyWeeklyOnlyAllocation(
        Collection $timesheets,
        ?Department $department,
    ): void {
        foreach ($timesheets->groupBy(fn (Timesheet $row) => $this->timesheetDateKey($row)) as $dayTimesheets) {
            $orderedDayTimesheets = $dayTimesheets->sortBy('slotIndex')->values();

            foreach ($orderedDayTimesheets as $timesheet) {
                if ($this->applyHolidayIfNeeded($timesheet, $orderedDayTimesheets)) {
                    continue;
                }

                $hoursWorked = $this->netHoursForTimesheet($timesheet, $orderedDayTimesheets);
                $this->persistHours($timesheet, $hoursWorked, 0.0, $hoursWorked);
            }
        }

        $this->applyWeeklyAllocation($timesheets, $department);
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function applyDailyAllocation(Collection $timesheets, ?Department $department): void
    {
        foreach ($timesheets->groupBy(fn (Timesheet $row) => $this->timesheetDateKey($row)) as $dayTimesheets) {
            $orderedDayTimesheets = $dayTimesheets->sortBy('slotIndex')->values();
            $dailyThreshold = $this->effectiveDailyThreshold($orderedDayTimesheets, $department);
            $dayRegularUsed = 0.0;

            foreach ($orderedDayTimesheets as $timesheet) {
                if ($this->applyHolidayIfNeeded($timesheet, $orderedDayTimesheets)) {
                    continue;
                }

                $hoursWorked = $this->netHoursForTimesheet($timesheet, $orderedDayTimesheets);
                $dailyRegularRemaining = max(0.0, $dailyThreshold - $dayRegularUsed);
                $regularHours = round(min($hoursWorked, $dailyRegularRemaining), 2);
                $overtimeHours = round(max(0.0, $hoursWorked - $regularHours), 2);

                $this->persistHours($timesheet, $regularHours, $overtimeHours, $hoursWorked);
                $dayRegularUsed += $regularHours;
            }
        }
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function applyWeeklyAllocation(Collection $timesheets, ?Department $department): void
    {
        $weeklyThreshold = $this->effectiveWeeklyThreshold($timesheets, $department);
        $weekRegular = round((float) $timesheets->sum(fn (Timesheet $row) => (float) $row->regularHours), 2);
        $weeklyExcess = round(max(0.0, $weekRegular - $weeklyThreshold), 2);

        if ($weeklyExcess <= 0) {
            return;
        }

        $ordered = $timesheets
            ->sortBy([
                ['date', 'desc'],
                ['slotIndex', 'desc'],
            ])
            ->values();

        foreach ($ordered as $timesheet) {
            if ($weeklyExcess <= 0) {
                break;
            }

            if ($timesheet->date && $this->holidayPayResolver->isHoliday($timesheet->date)) {
                continue;
            }

            $regularHours = round((float) $timesheet->regularHours, 2);
            if ($regularHours <= 0) {
                continue;
            }

            $shift = round(min($regularHours, $weeklyExcess), 2);
            $this->persistHours(
                $timesheet,
                round($regularHours - $shift, 2),
                round((float) $timesheet->overtimeHours + $shift, 2),
            );
            $weeklyExcess = round($weeklyExcess - $shift, 2);
        }
    }

    private function applyHolidayIfNeeded(Timesheet $timesheet, ?Collection $dayTimesheets = null): bool
    {
        $payMultiplier = $timesheet->date
            ? $this->holidayPayResolver->payMultiplierForDate($timesheet->date)
            : null;

        if ($payMultiplier === null) {
            return false;
        }

        $hoursWorked = $dayTimesheets !== null
            ? $this->netHoursForTimesheet($timesheet, $dayTimesheets)
            : round((float) $timesheet->hoursWorked, 2);

        $this->persistHolidayHours($timesheet, $hoursWorked, $payMultiplier);

        return true;
    }

    /**
     * @param Collection<int, Timesheet> $dayTimesheets
     */
    private function netHoursForTimesheet(Timesheet $timesheet, Collection $dayTimesheets): float
    {
        $netHours = $this->lunchBreakResolver->netHoursForSlotInDay($timesheet, $dayTimesheets);
        $storedHours = round((float) ($timesheet->hoursWorked ?? 0), 2);

        if ($netHours <= 0 && $storedHours > 0) {
            return $storedHours;
        }

        return $netHours;
    }

    /**
     * @param Collection<int, Timesheet> $dayTimesheets
     */
    private function effectiveDailyThreshold(
        Collection $dayTimesheets,
        ?Department $department,
    ): float {
        $threshold = $this->dailyThreshold($department);
        $lunchHours = $this->lunchHoursForDepartmentThreshold($dayTimesheets, $department);

        if ($lunchHours > 0) {
            return max(0.0, round($threshold - $lunchHours, 2));
        }

        return $threshold;
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function effectiveWeeklyThreshold(Collection $timesheets, ?Department $department): float
    {
        $weeklyThreshold = $this->weeklyThreshold($department);
        $lunchDeduction = 0.0;

        foreach ($timesheets->groupBy(fn (Timesheet $row) => $this->timesheetDateKey($row)) as $dayTimesheets) {
            $orderedDayTimesheets = $dayTimesheets->sortBy('slotIndex')->values();
            if ($this->lunchBreakResolver->netHoursWorkedForDay($orderedDayTimesheets) <= 0) {
                continue;
            }

            $lunchDeduction += $this->lunchHoursForDepartmentThreshold($orderedDayTimesheets, $department);
        }

        if ($lunchDeduction <= 0) {
            return $weeklyThreshold;
        }

        return max(0.0, round($weeklyThreshold - $lunchDeduction, 2));
    }

    /**
     * @param Collection<int, Timesheet> $dayTimesheets
     */
    private function lunchHoursForDepartmentThreshold(Collection $dayTimesheets, ?Department $department): float
    {
        $lunchHours = $this->lunchBreakResolver->lunchHoursForDay($dayTimesheets);
        if ($lunchHours > 0) {
            return $lunchHours;
        }

        if ($department && (bool) $department->includeLunchHour) {
            return max(0.0, (float) ($department->lunchHourHours ?? 1));
        }

        return 0.0;
    }

    private function persistHours(
        Timesheet $timesheet,
        float $regularHours,
        float $overtimeHours,
        ?float $hoursWorked = null,
    ): void {
        if ($hoursWorked !== null) {
            $timesheet->hoursWorked = $hoursWorked;
        }
        $workingStatus = strtoupper((string) ($timesheet->workingStatus ?: 'REGULAR'));

        if ($overtimeHours > 0 && !in_array($workingStatus, ['HOLIDAY', 'UNPAID'], true)) {
            $workingStatus = 'OVERTIME';
        } elseif ($overtimeHours <= 0 && $workingStatus === 'OVERTIME') {
            $workingStatus = 'REGULAR';
        }

        $timesheet->regularHours = $regularHours;
        $timesheet->overtimeHours = $overtimeHours;
        $timesheet->holidayHours = 0.0;
        $timesheet->workingStatus = $workingStatus;
        $this->syncPaidFields($timesheet);
        $timesheet->save();
    }

    private function persistHolidayHours(Timesheet $timesheet, float $hoursWorked, float $payMultiplier): void
    {
        $holidayHours = round($hoursWorked * max(0, $payMultiplier), 2);

        $timesheet->hoursWorked = $hoursWorked;

        $timesheet->regularHours = 0;
        $timesheet->overtimeHours = 0;
        $timesheet->holidayHours = $holidayHours;
        $timesheet->workingStatus = 'HOLIDAY';
        $this->syncPaidFields($timesheet);
        $timesheet->save();
    }

    private function syncPaidFields(Timesheet $timesheet): void
    {
        $payFields = TimesheetPayFields::fromStoredHours(
            (bool) ($timesheet->isPaid ?? true),
            (float) $timesheet->regularHours,
            (float) $timesheet->overtimeHours,
            (float) ($timesheet->holidayHours ?? 0),
            (float) ($timesheet->hoursWorked ?? 0),
        );

        $timesheet->isPaid = $payFields['isPaid'];
        $timesheet->paidHours = $payFields['paidHours'];
        $timesheet->unpaidHours = $payFields['unpaidHours'];
    }

    private function persistBaseNoOvertimeHours(
        Timesheet $timesheet,
        float $payableHours,
        float $unpaidHours,
        ?float $holidayPayMultiplier = null,
    ): void {
        $payableHours = round(max(0.0, $payableHours), 2);
        $unpaidHours = round(max(0.0, $unpaidHours), 2);
        $holidayHours = $holidayPayMultiplier !== null
            ? round($payableHours * max(0, $holidayPayMultiplier), 2)
            : 0.0;
        $regularHours = $holidayPayMultiplier !== null ? 0.0 : $payableHours;
        $paidHours = round($regularHours + $holidayHours, 2);

        $timesheet->setAttribute('hoursWorked', $payableHours);
        $timesheet->setAttribute('regularHours', $regularHours);
        $timesheet->setAttribute('overtimeHours', 0.0);
        $timesheet->setAttribute('holidayHours', $holidayHours);
        $timesheet->setAttribute('unpaidHours', $unpaidHours);
        $timesheet->isPaid = $paidHours > 0;
        $timesheet->setAttribute('paidHours', $paidHours);
        $timesheet->workingStatus = $holidayPayMultiplier !== null
            ? 'HOLIDAY'
            : ($paidHours > 0 ? 'REGULAR' : 'UNPAID');
        $timesheet->save();
    }

    private function timesheetDateKey(Timesheet $timesheet): string
    {
        return $timesheet->date
            ? Carbon::parse($timesheet->date)->format('Y-m-d')
            : '';
    }

    private function dailyThreshold(?Department $department): float
    {
        if ($department && (float) $department->totalDailyHoursBeforeOvertime > 0) {
            return (float) $department->totalDailyHoursBeforeOvertime;
        }

        return (float) config('attendance.standard_daily_hours', 8);
    }

    private function weeklyThreshold(?Department $department): float
    {
        if ($department && (float) $department->totalWeeklyHoursBeforeOvertime > 0) {
            return (float) $department->totalWeeklyHoursBeforeOvertime;
        }

        return 45.0;
    }
}
