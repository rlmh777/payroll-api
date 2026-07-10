<?php

namespace App\Services\Leave;

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

    public function approve(EmployeeLeave $leave, ?User $actor): EmployeeLeave
    {
        $this->assertCanTransition($leave, [LeaveStatusCode::PendingSupervisorApproval, LeaveStatusCode::PendingApproval]);
        $this->assertSupervisor($leave, $actor);

        $target = Carbon::parse($leave->endDate)->endOfDay()->lt(Carbon::today())
            ? LeaveStatusCode::Taken
            : LeaveStatusCode::Scheduled;

        return $this->applyTransition($leave, $target, $actor);
    }

    public function reject(EmployeeLeave $leave, ?User $actor, ?string $note = null): EmployeeLeave
    {
        $this->assertCanTransition($leave, [
            LeaveStatusCode::PendingSupervisorApproval,
            LeaveStatusCode::PendingApproval,
            LeaveStatusCode::Scheduled,
        ]);
        $this->assertSupervisor($leave, $actor);

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
        $this->assertSupervisor($leave, $actor);

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

    private function assertSupervisor(EmployeeLeave $leave, ?User $actor): void
    {
        if (!$this->authorization->canManageLeave($actor, $leave->loadMissing('employee'))) {
            abort(403, 'Only a supervisor or administrator can perform this action.');
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
            $leave->statusNote = $note ? trim($note) : null;

            if (in_array($target, [LeaveStatusCode::Scheduled, LeaveStatusCode::Taken, LeaveStatusCode::Rejected], true)) {
                $leave->approvalDate = Carbon::today();

                $approver = $actor
                    ? Employee::query()->where('user_id', $actor->id)->first()
                    : null;

                if ($approver) {
                    $leave->approverId = $approver->id;
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
