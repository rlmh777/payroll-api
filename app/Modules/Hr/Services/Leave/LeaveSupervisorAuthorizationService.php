<?php

namespace App\Modules\Hr\Services\Leave;

use App\Enums\LeaveStatusCode;
use App\Models\DepartmentHeadAssignment;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeReporting;
use App\Models\PipelineAction;
use App\Models\PipelineInstance;
use App\Models\User;
use App\Modules\Workflow\Services\PipelineEngine;
use App\Support\Access;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LeaveSupervisorAuthorizationService
{
    public function isLeaveAdmin(?User $user): bool
    {
        return Access::canManageAllLeave($user);
    }

    public function actorEmployee(?User $user): ?Employee
    {
        if (! $user) {
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
        if (! $actor) {
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
        if (! $actor) {
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
     * Leave List is visible to leave admins, supervisors, department heads, and accounts.
     */
    public function canAccessLeaveList(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->isLeaveAdmin($user) || Access::canConfirmLeavePayment($user)) {
            return true;
        }

        return $this->subordinateEmployeeIds($user)->isNotEmpty()
            || $this->isDepartmentHead($user);
    }

    /**
     * null = unrestricted (leave admin / accounts confirmer). Empty collection = no visible employees.
     *
     * @return Collection<int, string>|null
     */
    public function visibleEmployeeIdsForLeaveList(?User $user): ?Collection
    {
        if (! $user) {
            return collect();
        }

        if ($this->isLeaveAdmin($user) || Access::canConfirmLeavePayment($user)) {
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
        if (! $user) {
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

    public function isOwnEmployee(?User $user, string $employeeId): bool
    {
        $actor = $this->actorEmployee($user);

        return $actor !== null && (string) $actor->id === (string) $employeeId;
    }

    /**
     * Employee IDs the user may read leave records for.
     * null = unrestricted. Empty collection = none.
     *
     * @return Collection<int, string>|null
     */
    public function readableEmployeeIds(?User $user): ?Collection
    {
        if (! $user) {
            return collect();
        }

        if ($this->isLeaveAdmin($user) || Access::canConfirmLeavePayment($user)) {
            return null;
        }

        $ids = $this->visibleEmployeeIdsForLeaveList($user) ?? collect();
        $actor = $this->actorEmployee($user);
        if ($actor) {
            $ids->push((string) $actor->id);
        }

        return $ids->unique()->values();
    }

    public function canViewEmployeeLeaves(?User $user, string $employeeId): bool
    {
        if (! $user) {
            return false;
        }

        $readable = $this->readableEmployeeIds($user);
        if ($readable === null) {
            return true;
        }

        return $readable->contains((string) $employeeId);
    }

    public function canViewLeave(?User $user, EmployeeLeave $leave): bool
    {
        return $this->canViewEmployeeLeaves($user, (string) $leave->employeeId);
    }

    public function canCreateLeaveFor(?User $user, string $employeeId): bool
    {
        if ($this->isLeaveAdmin($user)) {
            return true;
        }

        if (! Access::canWriteEmployeeLeave($user)) {
            return false;
        }

        return $this->isOwnEmployee($user, $employeeId)
            || $this->isDirectSupervisorOf($user, $employeeId)
            || $this->employeeIsInHeadedDepartment($user, $employeeId);
    }

    /**
     * Assigned leave skips approval. Leave admins may assign anyone.
     * Supervisors may assign direct reports, and department heads may assign
     * employees in a department they currently head.
     */
    public function canAssignLeaveTo(?User $user, string $employeeId): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->isLeaveAdmin($user)) {
            return true;
        }

        if ($this->isOwnEmployee($user, $employeeId) || ! Access::canWriteEmployeeLeave($user)) {
            return false;
        }

        return $this->isDirectSupervisorOf($user, $employeeId)
            || $this->employeeIsInHeadedDepartment($user, $employeeId);
    }

    public function employeeIsInHeadedDepartment(?User $user, string $employeeId): bool
    {
        $departmentIds = $this->headedDepartmentIds($user);
        if ($departmentIds->isEmpty()) {
            return false;
        }

        return Employee::query()
            ->whereKey($employeeId)
            ->whereHas('employmentDetails', function ($query) use ($departmentIds) {
                $query->where('isActive', true)
                    ->whereIn('departmentId', $departmentIds->all());
            })
            ->exists();
    }

    public function isUnapprovedLeave(EmployeeLeave $leave): bool
    {
        $leave->loadMissing('leaveStatus');
        $code = $leave->leaveStatus?->codeEnum();

        return $code !== null && $code->isPendingAction();
    }

    /**
     * Update / delete / cancel an unapproved leave for self or a direct report.
     * Leave admins may mutate any leave.
     */
    public function canMutateLeave(?User $user, EmployeeLeave $leave): bool
    {
        if ($this->isLeaveAdmin($user)) {
            return true;
        }

        if (! Access::canWriteEmployeeLeave($user) || ! $this->isUnapprovedLeave($leave)) {
            return false;
        }

        $employeeId = (string) $leave->employeeId;

        return $this->isOwnEmployee($user, $employeeId)
            || $this->isDirectSupervisorOf($user, $employeeId);
    }

    public function canCancelLeave(?User $user, EmployeeLeave $leave): bool
    {
        if ($this->isLeaveAdmin($user)) {
            $leave->loadMissing('leaveStatus');
            $code = $leave->leaveStatus?->codeEnum();

            return $code !== null && in_array($code, LeaveStatusCode::cancellableStatuses(), true);
        }

        return $this->canMutateLeave($user, $leave);
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

        return $this->employeeRecordHasSupervisor($leave->employee);
    }

    /**
     * A supervisor is assigned when employee.supervisorId is set or an active
     * employee_reporting row exists. Either one owns the supervisor pipeline step.
     */
    public function employeeRecordHasSupervisor(?Employee $employee): bool
    {
        if (! $employee) {
            return false;
        }

        if (filled($employee->supervisorId)) {
            return true;
        }

        return EmployeeReporting::query()
            ->where('subordinate_id', $employee->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Supervisor step: only the assigned supervisor (or leave admin).
     */
    public function canActAsSupervisor(?User $user, EmployeeLeave $leave): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->isLeaveAdmin($user)) {
            return true;
        }

        $approver = $this->actorEmployee($user);
        if (! $approver) {
            return false;
        }

        if ((string) $leave->employeeId === (string) $approver->id) {
            return false;
        }

        return $this->isDirectSupervisorOf($user, (string) $leave->employeeId);
    }

    /**
     * Department-head step (legacy templates).
     */
    public function canActAsDepartmentHead(?User $user, EmployeeLeave $leave): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->isLeaveAdmin($user)) {
            return true;
        }

        $approver = $this->actorEmployee($user);
        if (! $approver) {
            return false;
        }

        if ((string) $leave->employeeId === (string) $approver->id) {
            return false;
        }

        return $this->isDepartmentHeadForLeave($user, $leave->loadMissing('employee'));
    }

    /**
     * HR step: leave admins or users with the hr role.
     */
    public function canActAsHr(?User $user, EmployeeLeave $leave): bool
    {
        if (! $user) {
            return false;
        }

        $approver = $this->actorEmployee($user);
        if ($approver && (string) $leave->employeeId === (string) $approver->id) {
            return false;
        }

        return $this->isLeaveAdmin($user) || $user->hasRole('hr');
    }

    /**
     * Accounts payment confirmation step.
     */
    public function canActAsAccounts(?User $user, EmployeeLeave $leave): bool
    {
        if (! $user) {
            return false;
        }

        $approver = $this->actorEmployee($user);
        if ($approver && (string) $leave->employeeId === (string) $approver->id) {
            return false;
        }

        return Access::canConfirmLeavePayment($user);
    }

    public function canActAsRole(?User $user, EmployeeLeave $leave, ?string $role): bool
    {
        if (! $user) {
            return false;
        }

        $normalized = Str::lower(trim((string) $role));
        if (in_array($normalized, ['accountant', 'payroll-accountant', 'accounts'], true)) {
            return $this->canActAsAccounts($user, $leave);
        }

        if ($normalized === 'admin' || $normalized === 'hr') {
            return $this->canActAsHr($user, $leave);
        }

        if ($normalized !== '' && $user->hasRole($normalized)) {
            return true;
        }

        return $this->isLeaveAdmin($user);
    }

    /**
     * Status-aware manage check for sequential approval.
     */
    public function canManageLeave(?User $user, EmployeeLeave $leave): bool
    {
        $leave->loadMissing(['leaveStatus', 'employee']);
        $status = $leave->leaveStatus?->codeEnum();

        if ($this->unactedSupervisorGate($leave)) {
            return $this->canActAsSupervisor($user, $leave);
        }

        if ($status === LeaveStatusCode::PendingSupervisorApproval) {
            return $this->canActAsSupervisor($user, $leave);
        }

        if ($status === LeaveStatusCode::PendingApproval) {
            return $this->canActAsDepartmentHead($user, $leave)
                || $this->canActAsHr($user, $leave);
        }

        if ($status === LeaveStatusCode::PendingHrApproval) {
            return $this->canActAsHr($user, $leave);
        }

        if ($status === LeaveStatusCode::PendingAccountsConfirmation) {
            return $this->canActAsAccounts($user, $leave);
        }

        return $this->canActAsSupervisor($user, $leave)
            || $this->canActAsDepartmentHead($user, $leave)
            || $this->canActAsHr($user, $leave)
            || $this->canActAsAccounts($user, $leave);
    }

    /**
     * Submitted leave whose supervisor step was skipped because supervisorId
     * was empty, even though an active reporting supervisor exists.
     */
    private function unactedSupervisorGate(EmployeeLeave $leave): bool
    {
        $status = $leave->leaveStatus?->codeEnum();
        if (! in_array($status, [
            LeaveStatusCode::PendingHrApproval,
            LeaveStatusCode::PendingApproval,
        ], true)) {
            return false;
        }

        if (! $this->employeeRecordHasSupervisor($leave->employee)) {
            return false;
        }

        $instanceIds = PipelineInstance::query()
            ->where('subject_type', PipelineEngine::SUBJECT_LEAVE)
            ->where('subject_id', (string) $leave->id)
            ->pluck('id');

        if ($instanceIds->isEmpty()) {
            return true;
        }

        return ! PipelineAction::query()
            ->whereIn('pipeline_instance_id', $instanceIds)
            ->exists();
    }
}
