<?php

namespace App\Services\Attendance;

use App\Models\DepartmentHeadAssignment;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TimesheetScopeService
{
    public function canViewAllEmployees(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin', 'payroll-officer']);
    }

    /**
     * @return Collection<int, int>
     */
    public function headedDepartmentIds(User $user): Collection
    {
        $employee = Employee::query()->where('user_id', $user->id)->first();
        if (!$employee) {
            return collect();
        }

        return DepartmentHeadAssignment::query()
            ->where('employeeId', $employee->id)
            ->where('isCurrent', true)
            ->whereNull('endDate')
            ->pluck('departmentId')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public function isDepartmentHead(User $user): bool
    {
        return $this->headedDepartmentIds($user)->isNotEmpty();
    }

    public function applyScope(Builder $query, User $user): void
    {
        if ($this->canViewAllEmployees($user)) {
            return;
        }

        $employee = Employee::query()->where('user_id', $user->id)->first();
        if (!$employee) {
            $query->whereRaw('1 = 0');

            return;
        }

        $departmentIds = $this->headedDepartmentIds($user);
        if ($departmentIds->isNotEmpty()) {
            $query->whereIn('departmentId', $departmentIds->all());

            return;
        }

        $query->where('employeeId', $employee->id);
    }

    public function canAccessTimesheet(User $user, Timesheet $timesheet): bool
    {
        if ($this->canViewAllEmployees($user)) {
            return true;
        }

        $employee = Employee::query()->where('user_id', $user->id)->first();
        if (!$employee) {
            return false;
        }

        $departmentIds = $this->headedDepartmentIds($user);
        if ($departmentIds->isNotEmpty()) {
            return $timesheet->departmentId !== null
                && $departmentIds->contains((int) $timesheet->departmentId);
        }

        return (string) $timesheet->employeeId === (string) $employee->id;
    }
}
