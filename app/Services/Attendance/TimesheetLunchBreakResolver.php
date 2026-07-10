<?php

namespace App\Services\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ScheduledWork;
use App\Models\ScheduleEmployeeTimesheet;
use App\Models\Timesheet;
use App\Models\TimesheetTemplate;
use App\Models\TimesheetTemplateDepartment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetLunchBreakResolver
{
    public function breakMinutesForTimesheet(Timesheet $timesheet): int
    {
        $settings = $this->lunchSettingsForTimesheet($timesheet);

        return LunchBreakHelper::breakMinutes(
            $settings['include_lunch_hour'],
            $settings['lunch_hour_hours'],
        );
    }

    /**
     * @return array{include_lunch_hour:bool, lunch_hour_hours:float}
     */
    public function lunchSettingsForTimesheet(Timesheet $timesheet): array
    {
        if ($timesheet->includeLunchHour !== null) {
            $hours = max(0, (float) ($timesheet->lunchHourHours ?? 0));

            return [
                'include_lunch_hour' => (bool) $timesheet->includeLunchHour && $hours > 0,
                'lunch_hour_hours' => $hours,
            ];
        }

        if (!$timesheet->date || !$timesheet->employeeId) {
            return $this->emptyLunchSettings();
        }

        return $this->lunchSettingsFor(
            (string) $timesheet->employeeId,
            Carbon::parse($timesheet->date),
            $timesheet->departmentId ? (int) $timesheet->departmentId : null,
            (int) ($timesheet->slotIndex ?? 0),
        );
    }

    public function lunchHoursForDay(Collection $dayTimesheets): float
    {
        $first = $dayTimesheets->sortBy('slotIndex')->first();
        if (!$first instanceof Timesheet) {
            return 0.0;
        }

        if ($first->includeLunchHour !== null) {
            if (!(bool) $first->includeLunchHour) {
                return 0.0;
            }

            return max(0.0, (float) ($first->lunchHourHours ?? 0));
        }

        return round($this->breakMinutesForTimesheet($first) / 60, 2);
    }

    public function grossHoursForTimesheet(Timesheet $timesheet): float
    {
        if ($timesheet->roundOffClockInTime && $timesheet->roundOffClockOutTime) {
            $clockIn = Carbon::parse($timesheet->roundOffClockInTime);
            $clockOut = Carbon::parse($timesheet->roundOffClockOutTime);

            if ($clockOut->gt($clockIn)) {
                return round($clockIn->diffInSeconds($clockOut) / 3600, 2);
            }
        }

        $clockedHours = (float) ($timesheet->clockedHoursWorked ?? 0);
        if ($clockedHours > 0) {
            return $clockedHours;
        }

        return round((float) ($timesheet->hoursWorked ?? 0), 2);
    }

    /**
     * @param Collection<int, Timesheet> $dayTimesheets
     */
    public function netHoursWorkedForDay(Collection $dayTimesheets): float
    {
        if ($dayTimesheets->isEmpty()) {
            return 0.0;
        }

        $grossHours = round(
            $dayTimesheets->sum(fn (Timesheet $timesheet) => $this->grossHoursForTimesheet($timesheet)),
            2,
        );
        $lunchMinutes = (int) round($this->lunchHoursForDay($dayTimesheets) * 60);

        return LunchBreakHelper::applyBreakToHours($grossHours, $lunchMinutes);
    }

    /**
     * @param Collection<int, Timesheet> $dayTimesheets
     */
    public function netHoursForSlotInDay(Timesheet $timesheet, Collection $dayTimesheets): float
    {
        $dayNetHours = $this->netHoursWorkedForDay($dayTimesheets);
        $dayGrossHours = round(
            $dayTimesheets->sum(fn (Timesheet $row) => $this->grossHoursForTimesheet($row)),
            2,
        );
        $slotGrossHours = $this->grossHoursForTimesheet($timesheet);

        if ($dayGrossHours <= 0) {
            return 0.0;
        }

        return round($dayNetHours * ($slotGrossHours / $dayGrossHours), 2);
    }

    public function breakMinutesFor(
        string $employeeId,
        Carbon $date,
        ?int $departmentId,
        int $slotIndex = 0,
    ): int {
        $settings = $this->lunchSettingsFor($employeeId, $date, $departmentId, $slotIndex);

        return LunchBreakHelper::breakMinutes(
            $settings['include_lunch_hour'],
            $settings['lunch_hour_hours'],
        );
    }

    /**
     * @return array{include_lunch_hour:bool, lunch_hour_hours:float}
     */
    public function lunchSettingsFor(
        string $employeeId,
        Carbon $date,
        ?int $departmentId,
        int $slotIndex = 0,
    ): array {
        $scheduledWork = ScheduledWork::query()
            ->where('employeeId', $employeeId)
            ->whereDate('startDate', '<=', $date->toDateString())
            ->whereDate('endDate', '>=', $date->toDateString())
            ->orderBy('startTime')
            ->orderBy('id')
            ->get();

        if ($scheduledWork->isNotEmpty()) {
            $entry = $scheduledWork->get($slotIndex) ?? $scheduledWork->first();

            if ((bool) $entry->includeLunchHour) {
                return $this->normalizeLunchSettings(
                    true,
                    $entry->lunchHourHours,
                );
            }
        }

        $employeeSchedule = ScheduleEmployeeTimesheet::query()
            ->where('employeeId', $employeeId)
            ->whereDate('date', $date->toDateString())
            ->orderBy('startTime')
            ->orderBy('id')
            ->get();

        if ($employeeSchedule->isNotEmpty()) {
            $entry = $employeeSchedule->get($slotIndex) ?? $employeeSchedule->first();

            if ((bool) $entry->include_lunch_hour) {
                return $this->normalizeLunchSettings(
                    true,
                    $entry->lunch_hour_hours,
                );
            }
        }

        $templateSettings = $this->lunchSettingsFromTemplate($employeeId, $date, $departmentId, $slotIndex);
        if ($templateSettings['include_lunch_hour']) {
            return $templateSettings;
        }

        if ($departmentId) {
            $department = Department::query()->find($departmentId);
            if ($department) {
                return $this->normalizeLunchSettings(
                    (bool) $department->includeLunchHour,
                    $department->lunchHourHours,
                );
            }
        }

        return $this->emptyLunchSettings();
    }

    /**
     * @return array{include_lunch_hour:bool, lunch_hour_hours:float}
     */
    private function lunchSettingsFromTemplate(
        string $employeeId,
        Carbon $date,
        ?int $departmentId,
        int $slotIndex,
    ): array {
        $employee = Employee::query()->find($employeeId);
        $template = $this->timesheetTemplateForDate($employee, $departmentId, $date);

        if (!$template) {
            return $this->emptyLunchSettings();
        }

        $slots = $template->daySlotsFor($date->format('D'), $departmentId);
        if ($slots === []) {
            $slots = $template->daySlotsFor($date->format('D'));
        }

        $schedule = $slots[$slotIndex] ?? $slots[0] ?? null;
        if (!$schedule) {
            return $this->emptyLunchSettings();
        }

        return $this->normalizeLunchSettings(
            (bool) ($schedule['include_lunch_hour'] ?? false),
            $schedule['lunch_hour_hours'] ?? 1,
        );
    }

    /**
     * @return array{include_lunch_hour:bool, lunch_hour_hours:float}
     */
    private function normalizeLunchSettings(bool $includeLunchHour, mixed $lunchHourHours): array
    {
        if (!$includeLunchHour) {
            return $this->emptyLunchSettings();
        }

        return [
            'include_lunch_hour' => true,
            'lunch_hour_hours' => max(0, (float) ($lunchHourHours ?? 1)),
        ];
    }

    /**
     * @return array{include_lunch_hour:bool, lunch_hour_hours:float}
     */
    private function emptyLunchSettings(): array
    {
        return [
            'include_lunch_hour' => false,
            'lunch_hour_hours' => 0,
        ];
    }

    private function timesheetTemplateForDate(?Employee $employee, ?int $departmentId, Carbon $date): ?TimesheetTemplate
    {
        if ($employee?->timesheetTemplateId) {
            return TimesheetTemplate::query()->find($employee->timesheetTemplateId);
        }

        if ($departmentId === null) {
            return null;
        }

        $assignment = TimesheetTemplateDepartment::query()
            ->where('department_id', $departmentId)
            ->whereDate('effective_date', '<=', $date->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('created_at')
            ->first();

        return $assignment?->timesheetTemplate;
    }
}
