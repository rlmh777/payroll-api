<?php

namespace App\Services\Attendance;

use App\Helpers\EmployeeLeaveHelper;
use App\Models\EmployeeLeave;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetLeaveConflictService
{
    public const CONFLICT_MESSAGE = 'Work time recorded during approved leave.';

    /**
     * @param array<int, EmployeeLeave> $leaves
     */
    public function hasApprovedLeaveOnDate(array $leaves): bool
    {
        return $leaves !== [];
    }

    /**
     * @param array<int, EmployeeLeave> $leaves
     */
    public function hasFullDayLeave(array $leaves): bool
    {
        foreach ($leaves as $leave) {
            if ($this->isFullDayLeave($leave)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, EmployeeLeave> $leaves
     * @return Collection<int, EmployeeLeave>
     */
    public function conflictingLeaves(
        array $leaves,
        string $workDate,
        ?string $workStartDatetime,
        ?string $workEndDatetime,
        bool $expectsScheduledWork = false,
    ): Collection {
        if ($leaves === []) {
            return collect();
        }

        if ($expectsScheduledWork && !$workStartDatetime && !$workEndDatetime) {
            return collect($leaves);
        }

        $workFrom = $workStartDatetime ? Carbon::parse($workStartDatetime)->format('H:i') : null;
        $workTo = $workEndDatetime ? Carbon::parse($workEndDatetime)->format('H:i') : null;

        return collect($leaves)->filter(function (EmployeeLeave $leave) use ($workDate, $workFrom, $workTo) {
            if (!$this->leaveCoversDate($leave, $workDate)) {
                return false;
            }

            if ($this->isFullDayLeave($leave)) {
                return $workFrom !== null || $workTo !== null;
            }

            if (!$workFrom || !$workTo) {
                return true;
            }

            return EmployeeLeaveHelper::doTimesOverlap(
                $leave->fromTime,
                $leave->toTime,
                $workFrom,
                $workTo,
            );
        })->values();
    }

    public function leaveCoversDate(EmployeeLeave $leave, string $workDate): bool
    {
        $date = Carbon::parse($workDate)->toDateString();
        $startDate = Carbon::parse($leave->startDate)->toDateString();
        $endDate = Carbon::parse($leave->endDate)->toDateString();

        return $date >= $startDate && $date <= $endDate;
    }

    private function isFullDayLeave(EmployeeLeave $leave): bool
    {
        return in_array((string) $leave->duration, ['Full Day', 'All Days'], true);
    }
}
