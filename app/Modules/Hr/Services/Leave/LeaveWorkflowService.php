<?php

namespace App\Modules\Hr\Services\Leave;

use App\Enums\LeavePaymentTreatment;
use App\Enums\LeaveStatusCode;
use App\Enums\PipelineAssigneeType;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\LeaveStatus;
use App\Models\User;
use App\Modules\Workflow\Services\PipelineEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LeaveWorkflowService
{
    public function __construct(
        private readonly LeaveSupervisorAuthorizationService $authorization,
        private readonly PipelineEngine $pipelineEngine,
    ) {
    }

    public function defaultStatusId(): int
    {
        return LeaveStatus::idForCode(LeaveStatusCode::PendingSupervisorApproval);
    }

    public function initialStatusIdForEmployee(?Employee $employee): int
    {
        $domainStatus = $this->resolveInitialDomainStatus($employee);
        if ($domainStatus) {
            $code = LeaveStatusCode::fromStored($domainStatus);
            if ($code) {
                return LeaveStatus::idForCode($code);
            }
        }

        if ($employee?->supervisorId) {
            return LeaveStatus::idForCode(LeaveStatusCode::PendingSupervisorApproval);
        }

        return LeaveStatus::idForCode(LeaveStatusCode::PendingHrApproval);
    }

    /**
     * Assigned leave: vacations go to accounts for payment choice;
     * all other leave types are scheduled with a default payment treatment.
     */
    public function initialStatusIdForAssignedLeave(?\App\Models\LeaveType $leaveType = null): int
    {
        if ($leaveType?->isVacation()) {
            return LeaveStatus::idForCode(LeaveStatusCode::PendingAccountsConfirmation);
        }

        return LeaveStatus::idForCode(LeaveStatusCode::Scheduled);
    }

    public function ensurePipelineStarted(EmployeeLeave $leave, ?User $actor = null): void
    {
        $leave->loadMissing(['employee', 'leaveStatus']);

        $code = $leave->leaveStatus?->codeEnum();
        if ($code && in_array($code, [
            LeaveStatusCode::Scheduled,
            LeaveStatusCode::Taken,
            LeaveStatusCode::Rejected,
            LeaveStatusCode::Cancelled,
        ], true)) {
            return;
        }

        try {
            $started = $this->pipelineEngine->start(
                PipelineEngine::SUBJECT_LEAVE,
                (string) $leave->id,
                $actor,
                'leave_approval',
            );
            $this->skipSupervisorStepIfNeeded($started['instance'], $leave->employee);
            $this->alignPipelineToLeaveStatus($started['instance'], $leave);
        } catch (InvalidArgumentException) {
            // Template missing — keep legacy status-only flow.
        }
    }

    public function syncScheduledToTaken(?string $employeeId = null): void
    {
        $scheduledId = LeaveStatus::idForCode(LeaveStatusCode::Scheduled);
        $takenId = LeaveStatus::idForCode(LeaveStatusCode::Taken);

        $query = EmployeeLeave::query()
            ->where('leaveStatusId', $scheduledId)
            ->whereDate('endDate', '<', Carbon::today()->toDateString());

        if ($employeeId) {
            $query->where('employeeId', $employeeId);
        }

        $query->update(['leaveStatusId' => $takenId]);
    }

    public function approve(
        EmployeeLeave $leave,
        ?User $actor,
        ?string $note = null,
        ?string $paymentTreatment = null,
    ): EmployeeLeave {
        $leave->loadMissing(['leaveStatus', 'employee', 'leaveType']);
        $this->ensurePipelineStarted($leave, $actor);
        $this->assertActorForCurrentStep($leave, $actor, 'approve');

        $resolvedTreatment = null;
        if ($this->isAccountsConfirmationStep($leave)) {
            $resolvedTreatment = $this->requiresPaymentTreatmentChoice($leave)
                ? $this->requirePaymentTreatment($paymentTreatment)
                : $this->defaultPaymentTreatment($leave);
        }

        try {
            $result = $this->pipelineEngine->transition(
                PipelineEngine::SUBJECT_LEAVE,
                (string) $leave->id,
                'approve',
                $actor,
                $note,
            );
        } catch (InvalidArgumentException) {
            return $this->legacyApprove($leave, $actor, $note, $resolvedTreatment);
        }

        $target = $this->resolveLeaveTargetStatus($leave, $result['domain_status'], $result['completed']);

        if (
            $target === LeaveStatusCode::PendingAccountsConfirmation
            && ! $this->requiresPaymentTreatmentChoice($leave)
        ) {
            return $this->autoCompleteAccountsConfirmation($leave, $actor, $note);
        }

        return $this->applyTransition($leave, $target, $actor, $note, $resolvedTreatment);
    }

    public function reject(EmployeeLeave $leave, ?User $actor, ?string $note = null): EmployeeLeave
    {
        $leave->loadMissing(['leaveStatus', 'employee']);
        $this->ensurePipelineStarted($leave, $actor);
        $this->assertActorForCurrentStep($leave, $actor, 'reject');

        try {
            $result = $this->pipelineEngine->transition(
                PipelineEngine::SUBJECT_LEAVE,
                (string) $leave->id,
                'reject',
                $actor,
                $note,
            );
            $target = LeaveStatusCode::fromStored($result['domain_status']) ?? LeaveStatusCode::Rejected;
        } catch (InvalidArgumentException) {
            return $this->legacyReject($leave, $actor, $note);
        }

        return $this->applyTransition($leave, $target, $actor, $note);
    }

    public function cancel(EmployeeLeave $leave, ?User $actor, ?string $note = null): EmployeeLeave
    {
        $leave->loadMissing(['leaveStatus', 'employee']);
        $this->ensurePipelineStarted($leave, $actor);

        try {
            $result = $this->pipelineEngine->transition(
                PipelineEngine::SUBJECT_LEAVE,
                (string) $leave->id,
                'cancel',
                $actor,
                $note,
            );
            $target = LeaveStatusCode::fromStored($result['domain_status']) ?? LeaveStatusCode::Cancelled;
        } catch (InvalidArgumentException) {
            $this->assertCanTransition($leave, LeaveStatusCode::cancellableStatuses());

            return $this->applyTransition($leave, LeaveStatusCode::Cancelled, $actor, $note);
        }

        return $this->applyTransition($leave, $target, $actor, $note);
    }

    public function submitForFinalApproval(EmployeeLeave $leave, ?User $actor): EmployeeLeave
    {
        return $this->approve($leave, $actor);
    }

    public function isAccountsConfirmationStep(EmployeeLeave $leave): bool
    {
        $leave->loadMissing('leaveStatus');
        if ($leave->leaveStatus?->codeEnum() === LeaveStatusCode::PendingAccountsConfirmation) {
            return true;
        }

        $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_LEAVE, (string) $leave->id);
        if (! $instance) {
            return false;
        }

        $instance->loadMissing('currentStep');

        return $instance->currentStep?->key === 'accounts'
            || $instance->currentStep?->domain_status === LeaveStatusCode::PendingAccountsConfirmation->value;
    }

    public function requiresPaymentTreatmentChoice(EmployeeLeave $leave): bool
    {
        $leave->loadMissing('leaveType');

        return (bool) $leave->leaveType?->isVacation();
    }

    public function defaultPaymentTreatment(EmployeeLeave $leave): LeavePaymentTreatment
    {
        $leave->loadMissing('leaveType');

        if ($leave->leaveType && ! (bool) $leave->leaveType->isPaid) {
            return LeavePaymentTreatment::Unpaid;
        }

        return LeavePaymentTreatment::PaidWithPayroll;
    }

    private function requirePaymentTreatment(?string $paymentTreatment): LeavePaymentTreatment
    {
        $treatment = LeavePaymentTreatment::fromStored($paymentTreatment);

        if (! $treatment) {
            abort(422, 'Select whether vacation is unpaid, paid with payroll, or already paid in advance.');
        }

        return $treatment;
    }

    private function autoCompleteAccountsConfirmation(
        EmployeeLeave $leave,
        ?User $actor,
        ?string $note = null,
    ): EmployeeLeave {
        $treatment = $this->defaultPaymentTreatment($leave);

        try {
            $result = $this->pipelineEngine->transition(
                PipelineEngine::SUBJECT_LEAVE,
                (string) $leave->id,
                'approve',
                $actor,
                $note,
            );
            $target = $this->resolveLeaveTargetStatus($leave, $result['domain_status'], $result['completed']);
        } catch (InvalidArgumentException) {
            $target = Carbon::parse($leave->endDate)->endOfDay()->lt(Carbon::today())
                ? LeaveStatusCode::Taken
                : LeaveStatusCode::Scheduled;
        }

        if ($target === LeaveStatusCode::PendingAccountsConfirmation) {
            $target = Carbon::parse($leave->endDate)->endOfDay()->lt(Carbon::today())
                ? LeaveStatusCode::Taken
                : LeaveStatusCode::Scheduled;
        }

        return $this->applyTransition($leave, $target, $actor, $note, $treatment);
    }

    private function resolveInitialDomainStatus(?Employee $employee): ?string
    {
        try {
            $template = $this->pipelineEngine->resolveTemplate(PipelineEngine::SUBJECT_LEAVE, 'leave_approval');
        } catch (InvalidArgumentException) {
            return null;
        }

        $steps = $template->steps;
        if ($steps->isEmpty()) {
            return null;
        }

        if ($employee?->supervisorId) {
            return $steps->first()?->domain_status;
        }

        $hrStep = $steps->firstWhere('assignee_type', PipelineAssigneeType::Admin->value)
            ?? $steps->firstWhere('key', 'hr');

        return $hrStep?->domain_status
            ?? $steps->skip(1)->first()?->domain_status
            ?? $steps->first()?->domain_status;
    }

    private function skipSupervisorStepIfNeeded($instance, ?Employee $employee): void
    {
        if ($employee?->supervisorId) {
            return;
        }

        $instance->loadMissing(['template.steps', 'currentStep']);
        if ($instance->currentStep?->assignee_type !== PipelineAssigneeType::Supervisor->value) {
            return;
        }

        $nextStep = $instance->template->steps
            ->firstWhere('assignee_type', PipelineAssigneeType::Admin->value)
            ?? $instance->template->steps->firstWhere('key', 'hr')
            ?? $instance->template->steps->firstWhere('assignee_type', PipelineAssigneeType::DepartmentHead->value);

        if (! $nextStep) {
            return;
        }

        $instance->current_step_id = $nextStep->id;
        $instance->save();
    }

    /**
     * Keep the pipeline pointer aligned with the leave domain status
     * (e.g. assigned leave that starts at accounts confirmation).
     */
    private function alignPipelineToLeaveStatus($instance, EmployeeLeave $leave): void
    {
        $leave->loadMissing('leaveStatus');
        $code = $leave->leaveStatus?->codeEnum();
        if (! $code) {
            return;
        }

        $instance->loadMissing(['template.steps', 'currentStep']);
        $targetStep = $instance->template->steps->firstWhere('domain_status', $code->value);

        if (! $targetStep) {
            $stepKey = match ($code) {
                LeaveStatusCode::PendingAccountsConfirmation => 'accounts',
                LeaveStatusCode::PendingHrApproval => 'hr',
                LeaveStatusCode::PendingSupervisorApproval => 'supervisor',
                default => null,
            };
            if ($stepKey) {
                $targetStep = $instance->template->steps->firstWhere('key', $stepKey);
            }
        }

        if (! $targetStep || (string) $instance->current_step_id === (string) $targetStep->id) {
            return;
        }

        $instance->current_step_id = $targetStep->id;
        $instance->save();
    }

    private function assertActorForCurrentStep(EmployeeLeave $leave, ?User $actor, string $action): void
    {
        $instance = $this->pipelineEngine->activeInstance(PipelineEngine::SUBJECT_LEAVE, (string) $leave->id);
        $assigneeType = $instance
            ? $this->pipelineEngine->currentAssigneeType($instance)
            : null;

        if ($this->authorization->isLeaveAdmin($actor)) {
            return;
        }

        if ($assigneeType === PipelineAssigneeType::Supervisor) {
            if (! $this->authorization->canActAsSupervisor($actor, $leave)) {
                abort(403, 'Only the employee supervisor or an administrator can perform this action.');
            }

            return;
        }

        if ($assigneeType === PipelineAssigneeType::DepartmentHead) {
            if (! $this->authorization->canActAsDepartmentHead($actor, $leave)) {
                abort(403, 'Only a department head or an administrator can perform this action.');
            }

            return;
        }

        if ($assigneeType === PipelineAssigneeType::Admin) {
            if (! $this->authorization->canActAsHr($actor, $leave)) {
                abort(403, 'Only HR or an administrator can perform this action.');
            }

            return;
        }

        if ($assigneeType === PipelineAssigneeType::Role) {
            $role = $instance?->currentStep?->assignee_role;
            if (! $this->authorization->canActAsRole($actor, $leave, $role)) {
                abort(403, 'Only an accounts user or an administrator can perform this action.');
            }

            return;
        }

        if (! $this->authorization->canManageLeave($actor, $leave)) {
            abort(403, 'You are not allowed to perform this leave action.');
        }
    }

    private function resolveLeaveTargetStatus(EmployeeLeave $leave, ?string $domainStatus, bool $completed): LeaveStatusCode
    {
        if (! $completed) {
            return LeaveStatusCode::fromStored($domainStatus)
                ?? LeaveStatusCode::PendingHrApproval;
        }

        if ($domainStatus && LeaveStatusCode::fromStored($domainStatus) === LeaveStatusCode::Scheduled) {
            return Carbon::parse($leave->endDate)->endOfDay()->lt(Carbon::today())
                ? LeaveStatusCode::Taken
                : LeaveStatusCode::Scheduled;
        }

        return LeaveStatusCode::fromStored($domainStatus)
            ?? LeaveStatusCode::Scheduled;
    }

    private function legacyApprove(
        EmployeeLeave $leave,
        ?User $actor,
        ?string $note = null,
        ?LeavePaymentTreatment $paymentTreatment = null,
    ): EmployeeLeave {
        $current = $leave->leaveStatus?->codeEnum();

        if ($current === LeaveStatusCode::PendingSupervisorApproval) {
            $this->assertCanTransition($leave, [LeaveStatusCode::PendingSupervisorApproval]);
            if (! $this->authorization->canActAsSupervisor($actor, $leave)) {
                abort(403, 'Only the employee supervisor or an administrator can perform this action.');
            }

            return $this->applyTransition($leave, LeaveStatusCode::PendingHrApproval, $actor, $note);
        }

        if ($current === LeaveStatusCode::PendingHrApproval) {
            $this->assertCanTransition($leave, [LeaveStatusCode::PendingHrApproval]);
            if (! $this->authorization->canActAsHr($actor, $leave)) {
                abort(403, 'Only HR or an administrator can perform this action.');
            }

            if (! $this->requiresPaymentTreatmentChoice($leave)) {
                $target = Carbon::parse($leave->endDate)->endOfDay()->lt(Carbon::today())
                    ? LeaveStatusCode::Taken
                    : LeaveStatusCode::Scheduled;

                return $this->applyTransition(
                    $leave,
                    $target,
                    $actor,
                    $note,
                    $this->defaultPaymentTreatment($leave),
                );
            }

            return $this->applyTransition($leave, LeaveStatusCode::PendingAccountsConfirmation, $actor, $note);
        }

        if ($current === LeaveStatusCode::PendingAccountsConfirmation) {
            $this->assertCanTransition($leave, [LeaveStatusCode::PendingAccountsConfirmation]);
            if (! $this->authorization->canActAsAccounts($actor, $leave)) {
                abort(403, 'Only an accounts user or an administrator can perform this action.');
            }

            $treatment = $this->requiresPaymentTreatmentChoice($leave)
                ? ($paymentTreatment ?? $this->requirePaymentTreatment(null))
                : ($paymentTreatment ?? $this->defaultPaymentTreatment($leave));
            $target = Carbon::parse($leave->endDate)->endOfDay()->lt(Carbon::today())
                ? LeaveStatusCode::Taken
                : LeaveStatusCode::Scheduled;

            return $this->applyTransition($leave, $target, $actor, $note, $treatment);
        }

        abort(422, 'Leave cannot be approved from its current status.');
    }

    private function legacyReject(EmployeeLeave $leave, ?User $actor, ?string $note = null): EmployeeLeave
    {
        $this->assertCanTransition($leave, array_merge(
            LeaveStatusCode::pendingActionStatuses(),
            [LeaveStatusCode::Scheduled],
        ));

        return $this->applyTransition($leave, LeaveStatusCode::Rejected, $actor, $note);
    }

    /**
     * @param array<int, LeaveStatusCode> $allowedFrom
     */
    private function assertCanTransition(EmployeeLeave $leave, array $allowedFrom): void
    {
        $leave->loadMissing('leaveStatus');
        $current = $leave->leaveStatus?->codeEnum();

        if (! $current || ! in_array($current, $allowedFrom, true)) {
            abort(422, 'Leave cannot be updated from its current status.');
        }
    }

    private function applyTransition(
        EmployeeLeave $leave,
        LeaveStatusCode $target,
        ?User $actor,
        ?string $note = null,
        ?LeavePaymentTreatment $paymentTreatment = null,
    ): EmployeeLeave {
        return DB::transaction(function () use ($leave, $target, $actor, $note, $paymentTreatment) {
            $leave->leaveStatusId = LeaveStatus::idForCode($target);

            if ($note !== null) {
                $trimmed = trim($note);
                $leave->statusNote = $trimmed !== '' ? $trimmed : null;
            }

            if ($paymentTreatment && in_array($target, [LeaveStatusCode::Scheduled, LeaveStatusCode::Taken], true)) {
                $leave->paymentTreatment = $paymentTreatment->value;
                $leave->paymentConfirmedAt = now();
                $leave->paymentConfirmedByUserId = $actor?->id;
                $leave->multiplier = $paymentTreatment->defaultMultiplier();

                // Advance-paid leave must never be linked to a later payroll run as "paid with payroll".
                if ($paymentTreatment === LeavePaymentTreatment::AlreadyPaid) {
                    $leave->paidInPayrollRunId = null;
                }
            }

            if (in_array($target, [LeaveStatusCode::Scheduled, LeaveStatusCode::Taken, LeaveStatusCode::Rejected, LeaveStatusCode::Cancelled], true)) {
                if (in_array($target, [LeaveStatusCode::Scheduled, LeaveStatusCode::Taken, LeaveStatusCode::Rejected], true)) {
                    $leave->approvalDate = Carbon::today();

                    $approver = $actor
                        ? Employee::query()->where('user_id', $actor->id)->first()
                        : null;

                    if ($approver) {
                        $leave->approverId = $approver->id;
                    }
                }
            }

            if ($target === LeaveStatusCode::Cancelled) {
                $leave->approvalDate = null;
                $leave->approverId = null;
            }

            $leave->save();

            return $leave->fresh(['employee', 'leaveType', 'leaveStatus', 'approver', 'paymentConfirmedBy']);
        });
    }
}
