<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TimesheetLeaveConflictNotification extends Notification
{
    use Queueable;

    /**
     * @param array{
     *   timesheetId:string,
     *   employeeId:string,
     *   employeeName:?string,
     *   workDate:string,
     *   message:string
     * } $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'timesheet_leave_conflict',
            'timesheet_id' => $this->payload['timesheetId'],
            'employee_id' => $this->payload['employeeId'],
            'employee_name' => $this->payload['employeeName'],
            'work_date' => $this->payload['workDate'],
            'message' => $this->payload['message'],
            'url' => '/payroll/timesheets',
        ];
    }
}
