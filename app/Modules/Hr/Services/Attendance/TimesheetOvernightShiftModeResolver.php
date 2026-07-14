<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\OvernightShiftMode;
use App\Models\Department;
use App\Models\Timesheet;

class TimesheetOvernightShiftModeResolver
{
    public function forTimesheet(Timesheet $timesheet): OvernightShiftMode
    {
        if (!$timesheet->departmentId) {
            return OvernightShiftMode::SplitAtMidnight;
        }

        $department = Department::query()->find($timesheet->departmentId);

        return OvernightShiftMode::fromStored($department?->overnightShiftMode);
    }

    public function forDepartmentId(?int $departmentId): OvernightShiftMode
    {
        if (!$departmentId) {
            return OvernightShiftMode::SplitAtMidnight;
        }

        $department = Department::query()->find($departmentId);

        return OvernightShiftMode::fromStored($department?->overnightShiftMode);
    }

    public function shouldSplitAtMidnight(Timesheet $timesheet): bool
    {
        return $this->forTimesheet($timesheet)->splitsAtMidnight();
    }
}
