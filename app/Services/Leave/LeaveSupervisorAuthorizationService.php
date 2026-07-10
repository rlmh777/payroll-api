<?php

namespace App\Services\Leave;

use App\Models\DepartmentHeadAssignment;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\User;

class LeaveSupervisorAuthorizationService
{
    public function canManageLeave(?User $user, EmployeeLeave $leave): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasAnyRole(['super-admin', 'admin', 'payroll-officer'])) {
            return true;
        }

        $approver = Employee::query()->where('user_id', $user->id)->first();
        if (!$approver) {
            return false;
        }

        if ((string) $leave->employeeId === (string) $approver->id) {
            return false;
        }

        $departmentIds = DepartmentHeadAssignment::query()
            ->where('employeeId', $approver->id)
            ->where('isCurrent', true)
            ->whereNull('endDate')
            ->pluck('departmentId')
            ->map(fn ($id) => (int) $id);

        if ($departmentIds->isEmpty()) {
            return false;
        }

        if ($leave->departmentId && $departmentIds->contains((int) $leave->departmentId)) {
            return true;
        }

        $employeeDepartmentId = $leave->employee?->departmentId
            ?? $leave->employee?->employmentDetails()
                ->where('isActive', true)
                ->value('departmentId');

        return $employeeDepartmentId && $departmentIds->contains((int) $employeeDepartmentId);
    }
}
