<?php

namespace App\Services\Attendance;

use App\Enums\LeaveStatusCode;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\LeaveStatus;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimesheetLeaveConflictResolver
{
    public const RESOLUTION_PREFIX = 'Authorized work on leave:';

    public function __construct(
        private readonly TimesheetLeaveConflictService $leaveConflictService,
        private readonly TimesheetRoundOffService $roundOffService,
    ) {
    }

    public function hasUnresolvedLeaveConflict(Timesheet $timesheet): bool
    {
        return is_string($timesheet->remarks)
            && str_contains($timesheet->remarks, TimesheetLeaveConflictService::CONFLICT_MESSAGE);
    }

    public function authorizeWork(Timesheet $timesheet, Employee $resolver, string $note): Timesheet
    {
        if (!$this->hasUnresolvedLeaveConflict($timesheet)) {
            throw ValidationException::withMessages([
                'leave' => ['This timesheet does not have an unresolved leave conflict.'],
            ]);
        }

        return DB::transaction(function () use ($timesheet, $resolver, $note) {
            $workDate = $timesheet->date->format('Y-m-d');
            $roundOffIn = $timesheet->roundOffClockInTime
                ? Carbon::parse($timesheet->roundOffClockInTime)
                : null;
            $roundOffOut = $timesheet->roundOffClockOutTime
                ? Carbon::parse($timesheet->roundOffClockOutTime)
                : null;

            $approvedLeaves = EmployeeLeave::query()
                ->where('employeeId', $timesheet->employeeId)
                ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                    fn (LeaveStatusCode $code) => $code->value,
                    LeaveStatusCode::activeAbsenceStatuses(),
                )))
                ->whereDate('startDate', '<=', $workDate)
                ->whereDate('endDate', '>=', $workDate)
                ->get()
                ->all();

            $conflicts = $this->leaveConflictService->conflictingLeaves(
                $approvedLeaves,
                $workDate,
                $roundOffIn?->format('Y-m-d H:i:s'),
                $roundOffOut?->format('Y-m-d H:i:s'),
            );

            if ($conflicts->isEmpty()) {
                throw ValidationException::withMessages([
                    'leave' => ['No approved leave conflict found for this timesheet.'],
                ]);
            }

            foreach ($conflicts as $leave) {
                $this->cancelLeaveForAuthorizedWork($leave, $workDate, $note, $resolver);
            }

            $timesheet->remarks = $this->buildResolvedRemarks($timesheet, $note);
            $timesheet->save();

            if ($roundOffIn && $roundOffOut && $roundOffOut->gt($roundOffIn)) {
                $this->roundOffService->recalculate($timesheet, $roundOffIn, $roundOffOut);
            }

            return $timesheet->fresh();
        });
    }

    private function cancelLeaveForAuthorizedWork(
        EmployeeLeave $leave,
        string $workDate,
        string $note,
        Employee $resolver,
    ): void {
        $cancellationNote = sprintf(
            'Leave cancelled: authorized work on %s. %s',
            $workDate,
            trim($note),
        );

        $existingNotes = trim((string) ($leave->notes ?? ''));
        $leave->notes = $existingNotes === ''
            ? $cancellationNote
            : $existingNotes."\n".$cancellationNote;
        $leave->leaveStatusId = LeaveStatus::idForCode(LeaveStatusCode::Cancelled);
        $leave->statusNote = $cancellationNote;
        $leave->approvalDate = Carbon::today();
        $leave->approverId = $resolver->id;
        $leave->save();
    }

    private function buildResolvedRemarks(Timesheet $timesheet, string $note): string
    {
        $remarks = trim((string) $timesheet->remarks);
        $remarks = str_replace(TimesheetLeaveConflictService::CONFLICT_MESSAGE, '', $remarks);
        $remarks = trim(preg_replace('/\s+/', ' ', $remarks) ?? '');
        $resolution = self::RESOLUTION_PREFIX.' '.trim($note);

        return $remarks === '' ? $resolution : $remarks.' '.$resolution;
    }
}
