<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Models\DepartmentHeadAssignment;
use App\Models\Employee;
use App\Models\EmployeeReporting;
use App\Models\User;
use Illuminate\Support\Collection;

class TimesheetIssueRecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function recipientsForEmployee(Employee $employee, ?int $departmentId): Collection
    {
        $supervisorEmployeeIds = EmployeeReporting::query()
            ->where('subordinate_id', $employee->id)
            ->where('is_active', true)
            ->pluck('supervisor_id');

        if ($employee->supervisorId) {
            $supervisorEmployeeIds->push($employee->supervisorId);
        }

        $users = User::query()
            ->whereHas('employee', fn ($query) => $query->whereIn('id', $supervisorEmployeeIds->unique()->filter()->values()))
            ->get();

        if ($departmentId) {
            $departmentHeadEmployeeIds = DepartmentHeadAssignment::query()
                ->where('departmentId', $departmentId)
                ->where('isCurrent', true)
                ->whereNull('endDate')
                ->pluck('employeeId');

            $departmentHeadUsers = User::query()
                ->whereHas('employee', fn ($query) => $query->whereIn('id', $departmentHeadEmployeeIds->unique()->filter()->values()))
                ->get();

            $users = $users->merge($departmentHeadUsers);
        }

        return $users->unique('id')->values();
    }
}
