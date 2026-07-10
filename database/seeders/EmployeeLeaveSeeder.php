<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\LeaveStatus;
use App\Models\LeaveType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EmployeeLeaveSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::query()->get();
        $leaveType = LeaveType::query()->first();

        if ($employees->isEmpty() || !$leaveType) {
            return;
        }

        $pendingStatusId = LeaveStatus::idForCode('PENDING_SUPERVISOR_APPROVAL');
        $scheduledStatusId = LeaveStatus::idForCode('SCHEDULED');

        foreach ($employees as $index => $employee) {
            $offset = 3 + ($index % 10);
            $startDate = now()->addDays($offset)->format('Y-m-d');
            $endDate = now()->addDays($offset + 1)->format('Y-m-d');

            EmployeeLeave::updateOrCreate(
                [
                    'employeeId' => $employee->id,
                    'leaveTypeId' => $leaveType->id,
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'fromTime' => '08:00:00',
                    'toTime' => '17:00:00',
                    'duration' => 'Full Day',
                    'totalDays' => 2,
                    'notes' => 'Seeded leave',
                    'multiplier' => 1,
                    'leaveStatusId' => $index % 2 === 0 ? $pendingStatusId : $scheduledStatusId,
                ],
            );
        }
    }
}
