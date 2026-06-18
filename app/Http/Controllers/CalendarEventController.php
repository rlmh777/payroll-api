<?php

namespace App\Http\Controllers;

use App\Models\Calendar;
use App\Models\CalendarGroup;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeReporting;
use App\Models\EmploymentDetail;
use App\Models\ScheduleEmployeeTimesheet;
use App\Models\Timesheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarEventController extends Controller
{
    private function resolveEmployeeIds(Request $request)
    {
        $employeeIds = collect(explode(',', (string) $request->string('employee_ids')))
            ->map(fn ($id) => trim($id))
            ->filter();

        if ($request->filled('employee_id')) {
            $employeeIds = $employeeIds->push($request->string('employee_id'))->filter()->unique();
        }

        if ($request->filled('department_id')) {
            $departmentEmployees = EmploymentDetail::query()
                ->where('departmentId', $request->string('department_id'))
                ->where('isActive', true)
                ->pluck('employeeId');

            $employeeIds = $employeeIds->isNotEmpty()
                ? $employeeIds->intersect($departmentEmployees)
                : $departmentEmployees;
        }

        if ($request->filled('lead_id')) {
            $leadEmployees = EmployeeReporting::query()
                ->where('supervisor_id', $request->string('lead_id'))
                ->where('is_active', true)
                ->pluck('subordinate_id');

            if ($leadEmployees->isEmpty()) {
                $leadEmployees = Employee::query()
                    ->where('leadId', $request->string('lead_id'))
                    ->pluck('id');
            }

            $employeeIds = $employeeIds->isNotEmpty()
                ? $employeeIds->intersect($leadEmployees)
                : $leadEmployees;
        }

        if ($request->filled('supervisor_id')) {
            $supervisorEmployees = EmployeeReporting::query()
                ->where('supervisor_id', $request->string('supervisor_id'))
                ->where('is_active', true)
                ->pluck('subordinate_id');

            if ($supervisorEmployees->isEmpty()) {
                $supervisorEmployees = Employee::query()
                    ->where('supervisorId', $request->string('supervisor_id'))
                    ->pluck('id');
            }

            $employeeIds = $employeeIds->isNotEmpty()
                ? $employeeIds->intersect($supervisorEmployees)
                : $supervisorEmployees;
        }

        return $employeeIds;
    }

    /**
     * Display merged calendar events for a date range.
     */
    public function index(Request $request): JsonResponse
    {
        $start = $request->string('start');
        $end = $request->string('end');

        if (!$start || !$end) {
            return response()->json(['error' => 'start and end are required'], 422);
        }

        $groupIds = collect(explode(',', (string) $request->string('group_ids')))
            ->map(fn ($id) => trim($id))
            ->filter();

        $employeeIds = $this->resolveEmployeeIds($request);

        $calendarQuery = Calendar::query()->whereBetween('date', [$start, $end]);

        if ($groupIds->isNotEmpty()) {
            $calendarQuery->whereIn('calendar_group_id', $groupIds);
        }

        $calendarEntries = $calendarQuery->get();

        $groupsById = CalendarGroup::query()->get()->keyBy('id');
        $timesheetGroup = CalendarGroup::query()->where('key', 'timesheets')->first();
        $scheduleGroup = CalendarGroup::query()->where('key', 'schedules')->first();
        $leaveGroup = CalendarGroup::query()->where('key', 'leaves')->first();
        $birthdayGroup = CalendarGroup::query()->where('key', 'birthdays')->first();

        $events = $calendarEntries->map(function (Calendar $entry) use ($groupsById) {
            $group = $entry->calendar_group_id ? $groupsById->get($entry->calendar_group_id) : null;

            return [
                'id' => $entry->id,
                'date' => $entry->date->format('Y-m-d'),
                'description' => $entry->description,
                'type' => $entry->type,
                'rate' => $entry->rate,
                'calendar_group_id' => $group?->id,
                'calendar_group_name' => $group?->name,
                'calendar_group_color' => $group?->color,
                'source' => 'calendar',
            ];
        })->values();

        if ($timesheetGroup && ($groupIds->isEmpty() || $groupIds->contains($timesheetGroup->id))) {
            $timesheetQuery = Timesheet::query()
                ->with('employee')
                ->whereBetween('date', [$start, $end]);

            if ($employeeIds->isNotEmpty()) {
                $timesheetQuery->whereIn('employeeId', $employeeIds);
            }

            $timesheets = $timesheetQuery->get();

            foreach ($timesheets as $timesheet) {
                $employeeName = trim(sprintf('%s %s', $timesheet->employee?->firstName, $timesheet->employee?->lastName));
                $description = trim(sprintf('Timesheet - %s', $employeeName));
                $groupId = $timesheetGroup->id;
                $group = $timesheetGroup;

                $events->push([
                    'id' => sprintf('timesheet-%s-%s', $timesheet->id, $timesheet->date->format('Y-m-d')),
                    'date' => $timesheet->date->format('Y-m-d'),
                    'description' => $description,
                    'type' => 'timesheet',
                    'rate' => 1,
                    'calendar_group_id' => $groupId,
                    'calendar_group_name' => $group?->name,
                    'calendar_group_color' => $group?->color,
                    'source' => 'timesheet',
                    'employee_id' => $timesheet->employeeId,
                    'hours_worked' => $timesheet->hoursWorked,
                    'approval_status' => $timesheet->approvalStatus,
                ]);
            }
        }

        if ($scheduleGroup && ($groupIds->isEmpty() || $groupIds->contains($scheduleGroup->id))) {
            $scheduleQuery = ScheduleEmployeeTimesheet::query()
                ->with('employee')
                ->whereBetween('date', [$start, $end]);

            if ($employeeIds->isNotEmpty()) {
                $scheduleQuery->whereIn('employeeId', $employeeIds);
            }

            $schedules = $scheduleQuery->get();

            foreach ($schedules as $schedule) {
                $employeeName = trim(sprintf('%s %s', $schedule->employee?->firstName, $schedule->employee?->lastName));
                $description = trim(sprintf('Schedule %s - %s', $schedule->startTime, $schedule->endTime));
                $groupId = $schedule->calendar_group_id ?? $scheduleGroup->id;
                $group = $groupsById->get($groupId) ?? $scheduleGroup;

                $events->push([
                    'id' => sprintf('schedule-%s-%s', $schedule->id, $schedule->date->format('Y-m-d')),
                    'date' => $schedule->date->format('Y-m-d'),
                    'description' => trim(sprintf('%s - %s', $description, $employeeName)),
                    'type' => 'schedule',
                    'rate' => 1,
                    'calendar_group_id' => $groupId,
                    'calendar_group_name' => $group?->name,
                    'calendar_group_color' => $group?->color,
                    'source' => 'schedule',
                    'employee_id' => $schedule->employeeId,
                    'start_time' => $schedule->startTime,
                    'end_time' => $schedule->endTime,
                ]);
            }
        }

        if ($leaveGroup && ($groupIds->isEmpty() || $groupIds->contains($leaveGroup->id))) {
            $leaves = EmployeeLeave::query()
                ->with(['employee', 'leaveType'])
                ->whereDate('startDate', '<=', $end)
                ->whereDate('endDate', '>=', $start)
                ->when($employeeIds->isNotEmpty(), function ($query) use ($employeeIds) {
                    $query->whereIn('employeeId', $employeeIds);
                })
                ->get();

            foreach ($leaves as $leave) {
                $rangeStart = Carbon::parse($leave->startDate)->max(Carbon::parse($start));
                $rangeEnd = Carbon::parse($leave->endDate)->min(Carbon::parse($end));
                $leaveTypeName = $leave->leaveType?->name ?? 'Leave';
                $employeeName = trim(sprintf('%s %s', $leave->employee?->firstName, $leave->employee?->lastName));
                $typeHint = strtolower($leaveTypeName);
                $eventType = str_contains($typeHint, 'sick') ? 'sick' : 'vacation';
                $description = trim(sprintf('%s - %s', $leaveTypeName, $employeeName));

                for ($date = $rangeStart->copy(); $date->lte($rangeEnd); $date->addDay()) {
                    $events->push([
                        'id' => sprintf('leave-%s-%s', $leave->id, $date->format('Y-m-d')),
                        'date' => $date->format('Y-m-d'),
                        'description' => $description,
                        'type' => $eventType,
                        'rate' => $leave->multiplier ?? 1,
                        'calendar_group_id' => $leaveGroup->id,
                        'calendar_group_name' => $leaveGroup->name,
                        'calendar_group_color' => $leaveGroup->color,
                        'source' => 'leave',
                        'employee_id' => $leave->employeeId,
                        'leave_type_id' => $leave->leaveTypeId,
                        'start_date' => $leave->startDate,
                        'end_date' => $leave->endDate,
                    ]);
                }
            }
        }

        if ($birthdayGroup && ($groupIds->isEmpty() || $groupIds->contains($birthdayGroup->id))) {
            $rangeStart = Carbon::parse($start);
            $rangeEnd = Carbon::parse($end);
            $employeeQuery = Employee::query()->select(['id', 'firstName', 'lastName', 'birthdate']);

            if ($employeeIds->isNotEmpty()) {
                $employeeQuery->whereIn('id', $employeeIds);
            }

            $employees = $employeeQuery->get();

            $yearStart = (int) $rangeStart->format('Y');
            $yearEnd = (int) $rangeEnd->format('Y');

            foreach ($employees as $employee) {
                if (!$employee->birthdate) {
                    continue;
                }

                $birth = Carbon::parse($employee->birthdate);

                for ($year = $yearStart; $year <= $yearEnd; $year++) {
                    if ($birth->month === 2 && $birth->day === 29 && !Carbon::create($year)->isLeapYear()) {
                        $birthday = Carbon::create($year, 2, 28);
                    } else {
                        $birthday = Carbon::create($year, $birth->month, $birth->day);
                    }

                    if ($birthday->betweenIncluded($rangeStart, $rangeEnd)) {
                        $fullName = trim(sprintf('%s %s', $employee->firstName, $employee->lastName));
                        $events->push([
                            'id' => sprintf('birthday-%s-%s', $employee->id, $birthday->format('Y-m-d')),
                            'date' => $birthday->format('Y-m-d'),
                            'description' => sprintf('Birthday - %s', $fullName),
                            'type' => 'other',
                            'rate' => 1,
                            'calendar_group_id' => $birthdayGroup->id,
                            'calendar_group_name' => $birthdayGroup->name,
                            'calendar_group_color' => $birthdayGroup->color,
                            'source' => 'birthday',
                            'employee_id' => $employee->id,
                        ]);
                    }
                }
            }
        }

        return response()->json($events->values());
    }

    /**
     * Display pending approvals (timesheets) for a date range.
     */
    public function approvals(Request $request): JsonResponse
    {
        $start = $request->string('start');
        $end = $request->string('end');

        if (!$start || !$end) {
            return response()->json(['error' => 'start and end are required'], 422);
        }

        $employeeIds = $this->resolveEmployeeIds($request);

        $timesheetQuery = Timesheet::query()
            ->with('employee')
            ->whereBetween('date', [$start, $end])
            ->whereRaw('LOWER("approvalStatus") = ?', ['pending']);

        if ($employeeIds->isNotEmpty()) {
            $timesheetQuery->whereIn('employeeId', $employeeIds);
        }

        $timesheets = $timesheetQuery->get();
        $scheduleQuery = ScheduleEmployeeTimesheet::query()
            ->with('employee')
            ->whereBetween('date', [$start, $end])
            ->whereRaw('LOWER("approvalStatus") = ?', ['pending']);

        if ($employeeIds->isNotEmpty()) {
            $scheduleQuery->whereIn('employeeId', $employeeIds);
        }

        $schedules = $scheduleQuery->get();

        $leaveQuery = EmployeeLeave::query()
            ->with(['employee', 'leaveType'])
            ->whereDate('startDate', '<=', $end)
            ->whereDate('endDate', '>=', $start)
            ->whereRaw('LOWER("approvalStatus") = ?', ['pending']);

        if ($employeeIds->isNotEmpty()) {
            $leaveQuery->whereIn('employeeId', $employeeIds);
        }

        $leaves = $leaveQuery->get();

        $approvals = collect();

        foreach ($timesheets as $timesheet) {
            $employeeName = trim(sprintf('%s %s', $timesheet->employee?->firstName, $timesheet->employee?->lastName));

            $approvals->push([
                'id' => $timesheet->id,
                'type' => 'timesheet',
                'date' => $timesheet->date->format('Y-m-d'),
                'description' => trim(sprintf('Timesheet - %s', $employeeName)),
                'hours_worked' => $timesheet->hoursWorked,
                'approval_status' => $timesheet->approvalStatus,
                'employee_id' => $timesheet->employeeId,
            ]);
        }

        foreach ($schedules as $schedule) {
            $employeeName = trim(sprintf('%s %s', $schedule->employee?->firstName, $schedule->employee?->lastName));
            $description = trim(sprintf('Schedule %s - %s', $schedule->startTime, $schedule->endTime));

            $approvals->push([
                'id' => $schedule->id,
                'type' => 'schedule',
                'date' => $schedule->date->format('Y-m-d'),
                'description' => trim(sprintf('%s - %s', $description, $employeeName)),
                'hours_worked' => null,
                'approval_status' => $schedule->approvalStatus,
                'employee_id' => $schedule->employeeId,
            ]);
        }

        foreach ($leaves as $leave) {
            $employeeName = trim(sprintf('%s %s', $leave->employee?->firstName, $leave->employee?->lastName));
            $leaveTypeName = $leave->leaveType?->name ?? 'Leave';

            $approvals->push([
                'id' => $leave->id,
                'type' => 'leave',
                'date' => $leave->startDate,
                'description' => trim(sprintf('%s - %s', $leaveTypeName, $employeeName)),
                'hours_worked' => null,
                'approval_status' => $leave->approvalStatus,
                'employee_id' => $leave->employeeId,
            ]);
        }

        return response()->json($approvals->values());
    }

    /**
     * Update approval status for timesheets, schedules, or leaves.
     */
    public function updateApproval(Request $request, string $type, string $id): JsonResponse
    {
        $status = strtolower((string) $request->input('status', ''));
        $allowed = ['approved', 'rejected'];

        if (!$status || !in_array($status, $allowed, true)) {
            return response()->json(['error' => 'status must be approved or rejected'], 422);
        }

        $model = match ($type) {
            'timesheet' => Timesheet::class,
            'schedule' => ScheduleEmployeeTimesheet::class,
            'leave' => EmployeeLeave::class,
            default => null,
        };

        if (!$model) {
            return response()->json(['error' => 'Invalid approval type'], 404);
        }

        $record = $model::query()->find($id);

        if (!$record) {
            return response()->json(['error' => 'Approval item not found'], 404);
        }

        if (strtolower((string) $record->approvalStatus) !== 'pending') {
            return response()->json(['error' => 'Approval item already processed'], 409);
        }

        if ($record instanceof Timesheet) {
            $record->approvalStatus = strtoupper($status);
        } else {
            $record->approvalStatus = $status;
        }

        if ($record instanceof Timesheet) {
            $record->approvedAt = Carbon::now();

            $approver = $request->user()
                ? Employee::query()->where('user_id', $request->user()->id)->first()
                : null;

            if ($approver) {
                $record->approvedBy = $approver->id;
            }
        } elseif ($record instanceof ScheduleEmployeeTimesheet || $record instanceof EmployeeLeave) {
            $record->approvalDate = Carbon::now();

            $approver = $request->user()
                ? Employee::query()->where('user_id', $request->user()->id)->first()
                : null;

            if ($approver) {
                $record->approverId = $approver->id;
            }
        }

        $record->save();

        return response()->json([
            'id' => $record->id,
            'type' => $type,
            'status' => $record->approvalStatus,
        ]);
    }
}
