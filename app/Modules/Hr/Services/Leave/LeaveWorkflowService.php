<?php

namespace App\Modules\Hr\Services\Leave;

use App\Enums\LeaveStatusCode;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\LeaveStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveWorkflowService
{
    public function __construct(
        private readonly LeaveSupervisorAuthorizationService $authorization,
    ) {
    }

    public function defaultStatusId(): int
    {
        return LeaveStatus::idForCode(LeaveStatusCode::PendingSupervisorApproval);
    }

    public function initialStatusIdForEmployee(?Employee $employee): int
    {
        if ($employee?->supervisorId) {
            return LeaveStatus::idForCode(LeaveStatusCode::PendingSupervisorApproval);
        }

        return LeaveStatus::idForCode(LeaveStatusCode::PendingApproval);
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

    public function approve(EmployeeLeave $leave, ?User $actor, ?string $note = null): EmployeeLeave
    {
        $leave->loadMissing(['leaveStatus', 'employee']);
        $current = $leave->leaveStatus?->codeEnum();

        // Supervisor step: non-admins only forward to department head.
        if (
            $current === LeaveStatusCode::PendingSupervisorApproval
            && $this->authorization->employeeHasSupervisor($leave)
            && !$this->authorization->isLeaveAdmin($actor)
        ) {
            $this->assertCanTransition($leave, [LeaveStatusCode::PendingSupervisorApproval]);
            if (!$this->authorization->canActAsSupervisor($actor, $leave)) {
                abort(403, 'Only the employee supervisor or an administrator can perform this action.');
            }

            return $this->applyTransition($leave, LeaveStatusCode::PendingApproval, $actor, $note);
        }

        // Final approval (no supervisor, already at dept-head step, or leave admin).
        $this->assertCanTransition($leave, [
            LeaveStatusCode::PendingSupervisorApproval,
            LeaveStatusCode::PendingApproval,
        ]);

        if ($current === LeaveStatusCode::PendingApproval) {
            if (!$this->authorization->canActAsDepartmentHead($actor, $leave)) {
                abort(403, 'Only a department head or an administrator can perform final approval.');
            }
        } elseif (!$this->authorization->canManageLeave($actor, $leave)) {
            abort(403, 'Only a supervisor, department head, or administrator can perform this action.');
        }

        $target = Carbon::parse($leave->endDate)->endOfDay()->lt(Carbon::today())
            ? LeaveStatusCode::Taken
            : LeaveStatusCode::Scheduled;

        return $this->applyTransition($leave, $target, $actor, $note);
    }

    public function reject(EmployeeLeave $leave, ?User $actor, ?string $note = null): EmployeeLeave
    {
        $leave->loadMissing(['leaveStatus', 'employee']);
        $this->assertCanTransition($leave, [
            LeaveStatusCode::PendingSupervisorApproval,
            LeaveStatusCode::PendingApproval,
            LeaveStatusCode::Scheduled,
        ]);

        $current = $leave->leaveStatus?->codeEnum();
        if ($current === LeaveStatusCode::PendingSupervisorApproval) {
            if (!$this->authorization->canActAsSupervisor($actor, $leave)) {
                abort(403, 'Only the employee supervisor or an administrator can reject at this step.');
            }
        } elseif ($current === LeaveStatusCode::PendingApproval) {
            if (!$this->authorization->canActAsDepartmentHead($actor, $leave)) {
                abort(403, 'Only a department head or an administrator can reject at this step.');
            }
        } elseif (!$this->authorization->canManageLeave($actor, $leave)) {
            abort(403, 'Only a supervisor, department head, or administrator can perform this action.');
        }

        return $this->applyTransition($leave, LeaveStatusCode::Rejected, $actor, $note);
    }

    public function cancel(EmployeeLeave $leave, ?User $actor, ?string $note = null): EmployeeLeave
    {
        $this->assertCanTransition($leave, [
            LeaveStatusCode::PendingSupervisorApproval,
            LeaveStatusCode::PendingApproval,
            LeaveStatusCode::Scheduled,
        ]);

        return $this->applyTransition($leave, LeaveStatusCode::Cancelled, $actor, $note);
    }

    public function submitForFinalApproval(EmployeeLeave $leave, ?User $actor): EmployeeLeave
    {
        $this->assertCanTransition($leave, [LeaveStatusCode::PendingSupervisorApproval]);
        if (!$this->authorization->canActAsSupervisor($actor, $leave->loadMissing('employee'))) {
            abort(403, 'Only the employee supervisor or an administrator can submit for final approval.');
        }

        return $this->applyTransition($leave, LeaveStatusCode::PendingApproval, $actor);
    }

    /**
     * @param array<int, LeaveStatusCode> $allowedFrom
     */
    private function assertCanTransition(EmployeeLeave $leave, array $allowedFrom): void
    {
        $leave->loadMissing('leaveStatus');
        $current = $leave->leaveStatus?->codeEnum();

        if (!$current || !in_array($current, $allowedFrom, true)) {
            abort(422, 'Leave cannot be updated from its current status.');
        }
    }

    private function applyTransition(
        EmployeeLeave $leave,
        LeaveStatusCode $target,
        ?User $actor,
        ?string $note = null,
    ): EmployeeLeave {
        return DB::transaction(function () use ($leave, $target, $actor, $note) {
            $leave->leaveStatusId = LeaveStatus::idForCode($target);

            if ($note !== null) {
                $trimmed = trim($note);
                $leave->statusNote = $trimmed !== '' ? $trimmed : null;
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

            return $leave->fresh(['employee', 'leaveType', 'leaveStatus', 'approver']);
        });
    }
}
