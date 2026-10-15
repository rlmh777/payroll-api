<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\PipelineAssigneeType;
use App\Models\DepartmentHeadAssignment;
use App\Models\Employee;
use App\Models\EmploymentDetail;
use App\Models\Timesheet;
use App\Models\User;
use App\Modules\Workflow\Services\PipelineEngine;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class TimesheetScopeService
{
    public function __construct(
        private readonly PipelineEngine $pipelineEngine,
    ) {
    }

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
        try {
            $template = $this->pipelineEngine->resolveTemplate(
                PipelineEngine::SUBJECT_TIMESHEET,
                'timesheet_approval',
            );
            $steps = $template->steps;
            if ($steps->isNotEmpty()) {
                if ($employee?->supervisorId) {
                    return (string) ($steps->first()?->domain_status ?: 'PENDING_SUPERVISOR');
                }

                $departmentHeadStep = $steps->firstWhere(
                    'assignee_type',
                    PipelineAssigneeType::DepartmentHead->value,
                );

                return (string) (
                    $departmentHeadStep?->domain_status
                    ?? $steps->first()?->domain_status
                    ?: 'PENDING'
                );
            }
        } catch (InvalidArgumentException) {
            // Fall through to legacy defaults.
        }

        return filled($employee?->supervisorId) ? 'PENDING_SUPERVISOR' : 'PENDING';
    }

    public function ensurePipelineStarted(Timesheet $timesheet, ?User $actor = null): void
    {
        $timesheet->loadMissing('employee');

        try {
            $started = $this->pipelineEngine->start(
                PipelineEngine::SUBJECT_TIMESHEET,
                (string) $timesheet->id,
                $actor,
                'timesheet_approval',
            );

            if (
                ! $timesheet->employee?->supervisorId
                && $started['instance']->currentStep?->assignee_type === PipelineAssigneeType::Supervisor->value
            ) {
                $departmentHeadStep = $started['instance']->template->steps
                    ->firstWhere('assignee_type', PipelineAssigneeType::DepartmentHead->value);
                if ($departmentHeadStep) {
                    $started['instance']->current_step_id = $departmentHeadStep->id;
                    $started['instance']->save();
                }
            }
        } catch (InvalidArgumentException) {
            // Template missing — keep legacy status-only flow.
        }
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
     * are remapped via the pipeline so the department head can final-approve.
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

            $this->ensurePipelineStarted($timesheet, $user);
            try {
                $result = $this->pipelineEngine->transition(
                    PipelineEngine::SUBJECT_TIMESHEET,
                    (string) $timesheet->id,
                    'reject',
                    $user,
                );

                return strtoupper((string) ($result['domain_status'] ?: 'REJECTED'));
            } catch (InvalidArgumentException) {
                return 'REJECTED';
            }
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
            $this->ensurePipelineStarted($timesheet, $user);
            try {
                $guard = 0;
                $status = 'APPROVED';
                while ($guard < 10) {
                    $instance = $this->pipelineEngine->activeInstance(
                        PipelineEngine::SUBJECT_TIMESHEET,
                        (string) $timesheet->id,
                    );
                    if (! $instance) {
                        break;
                    }
                    $result = $this->pipelineEngine->transition(
                        PipelineEngine::SUBJECT_TIMESHEET,
                        (string) $timesheet->id,
                        'approve',
                        $user,
                    );
                    $status = strtoupper((string) ($result['domain_status'] ?: 'APPROVED'));
                    if ($result['completed']) {
                        return $status;
                    }
                    $guard++;
                }

                return $status;
            } catch (InvalidArgumentException) {
                return 'APPROVED';
            }
        }

        if (! $this->canActOnCurrentStep($user, $timesheet, $current)) {
            if ($current === 'PENDING_SUPERVISOR') {
                abort(403, 'Only the employee supervisor can approve at this step.');
            }
            if ($current === 'PENDING') {
                abort(403, 'Only a department head can perform final timesheet approval.');
            }
            abort(422, 'Timesheet cannot be approved from its current status.');
        }

        $this->ensurePipelineStarted($timesheet, $user);

        try {
            $result = $this->pipelineEngine->transition(
                PipelineEngine::SUBJECT_TIMESHEET,
                (string) $timesheet->id,
                'approve',
                $user,
            );

            return strtoupper((string) ($result['domain_status'] ?: ($result['completed'] ? 'APPROVED' : 'PENDING')));
        } catch (InvalidArgumentException) {
            if ($current === 'PENDING_SUPERVISOR') {
                return 'PENDING';
            }

            if ($current === 'PENDING') {
                return 'APPROVED';
            }

            abort(422, 'Timesheet cannot be approved from its current status.');
        }
    }

    private function canActOnCurrentStep(User $user, Timesheet $timesheet, string $current): bool
    {
        $instance = $this->pipelineEngine->activeInstance(
            PipelineEngine::SUBJECT_TIMESHEET,
            (string) $timesheet->id,
        );
        $assigneeType = $instance ? $this->pipelineEngine->currentAssigneeType($instance) : null;

        if ($assigneeType === PipelineAssigneeType::Supervisor) {
            return $this->isDirectSupervisorOf($user, $timesheet);
        }

        if ($assigneeType === PipelineAssigneeType::DepartmentHead) {
            return $this->isDepartmentHeadForTimesheet($user, $timesheet);
        }

        if ($current === 'PENDING_SUPERVISOR') {
            return $this->isDirectSupervisorOf($user, $timesheet);
        }

        if ($current === 'PENDING') {
            return $this->isDepartmentHeadForTimesheet($user, $timesheet);
        }

        return false;
    }
}
