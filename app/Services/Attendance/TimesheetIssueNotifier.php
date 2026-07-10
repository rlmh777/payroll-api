<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use App\Notifications\TimesheetLeaveConflictNotification;
use Illuminate\Support\Facades\Notification;

class TimesheetIssueNotifier
{
    public function __construct(
        private readonly TimesheetIssueRecipientResolver $recipientResolver,
    ) {
    }

    public function notifyLeaveConflict(Timesheet $timesheet, Employee $employee, string $message): void
    {
        $recipients = $this->recipientResolver->recipientsForEmployee(
            $employee,
            $timesheet->departmentId ? (int) $timesheet->departmentId : null,
        );

        if ($recipients->isEmpty()) {
            return;
        }

        $employeeName = trim(sprintf('%s %s', $employee->firstName, $employee->lastName)) ?: null;
        $payload = [
            'timesheetId' => (string) $timesheet->id,
            'employeeId' => (string) $employee->id,
            'employeeName' => $employeeName,
            'workDate' => $timesheet->date->format('Y-m-d'),
            'message' => $message,
        ];

        $recipientsToNotify = $recipients->filter(
            fn (User $user) => !$this->hasUnreadLeaveConflictNotification($user, (string) $timesheet->id),
        );

        if ($recipientsToNotify->isEmpty()) {
            return;
        }

        Notification::send(
            $recipientsToNotify,
            new TimesheetLeaveConflictNotification($payload),
        );
    }

    private function hasUnreadLeaveConflictNotification(User $user, string $timesheetId): bool
    {
        return $user->notifications()
            ->where('type', TimesheetLeaveConflictNotification::class)
            ->whereNull('read_at')
            ->where('data->timesheet_id', $timesheetId)
            ->exists();
    }
}
