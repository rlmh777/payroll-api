<?php

namespace App\Modules\Hr\Services\Leave;

use App\Enums\LeaveStatusCode;
use App\Models\DepartmentHeadAssignment;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeReporting;
use App\Models\User;
use Illuminate\Support\Collection;

class LeaveSupervisorAuthorizationService
{
    /**
     * @var array<int, string>
     */
    private const LEAVE_ADMIN_ROLES = ['super-admin', 'admin', 'hr-admin'];

    public function isLeaveAdmin(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(self::LEAVE_ADMIN_ROLES);
    }

    public function actorEmployee(?User $user): ?Employee
    {
        if (!$user) {
            return null;
        }

        return Employee::query()->where('user_id', $user->id)->first();
    }

    /**
     * Direct active subordinates via employee_reporting and employee.supervisorId.
     *
     * @return Collection<int, string>
     */
    public function subordinateEmployeeIds(?User $user): Collection
    {
        $actor = $this->actorEmployee($user);
        if (!$actor) {
            return collect();
        }

        $fromReporting = EmployeeReporting::query()
            ->where('supervisor_id', $actor->id)
            ->where('is_active', true)
            ->pluck('subordinate_id');

        $fromSupervisorId = Employee::query()
            ->where('supervisorId', $actor->id)
            ->pluck('id');

        return $fromReporting
            ->merge($fromSupervisorId)
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    public function headedDepartmentIds(?User $user): Collection
    {
        $actor = $this->actorEmployee($user);
        if (!$actor) {
            return collect();
        }

        return DepartmentHeadAssignment::query()
            ->where('employeeId', $actor->id)
            ->where('isCurrent', true)
            ->whereNull('endDate')
            ->pluck('departmentId')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public function isDepartmentHead(?User $user): bool
    {
        return $this->headedDepartmentIds($user)->isNotEmpty();
    }

    /**
     * Leave List is visible to leave admins, supervisors, and department heads.
     */
    public function canAccessLeaveList(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if ($this->isLeaveAdmin($user)) {
            return true;
        }

        return $this->subordinateEmployeeIds($user)->isNotEmpty()
            || $this->isDepartmentHead($user);
    }

    /**
     * null = unrestricted (leave admin). Empty collection = no visible employees.
     *
     * @return Collection<int, string>|null
     */
    public function visibleEmployeeIdsForLeaveList(?User $user): ?Collection
    {
        if (!$user) {
            return collect();
        }

        if ($this->isLeaveAdmin($user)) {
            return null;
        }

        $ids = $this->subordinateEmployeeIds($user);

        $departmentIds = $this->headedDepartmentIds($user);
        if ($departmentIds->isNotEmpty()) {
            $departmentEmployeeIds = Employee::query()
                ->whereHas('employmentDetails', function ($query) use ($departmentIds) {
                    $query->where('isActive', true)
                        ->whereIn('departmentId', $departmentIds->all());
                })
                ->pluck('id')
                ->map(fn ($id) => (string) $id);

            $ids = $ids->merge($departmentEmployeeIds)->unique()->values();
        }

        return $ids;
    }

    public function canViewEmployeeLeaveBalances(?User $user, string $employeeId): bool
    {
        if (!$user) {
            return false;
        }

        if ($this->isLeaveAdmin($user)) {
            return true;
        }

        $actor = $this->actorEmployee($user);
        if ($actor && (string) $actor->id === (string) $employeeId) {
            return true;
        }

        $visible = $this->visibleEmployeeIdsForLeaveList($user);

        return $visible !== null && $visible->contains((string) $employeeId);
    }

    public function isDirectSupervisorOf(?User $user, string $employeeId): bool
    {
        return $this->subordinateEmployeeIds($user)->contains((string) $employeeId);
    }

    public function isDepartmentHeadForLeave(?User $user, EmployeeLeave $leave): bool
    {
        $departmentIds = $this->headedDepartmentIds($user);
        if ($departmentIds->isEmpty()) {
            return false;
        }

        if ($leave->departmentId && $departmentIds->contains((int) $leave->departmentId)) {
            return true;
        }

        $employeeDepartmentId = $leave->employee?->employmentDetails()
            ->where('isActive', true)
            ->value('departmentId');

        return $employeeDepartmentId && $departmentIds->contains((int) $employeeDepartmentId);
    }

    public function employeeHasSupervisor(EmployeeLeave $leave): bool
    {
        $leave->loadMissing('employee');

        return filled($leave->employee?->supervisorId);
    }

    /**
     * Supervisor step: only the assigned supervisor (or leave admin).
     */
    public function canActAsSupervisor(?User $user, EmployeeLeave $leave): bool
    {
        if (!$user) {
            return false;
        }

        if ($this->isLeaveAdmin($user)) {
            return true;
        }

        $approver = $this->actorEmployee($user);
        if (!$approver) {
            return false;
        }

        if ((string) $leave->employeeId === (string) $approver->id) {
            return false;
        }

        return $this->isDirectSupervisorOf($user, (string) $leave->employeeId);
    }

    /**
     * Department-head / final step.
     */
    public function canActAsDepartmentHead(?User $user, EmployeeLeave $leave): bool
    {
        if (!$user) {
            return false;
        }

        if ($this->isLeaveAdmin($user)) {
            return true;
        }

        $approver = $this->actorEmployee($user);
        if (!$approver) {
            return false;
        }

        if ((string) $leave->employeeId === (string) $approver->id) {
            return false;
        }

        return $this->isDepartmentHeadForLeave($user, $leave->loadMissing('employee'));
    }

    /**
     * Status-aware manage check for sequential approval.
     */
    public function canManageLeave(?User $user, EmployeeLeave $leave): bool
    {
        $leave->loadMissing(['leaveStatus', 'employee']);
        $status = $leave->leaveStatus?->codeEnum();

        if ($status === LeaveStatusCode::PendingSupervisorApproval) {
            return $this->canActAsSupervisor($user, $leave);
        }

        if ($status === LeaveStatusCode::PendingApproval) {
            return $this->canActAsDepartmentHead($user, $leave);
        }

        // Scheduled / other actionable states: either supervisor or dept head of the employee.
        return $this->canActAsSupervisor($user, $leave)
            || $this->canActAsDepartmentHead($user, $leave);
    }
}
