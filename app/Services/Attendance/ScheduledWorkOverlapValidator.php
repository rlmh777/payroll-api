<?php

namespace App\Services\Attendance;

use App\Helpers\EmployeeLeaveHelper;
use App\Models\ScheduledWork;
use App\Models\ScheduleEmployeeTimesheet;
use App\Models\Timesheet;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ScheduledWorkOverlapValidator
{
    /**
     * @throws ValidationException
     */
    public function validate(
        ?string $employeeId,
        string $startDate,
        string $endDate,
        ?string $startTime,
        ?string $endTime,
        ?string $excludeScheduledWorkId = null,
        array $excludeEmployeeScheduleIds = [],
    ): void {
        if (!$employeeId) {
            return;
        }

        $startDate = Carbon::parse($startDate)->toDateString();
        $endDate = Carbon::parse($endDate)->toDateString();
        $startTime = $this->normalizeTime($startTime ?? '09:00');
        $endTime = $this->normalizeTime($endTime ?? '17:00');

        $scheduledWorkCandidates = ScheduledWork::query()
            ->where('employeeId', $employeeId)
            ->when($excludeScheduledWorkId, fn ($query) => $query->where('id', '!=', $excludeScheduledWorkId))
            ->whereDate('startDate', '<=', $endDate)
            ->whereDate('endDate', '>=', $startDate)
            ->get();

        foreach ($scheduledWorkCandidates as $existing) {
            if ($this->schedulesOverlap(
                $startDate,
                $endDate,
                $startTime,
                $endTime,
                $existing->startDate->format('Y-m-d'),
                $existing->endDate->format('Y-m-d'),
                $this->normalizeTime($existing->startTime ?? '09:00'),
                $this->normalizeTime($existing->endTime ?? '17:00'),
            )) {
                throw ValidationException::withMessages([
                    'overlap' => ['This shift overlaps with another scheduled shift for the same employee.'],
                ]);
            }
        }

        $employeeScheduleCandidates = ScheduleEmployeeTimesheet::query()
            ->where('employeeId', $employeeId)
            ->when(
                $excludeEmployeeScheduleIds !== [],
                fn ($query) => $query->whereNotIn('id', $excludeEmployeeScheduleIds),
            )
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        foreach ($employeeScheduleCandidates as $existing) {
            if ($this->schedulesOverlap(
                $startDate,
                $endDate,
                $startTime,
                $endTime,
                $existing->date->format('Y-m-d'),
                $existing->date->format('Y-m-d'),
                $this->normalizeTime($existing->startTime ?? '09:00'),
                $this->normalizeTime($existing->endTime ?? '17:00'),
            )) {
                throw ValidationException::withMessages([
                    'overlap' => ['This shift overlaps with another employee schedule for the same employee.'],
                ]);
            }
        }

        $timesheetCandidates = Timesheet::query()
            ->where('employeeId', $employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('roundOffClockInTime')
            ->whereNotNull('roundOffClockOutTime')
            ->get();

        foreach ($timesheetCandidates as $existing) {
            $existingStart = Carbon::parse($existing->roundOffClockInTime);
            $existingEnd = Carbon::parse($existing->roundOffClockOutTime);

            if ($this->schedulesOverlap(
                $startDate,
                $endDate,
                $startTime,
                $endTime,
                $existingStart->toDateString(),
                $existingEnd->toDateString(),
                $existingStart->format('H:i'),
                $existingEnd->format('H:i'),
            )) {
                throw ValidationException::withMessages([
                    'overlap' => ['This shift overlaps with an existing timesheet entry for the same employee.'],
                ]);
            }
        }
    }

    public function schedulesOverlap(
        string $startDate1,
        string $endDate1,
        string $startTime1,
        string $endTime1,
        string $startDate2,
        string $endDate2,
        string $startTime2,
        string $endTime2,
    ): bool {
        if ($startDate1 > $endDate2 || $startDate2 > $endDate1) {
            return false;
        }

        return EmployeeLeaveHelper::doTimesOverlap($startTime1, $endTime1, $startTime2, $endTime2);
    }

    private function normalizeTime(?string $time): string
    {
        if (!$time) {
            return '09:00';
        }

        return Carbon::parse($time)->format('H:i');
    }
}
