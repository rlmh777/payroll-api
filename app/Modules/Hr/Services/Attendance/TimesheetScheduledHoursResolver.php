<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Models\Employee;
use App\Models\EmploymentDetail;
use App\Models\ScheduledWork;
use App\Models\ScheduleEmployeeTimesheet;
use App\Models\Timesheet;
use App\Models\TimesheetTemplate;
use App\Models\TimesheetTemplateDepartment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetScheduledHoursResolver
{
    public function forTimesheet(Timesheet $timesheet): float
    {
        if (!$timesheet->employeeId || !$timesheet->date) {
            return 0.0;
        }

        return $this->forEmployeeDate(
            (string) $timesheet->employeeId,
            Carbon::parse($timesheet->date),
            $timesheet->departmentId ? (int) $timesheet->departmentId : null,
            $timesheet->employmentDetailId ? (string) $timesheet->employmentDetailId : null,
        );
    }

    public function forTimesheetSlot(Timesheet $timesheet): float
    {
        if (!$timesheet->employeeId || !$timesheet->date) {
            return 0.0;
        }

        return $this->forEmployeeDateSlot(
            (string) $timesheet->employeeId,
            Carbon::parse($timesheet->date),
            (int) ($timesheet->slotIndex ?? 0),
            $timesheet->departmentId ? (int) $timesheet->departmentId : null,
            $timesheet->employmentDetailId ? (string) $timesheet->employmentDetailId : null,
        );
    }

    /**
     * @return array{start:string,end:string}|null
     */
    public function scheduledWindowForTimesheet(Timesheet $timesheet): ?array
    {
        if (!$timesheet->employeeId || !$timesheet->date) {
            return null;
        }

        $employee = Employee::query()
            ->with(['timesheetTemplate', 'employmentDetails'])
            ->find($timesheet->employeeId);

        if (!$employee) {
            return null;
        }

        $date = Carbon::parse($timesheet->date);
        $employmentDetail = $this->employmentDetailForDate($employee->employmentDetails, $date);
        $resolvedDepartmentId = $timesheet->departmentId ? (int) $timesheet->departmentId : $employmentDetail?->departmentId;
        $explicitWindow = $this->explicitScheduledWindowForSlot(
            (string) $timesheet->employeeId,
            $date,
            (int) ($timesheet->slotIndex ?? 0),
            $resolvedDepartmentId,
            $timesheet->employmentDetailId ? (string) $timesheet->employmentDetailId : null,
        );

        if ($explicitWindow !== null) {
            return $explicitWindow;
        }

        $templateAssignments = TimesheetTemplateDepartment::query()
            ->with('timesheetTemplate')
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy(fn (TimesheetTemplateDepartment $assignment) => (string) $assignment->department_id);

        $timesheetTemplate = $this->timesheetTemplateForDate(
            $employee,
            $resolvedDepartmentId,
            $date,
            $templateAssignments,
        );

        if (!$this->isScheduledWorkDay($timesheetTemplate, $date, $resolvedDepartmentId)) {
            return null;
        }

        $slots = $timesheetTemplate?->daySlotsFor($date->format('D'), $resolvedDepartmentId) ?? [];
        if ($slots === [] && $date->isWeekday()) {
            return [
                'start' => '08:00',
                'end' => '17:00',
            ];
        }

        $schedule = $slots[(int) ($timesheet->slotIndex ?? 0)] ?? $slots[0] ?? null;
        if ($schedule === null) {
            return null;
        }

        $start = substr((string) ($schedule['start_time'] ?? ''), 0, 5);
        $end = substr((string) ($schedule['end_time'] ?? ''), 0, 5);

        if ($start === '' || $end === '') {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    public function forEmployeeDateSlot(
        string $employeeId,
        Carbon $date,
        int $slotIndex,
        ?int $departmentId = null,
        ?string $employmentDetailId = null,
    ): float {
        $employee = Employee::query()
            ->with(['timesheetTemplate', 'employmentDetails'])
            ->find($employeeId);

        if (!$employee) {
            return $this->scheduledHoursForSlot(null, $date, $slotIndex, $departmentId);
        }

        $employmentDetail = $this->employmentDetailForDate($employee->employmentDetails, $date);
        $resolvedDepartmentId = $departmentId ?? $employmentDetail?->departmentId;
        $explicitHours = $this->explicitScheduledHoursForSlot(
            $employeeId,
            $date,
            $slotIndex,
            $resolvedDepartmentId,
            $employmentDetailId,
        );

        if ($explicitHours !== null) {
            return $explicitHours;
        }

        $templateAssignments = TimesheetTemplateDepartment::query()
            ->with('timesheetTemplate')
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy(fn (TimesheetTemplateDepartment $assignment) => (string) $assignment->department_id);

        $timesheetTemplate = $this->timesheetTemplateForDate(
            $employee,
            $resolvedDepartmentId,
            $date,
            $templateAssignments,
        );

        if (!$this->isScheduledWorkDay($timesheetTemplate, $date, $resolvedDepartmentId)) {
            return 0.0;
        }

        return $this->scheduledHoursForSlot($timesheetTemplate, $date, $slotIndex, $resolvedDepartmentId);
    }

    public function forEmployeeDate(
        string $employeeId,
        Carbon $date,
        ?int $departmentId = null,
        ?string $employmentDetailId = null,
    ): float
    {
        $employee = Employee::query()
            ->with(['timesheetTemplate', 'employmentDetails'])
            ->find($employeeId);

        if (!$employee) {
            return $this->scheduledHoursForDate(null, $date, $departmentId);
        }

        $employmentDetail = $this->employmentDetailForDate($employee->employmentDetails, $date);
        $resolvedDepartmentId = $departmentId ?? $employmentDetail?->departmentId;
        $explicitHours = $this->explicitScheduledHoursForDate(
            $employeeId,
            $date,
            $resolvedDepartmentId,
            $employmentDetailId,
        );

        if ($explicitHours !== null) {
            return $explicitHours;
        }

        $templateAssignments = TimesheetTemplateDepartment::query()
            ->with('timesheetTemplate')
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy(fn (TimesheetTemplateDepartment $assignment) => (string) $assignment->department_id);

        $timesheetTemplate = $this->timesheetTemplateForDate(
            $employee,
            $resolvedDepartmentId,
            $date,
            $templateAssignments,
        );

        if (!$this->isScheduledWorkDay($timesheetTemplate, $date, $resolvedDepartmentId)) {
            return 0.0;
        }

        return $this->scheduledHoursForDate($timesheetTemplate, $date, $resolvedDepartmentId);
    }

    /**
     * @param Collection<int, EmploymentDetail> $details
     */
    private function employmentDetailForDate(Collection $details, Carbon $date): ?EmploymentDetail
    {
        $matching = $details->filter(function (EmploymentDetail $detail) use ($date) {
            $startDate = Carbon::parse($detail->startDate)->startOfDay();
            $endDate = $detail->endDate ? Carbon::parse($detail->endDate)->startOfDay() : null;

            return $startDate->lte($date) && (!$endDate || $endDate->gte($date));
        });

        return $matching
            ->sort(function (EmploymentDetail $left, EmploymentDetail $right) {
                if ($left->isActive !== $right->isActive) {
                    return $right->isActive <=> $left->isActive;
                }

                return Carbon::parse($right->startDate)->timestamp <=> Carbon::parse($left->startDate)->timestamp;
            })
            ->first();
    }

    private function explicitScheduledHoursForDate(
        string $employeeId,
        Carbon $date,
        ?int $departmentId = null,
        ?string $employmentDetailId = null,
    ): ?float {
        $slots = $this->explicitScheduleSlots($employeeId, $date, $departmentId, $employmentDetailId);

        if ($slots === []) {
            return null;
        }

        return round(array_sum(array_map(
            fn (array $slot) => $this->hoursForWindow($date, $slot['start'], $slot['end'], $slot['lunchHours']),
            $slots,
        )), 2);
    }

    private function explicitScheduledHoursForSlot(
        string $employeeId,
        Carbon $date,
        int $slotIndex,
        ?int $departmentId = null,
        ?string $employmentDetailId = null,
    ): ?float {
        $slots = $this->explicitScheduleSlots($employeeId, $date, $departmentId, $employmentDetailId);
        $slot = $slots[$slotIndex] ?? null;

        if ($slot === null) {
            return $slots === [] ? null : 0.0;
        }

        return $this->hoursForWindow($date, $slot['start'], $slot['end'], $slot['lunchHours']);
    }

    /**
     * @return array{start:string,end:string}|null
     */
    private function explicitScheduledWindowForSlot(
        string $employeeId,
        Carbon $date,
        int $slotIndex,
        ?int $departmentId = null,
        ?string $employmentDetailId = null,
    ): ?array {
        $slots = $this->explicitScheduleSlots($employeeId, $date, $departmentId, $employmentDetailId);
        $slot = $slots[$slotIndex] ?? null;

        if ($slot === null) {
            return null;
        }

        return [
            'start' => $slot['start'],
            'end' => $slot['end'],
        ];
    }

    /**
     * @return array<int, array{start:string,end:string,lunchHours:float}>
     */
    private function explicitScheduleSlots(
        string $employeeId,
        Carbon $date,
        ?int $departmentId = null,
        ?string $employmentDetailId = null,
    ): array {
        $workDate = $date->toDateString();
        $employeeScheduledWork = ScheduledWork::query()
            ->where('employeeId', $employeeId)
            ->when($employmentDetailId, fn ($query) => $query->where('employmentDetailId', $employmentDetailId))
            ->whereDate('startDate', '<=', $workDate)
            ->whereDate('endDate', '>=', $workDate)
            ->orderBy('startTime')
            ->orderBy('id')
            ->get(['startTime', 'endTime', 'includeLunchHour', 'lunchHourHours']);

        if ($employeeScheduledWork->isNotEmpty()) {
            return $employeeScheduledWork
                ->map(fn (ScheduledWork $entry) => $this->slotFromScheduledWork($entry))
                ->values()
                ->all();
        }

        $employeeSchedules = ScheduleEmployeeTimesheet::query()
            ->where('employeeId', $employeeId)
            ->when($employmentDetailId, fn ($query) => $query->where('employmentDetailId', $employmentDetailId))
            ->whereDate('date', $workDate)
            ->orderBy('startTime')
            ->orderBy('id')
            ->get(['startTime', 'endTime', 'include_lunch_hour', 'lunch_hour_hours']);

        if ($employeeSchedules->isNotEmpty()) {
            return $employeeSchedules
                ->map(fn (ScheduleEmployeeTimesheet $entry) => $this->slotFromEmployeeSchedule($entry))
                ->values()
                ->all();
        }

        if ($departmentId === null) {
            return [];
        }

        return ScheduledWork::query()
            ->whereNull('employeeId')
            ->where('departmentId', $departmentId)
            ->whereDate('startDate', '<=', $workDate)
            ->whereDate('endDate', '>=', $workDate)
            ->orderBy('startTime')
            ->orderBy('id')
            ->get(['startTime', 'endTime', 'includeLunchHour', 'lunchHourHours'])
            ->map(fn (ScheduledWork $entry) => $this->slotFromScheduledWork($entry))
            ->values()
            ->all();
    }

    /**
     * @return array{start:string,end:string,lunchHours:float}
     */
    private function slotFromScheduledWork(ScheduledWork $entry): array
    {
        return [
            'start' => $this->normalizeTime($entry->startTime),
            'end' => $this->normalizeTime($entry->endTime),
            'lunchHours' => (bool) $entry->includeLunchHour
                ? (float) ($entry->lunchHourHours ?? 0)
                : 0.0,
        ];
    }

    /**
     * @return array{start:string,end:string,lunchHours:float}
     */
    private function slotFromEmployeeSchedule(ScheduleEmployeeTimesheet $entry): array
    {
        return [
            'start' => $this->normalizeTime($entry->startTime),
            'end' => $this->normalizeTime($entry->endTime),
            'lunchHours' => (bool) $entry->include_lunch_hour
                ? (float) ($entry->lunch_hour_hours ?? 0)
                : 0.0,
        ];
    }

    /**
     * @param Collection<string, Collection<int, TimesheetTemplateDepartment>> $scheduleAssignments
     */
    private function timesheetTemplateForDate(
        ?Employee $employee,
        mixed $departmentId,
        Carbon $date,
        Collection $scheduleAssignments,
    ): ?TimesheetTemplate {
        if ($employee?->timesheetTemplateId) {
            if ($employee->relationLoaded('timesheetTemplate') && $employee->timesheetTemplate) {
                return $employee->timesheetTemplate;
            }

            return TimesheetTemplate::query()->find($employee->timesheetTemplateId);
        }

        if ($departmentId === null) {
            return null;
        }

        $assignments = $scheduleAssignments->get((string) $departmentId, collect());
        $assignment = $assignments->first(
            fn (TimesheetTemplateDepartment $item) => Carbon::parse($item->effective_date)->startOfDay()->lte($date)
        );

        return $assignment?->timesheetTemplate;
    }

    private function isScheduledWorkDay(?TimesheetTemplate $timesheetTemplate, Carbon $date, ?int $departmentId = null): bool
    {
        if (!$timesheetTemplate) {
            return $date->isWeekday();
        }

        if (!$timesheetTemplate->is_active) {
            return false;
        }

        return $timesheetTemplate->daySlotsFor($date->format('D'), $departmentId) !== [];
    }

    private function scheduledHoursForDate(?TimesheetTemplate $timesheetTemplate, Carbon $date, ?int $departmentId = null): float
    {
        if (!$timesheetTemplate) {
            if (!$date->isWeekday()) {
                return 0.0;
            }

            return (float) config('attendance.standard_daily_hours', 8);
        }

        $slots = $timesheetTemplate->daySlotsFor($date->format('D'), $departmentId);
        if ($slots === []) {
            return 0.0;
        }

        $totalMinutes = 0;

        foreach ($slots as $schedule) {
            $start = Carbon::parse($date->toDateString().' '.$schedule['start_time']);
            $end = Carbon::parse($date->toDateString().' '.$schedule['end_time']);

            if ($end->lte($start)) {
                $end->addDay();
            }

            $breakMinutes = LunchBreakHelper::breakMinutesFromSchedule($schedule);
            $totalMinutes += max(0, $start->diffInMinutes($end, false) - $breakMinutes);
        }

        return round($totalMinutes / 60, 2);
    }

    private function scheduledHoursForSlot(
        ?TimesheetTemplate $timesheetTemplate,
        Carbon $date,
        int $slotIndex,
        ?int $departmentId = null,
    ): float {
        if (!$timesheetTemplate) {
            if (!$date->isWeekday()) {
                return 0.0;
            }

            return $slotIndex === 0
                ? (float) config('attendance.standard_daily_hours', 8)
                : 0.0;
        }

        $slots = $timesheetTemplate->daySlotsFor($date->format('D'), $departmentId);
        $schedule = $slots[$slotIndex] ?? null;

        if ($schedule === null) {
            return 0.0;
        }

        $start = Carbon::parse($date->toDateString().' '.$schedule['start_time']);
        $end = Carbon::parse($date->toDateString().' '.$schedule['end_time']);

        if ($end->lte($start)) {
            $end->addDay();
        }

        $breakMinutes = LunchBreakHelper::breakMinutesFromSchedule($schedule);

        return round(max(0, $start->diffInMinutes($end, false) - $breakMinutes) / 60, 2);
    }

    private function hoursForWindow(Carbon $date, string $startTime, string $endTime, float $lunchHours = 0.0): float
    {
        $start = Carbon::parse($date->toDateString().' '.$startTime);
        $end = Carbon::parse($date->toDateString().' '.$endTime);

        if ($end->lte($start)) {
            $end->addDay();
        }

        return round(max(0, $start->diffInMinutes($end, false) / 60 - $lunchHours), 2);
    }

    private function normalizeTime(mixed $value): string
    {
        $time = trim((string) $value);

        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            return '00:00';
        }

        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }
}
