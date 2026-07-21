<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Models\DepartmentHeadAssignment;
use App\Models\Employee;
use App\Models\EmploymentDetail;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TimesheetScopeService
{
    public function canViewAllEmployees(User $user): bool
    {
        return Access::canViewAllEmployees($user);
    }

    public function isTimesheetAdmin(User $user): bool
    {
        return $this->canViewAllEmployees($user);
    }

    public function actorEmployee(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->first();
    }

    /**
     * @return Collection<int, string>
     */
    public function supervisedEmployeeIds(User $user): Collection
    {
        $employee = $this->actorEmployee($user);
        if (! $employee) {
            return collect();
        }

        return Employee::query()
            ->where('supervisorId', $employee->id)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    public function headedDepartmentIds(User $user): Collection
    {
        $employee = $this->actorEmployee($user);
        if (! $employee) {
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

    public function isSupervisor(User $user): bool
    {
        return $this->supervisedEmployeeIds($user)->isNotEmpty();
    }

    public function initialApprovalStatus(?Employee $employee): string
    {
        return filled($employee?->supervisorId) ? 'PENDING_SUPERVISOR' : 'PENDING';
    }

    public function applyScope(Builder $query, User $user): void
    {
        if ($this->canViewAllEmployees($user)) {
            return;
        }

        $employee = $this->actorEmployee($user);
        if (! $employee) {
            $query->whereRaw('1 = 0');

            return;
        }

        $departmentIds = $this->headedDepartmentIds($user);
        $superviseeIds = $this->supervisedEmployeeIds($user);

        if ($departmentIds->isEmpty() && $superviseeIds->isEmpty()) {
            $query->where('employeeId', $employee->id);

            return;
        }

        $query->where(function (Builder $scoped) use ($employee, $departmentIds, $superviseeIds) {
            $scoped->where('employeeId', $employee->id);

            if ($departmentIds->isNotEmpty()) {
                $scoped->orWhereIn('departmentId', $departmentIds->all());
            }

            if ($superviseeIds->isNotEmpty()) {
                $scoped->orWhereIn('employeeId', $superviseeIds->all());
            }
        });
    }

    public function canAccessTimesheet(User $user, Timesheet $timesheet): bool
    {
        if ($this->canViewAllEmployees($user)) {
            return true;
        }

        $employee = $this->actorEmployee($user);
        if (! $employee) {
            return false;
        }

        if ((string) $timesheet->employeeId === (string) $employee->id) {
            return true;
        }

        $departmentIds = $this->headedDepartmentIds($user);
        if (
            $departmentIds->isNotEmpty()
            && $timesheet->departmentId !== null
            && $departmentIds->contains((int) $timesheet->departmentId)
        ) {
            return true;
        }

        return $this->supervisedEmployeeIds($user)->contains((string) $timesheet->employeeId);
    }

    public function canAccessEmployee(User $user, Employee $employee, ?EmploymentDetail $employmentDetail = null): bool
    {
        if ($this->canViewAllEmployees($user)) {
            return true;
        }

        $actor = $this->actorEmployee($user);
        if (! $actor) {
            return false;
        }

        if ((string) $employee->id === (string) $actor->id) {
            return true;
        }

        $departmentIds = $this->headedDepartmentIds($user);
        if (
            $employmentDetail?->departmentId !== null
            && $departmentIds->contains((int) $employmentDetail->departmentId)
        ) {
            return true;
        }

        return $this->supervisedEmployeeIds($user)->contains((string) $employee->id);
    }

    public function isDirectSupervisorOf(User $user, Timesheet $timesheet): bool
    {
        return $this->supervisedEmployeeIds($user)->contains((string) $timesheet->employeeId);
    }

    public function isDepartmentHeadForTimesheet(User $user, Timesheet $timesheet): bool
    {
        $departmentIds = $this->headedDepartmentIds($user);
        if ($departmentIds->isEmpty() || $timesheet->departmentId === null) {
            return false;
        }

        return $departmentIds->contains((int) $timesheet->departmentId);
    }

    /**
     * Advance or finalise approval. Client typically sends APPROVED; supervisors
     * are remapped to PENDING so the department head can final-approve.
     */
    public function resolveApprovalTarget(User $user, Timesheet $timesheet, string $requestedStatus): string
    {
        $requested = strtoupper($requestedStatus);
        $current = strtoupper((string) $timesheet->approvalStatus);
        $isAdmin = $this->isTimesheetAdmin($user);

        if ($requested === 'REJECTED') {
            if (! $isAdmin && ! $this->canActOnCurrentStep($user, $timesheet, $current)) {
                abort(403, 'You are not allowed to reject this timesheet at its current step.');
            }

            return 'REJECTED';
        }

        if (in_array($requested, ['PENDING', 'PENDING_SUPERVISOR'], true)) {
            if (! $isAdmin && ! $this->canAccessTimesheet($user, $timesheet)) {
                abort(403, 'You are not allowed to update this timesheet.');
            }

            $timesheet->loadMissing('employee');

            return $this->initialApprovalStatus($timesheet->employee);
        }

        if ($requested !== 'APPROVED') {
            abort(422, 'The selected approval status is invalid.');
        }

        if ($isAdmin) {
            return 'APPROVED';
        }

        if ($current === 'PENDING_SUPERVISOR') {
            if (! $this->isDirectSupervisorOf($user, $timesheet)) {
                abort(403, 'Only the employee supervisor can approve at this step.');
            }

            return 'PENDING';
        }

        if ($current === 'PENDING') {
            if (! $this->isDepartmentHeadForTimesheet($user, $timesheet)) {
                abort(403, 'Only a department head can perform final timesheet approval.');
            }

            return 'APPROVED';
        }

        abort(422, 'Timesheet cannot be approved from its current status.');
    }

    private function canActOnCurrentStep(User $user, Timesheet $timesheet, string $current): bool
    {
        if ($current === 'PENDING_SUPERVISOR') {
            return $this->isDirectSupervisorOf($user, $timesheet);
        }

        if ($current === 'PENDING') {
            return $this->isDepartmentHeadForTimesheet($user, $timesheet);
        }

        return false;
    }
}
