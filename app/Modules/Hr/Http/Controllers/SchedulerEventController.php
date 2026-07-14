<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Enums\LeaveStatusCode;
use App\Models\CalendarGroup;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeReporting;
use App\Models\EmploymentDetail;
use App\Models\PublicHoliday;
use App\Models\ScheduledWork;
use App\Models\ScheduleEmployeeTimesheet;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\TimesheetCompensationRecalculationService;
use App\Modules\Hr\Services\Employment\EmploymentContractAssignmentService;
use App\Modules\Hr\Services\Leave\LeaveEntitlementService;
use App\Modules\Hr\Services\Leave\LeaveWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SchedulerEventController extends Controller
{
    public function __construct(
        private readonly LeaveWorkflowService $leaveWorkflowService,
        private readonly LeaveEntitlementService $leaveEntitlementService,
        private readonly TimesheetCompensationRecalculationService $timesheetRecalculationService,
        private readonly EmploymentContractAssignmentService $contractAssignmentService,
    ) {
    }

    private function formatTime(?string $time): ?string
    {
        if (!$time) {
            return null;
        }

        return Carbon::parse($time)->format('H:i');
    }

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
     * Merged scheduler feed: planned work, holidays, leaves, and birthdays.
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
        $schedulerOnly = $request->boolean('scheduler');
        if ($employeeIds->isNotEmpty()) {
            $this->timesheetRecalculationService->recalculate(
                $employeeIds->values()->all(),
                (string) $start,
                (string) $end,
            );
        }

        $leaveGroup = CalendarGroup::query()->where('key', 'leaves')->first();
        $birthdayGroup = CalendarGroup::query()->where('key', 'birthdays')->first();
        $holidayGroup = CalendarGroup::query()->where('key', 'holidays')->first();

        $events = collect();

        $scheduledWorkQuery = ScheduledWork::query()
            ->with([
                'employee',
                'department',
                'worksite',
                'employmentDetail.department',
                'employmentDetail.worksite',
                'employmentDetail.contractType',
                'employmentDetail.defaultPayPeriodGroup',
            ])
            ->whereDate('startDate', '<=', $end)
            ->whereDate('endDate', '>=', $start);

        if ($employeeIds->isNotEmpty()) {
            $scheduledWorkQuery->where(function ($query) use ($employeeIds) {
                $query->whereNull('employeeId')
                    ->orWhereIn('employeeId', $employeeIds);
            });
        }

        foreach ($scheduledWorkQuery->get() as $entry) {
            $entryStartDate = $entry->startDate->format('Y-m-d');
            $entryEndDate = $entry->endDate->format('Y-m-d');
            $rangeStart = Carbon::parse($entryStartDate)->max(Carbon::parse($start));
            $rangeEnd = Carbon::parse($entryEndDate)->min(Carbon::parse($end));
            $employeeName = trim(sprintf('%s %s', $entry->employee?->firstName, $entry->employee?->lastName));

            for ($date = $rangeStart->copy(); $date->lte($rangeEnd); $date->addDay()) {
                $workDate = $date->format('Y-m-d');
                $compensation = $this->contractAssignmentService->compensationForContractDate(
                    $entry->employmentDetailId ? (string) $entry->employmentDetailId : null,
                    $workDate,
                );
                $events->push([
                    'id' => sprintf('scheduled-work-%s-%s', $entry->id, $workDate),
                    'scheduled_work_id' => $entry->id,
                    'date' => $workDate,
                    'description' => $entry->description,
                    'type' => 'work',
                    'rate' => $entry->rate,
                    'source' => 'scheduled_work',
                    'employee_id' => $entry->employeeId,
                    'employee_name' => $employeeName ?: null,
                    'employment_detail_id' => $entry->employmentDetailId,
                    'employment_contract_label' => $entry->employmentDetail
                        ? $this->employmentContractLabel($entry->employmentDetail)
                        : null,
                    'employee_compensation_id' => $compensation?->id,
                    'compensation_label' => $compensation ? $this->compensationLabel($compensation) : null,
                    'department_id' => $entry->departmentId,
                    'department_name' => $entry->department?->name,
                    'worksite_id' => $entry->worksiteId,
                    'worksite_name' => $entry->worksite?->name,
                    'include_lunch_hour' => (bool) $entry->includeLunchHour,
                    'lunch_hour_hours' => (float) ($entry->lunchHourHours ?? 1),
                    'start_date' => $entryStartDate,
                    'end_date' => $entryEndDate,
                    'start_time' => $this->formatTime($entry->startTime),
                    'end_time' => $this->formatTime($entry->endTime),
                ]);
            }
        }

        if (!$schedulerOnly && $holidayGroup && ($groupIds->isEmpty() || $groupIds->contains($holidayGroup->id))) {
            $holidays = PublicHoliday::query()
                ->where('isActive', true)
                ->whereDate('startDate', '<=', $end)
                ->whereDate('endDate', '>=', $start)
                ->get();

            foreach ($holidays as $holiday) {
                $entryStartDate = $holiday->startDate->format('Y-m-d');
                $entryEndDate = $holiday->endDate->format('Y-m-d');
                $rangeStart = Carbon::parse($entryStartDate)->max(Carbon::parse($start));
                $rangeEnd = Carbon::parse($entryEndDate)->min(Carbon::parse($end));

                for ($date = $rangeStart->copy(); $date->lte($rangeEnd); $date->addDay()) {
                    $events->push([
                        'id' => sprintf('holiday-%s-%s', $holiday->id, $date->format('Y-m-d')),
                        'public_holiday_id' => $holiday->id,
                        'date' => $date->format('Y-m-d'),
                        'description' => $holiday->name,
                        'type' => 'holiday',
                        'pay_multiplier' => $holiday->payMultiplier,
                        'rate' => $holiday->payMultiplier,
                        'calendar_group_id' => $holidayGroup->id,
                        'calendar_group_name' => $holidayGroup->name,
                        'calendar_group_color' => $holidayGroup->color,
                        'source' => 'holiday',
                        'start_date' => $entryStartDate,
                        'end_date' => $entryEndDate,
                        'start_time' => null,
                        'end_time' => null,
                    ]);
                }
            }
        }

        if (!$schedulerOnly && $leaveGroup && ($groupIds->isEmpty() || $groupIds->contains($leaveGroup->id))) {
            $leaves = EmployeeLeave::query()
                ->with(['employee', 'leaveType', 'department', 'leaveStatus'])
                ->whereDate('startDate', '<=', $end)
                ->whereDate('endDate', '>=', $start)
                ->whereHas('leaveStatus', fn ($query) => $query->whereNotIn('code', [
                    LeaveStatusCode::Cancelled->value,
                    LeaveStatusCode::Rejected->value,
                ]))
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
                        'leave_id' => $leave->id,
                        'employee_id' => $leave->employeeId,
                        'employee_name' => $employeeName,
                        'leave_type_id' => $leave->leaveTypeId,
                        'leave_type_name' => $leaveTypeName,
                        'start_date' => $leave->startDate,
                        'end_date' => $leave->endDate,
                        'start_time' => $this->formatTime($leave->fromTime),
                        'end_time' => $this->formatTime($leave->toTime),
                        'department_id' => $leave->departmentId,
                        'department_name' => $leave->department?->name,
                        'approval_status' => $leave->leaveStatus?->code ?? $leave->statusCode,
                        'notes' => $leave->notes,
                    ]);
                }
            }
        }

        if (!$schedulerOnly && $birthdayGroup && ($groupIds->isEmpty() || $groupIds->contains($birthdayGroup->id))) {
            $rangeStart = Carbon::parse($start);
            $rangeEnd = Carbon::parse($end);
            $employeeQuery = Employee::query()
                ->with('person:id,firstName,lastName,birthdate')
                ->select(['id', 'person_id']);

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
                            'type' => 'birthday',
                            'rate' => 1,
                            'calendar_group_id' => $birthdayGroup->id,
                            'calendar_group_name' => $birthdayGroup->name,
                            'calendar_group_color' => $birthdayGroup->color,
                            'source' => 'birthday',
                            'employee_id' => $employee->id,
                            'employee_name' => $fullName,
                            'start_date' => $birthday->format('Y-m-d'),
                            'end_date' => $birthday->format('Y-m-d'),
                            'start_time' => null,
                            'end_time' => null,
                        ]);
                    }
                }
            }
        }

        return response()->json($events->values());
    }

    private function employmentContractLabel(EmploymentDetail $employmentDetail): string
    {
        $title = $employmentDetail->jobTitle
            ?: $employmentDetail->contractType?->name
            ?: 'Contract';
        $department = $employmentDetail->department?->name ?: 'No department';
        $payPeriodGroup = $employmentDetail->defaultPayPeriodGroup?->name ?: 'No pay period group';

        return "{$title} - {$department} - {$payPeriodGroup}";
    }

    private function compensationLabel($compensation): string
    {
        $method = strtoupper((string) $compensation->compensationMethod);
        $rate = (float) $compensation->yearlyRate > 0
            ? number_format((float) $compensation->yearlyRate, 2)
            : number_format((float) $compensation->hourlyRate, 2);

        return "{$method} @ {$rate}";
    }

    public function approvals(Request $request): JsonResponse
    {
        $start = $request->string('start');
        $end = $request->string('end');

        if (!$start || !$end) {
            return response()->json(['error' => 'start and end are required'], 422);
        }

        $employeeIds = $this->resolveEmployeeIds($request);

        $leaveQuery = EmployeeLeave::query()
            ->with(['employee', 'leaveType', 'leaveStatus'])
            ->whereDate('startDate', '<=', $end)
            ->whereDate('endDate', '>=', $start)
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::pendingSupervisorStatuses(),
            )));

        if ($employeeIds->isNotEmpty()) {
            $leaveQuery->whereIn('employeeId', $employeeIds);
        }

        $leaves = $leaveQuery->get();
        $approvals = collect();

        foreach ($leaves as $leave) {
            $employeeName = trim(sprintf('%s %s', $leave->employee?->firstName, $leave->employee?->lastName));
            $leaveTypeName = $leave->leaveType?->name ?? 'Leave';

            $approvals->push([
                'id' => $leave->id,
                'type' => 'leave',
                'date' => $leave->startDate,
                'description' => trim(sprintf('%s - %s', $leaveTypeName, $employeeName)),
                'hours_worked' => null,
                'approval_status' => $leave->leaveStatus?->code ?? $leave->statusCode,
                'employee_id' => $leave->employeeId,
            ]);
        }

        return response()->json($approvals->values());
    }

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

        if ($record instanceof EmployeeLeave) {
            $record->loadMissing('leaveStatus');
            $currentCode = $record->leaveStatus?->codeEnum();

            if (!$currentCode || !in_array($currentCode, LeaveStatusCode::pendingSupervisorStatuses(), true)) {
                return response()->json(['error' => 'Approval item already processed'], 409);
            }

            if ($status === 'approved') {
                $balanceError = $this->leaveEntitlementService->assertSufficientBalance(
                    $record->employeeId,
                    (int) $record->leaveTypeId,
                    (float) $record->totalDays,
                    Carbon::parse($record->startDate),
                );

                if ($balanceError) {
                    return response()->json(['errors' => ['balance' => [$balanceError]]], 422);
                }

                $record = $this->leaveWorkflowService->approve($record, $request->user());
            } else {
                $record = $this->leaveWorkflowService->reject($record, $request->user());
            }

            return response()->json([
                'id' => $record->id,
                'type' => $type,
                'status' => $record->leaveStatus?->code ?? $record->statusCode,
            ]);
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
        } elseif ($record instanceof ScheduleEmployeeTimesheet) {
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
