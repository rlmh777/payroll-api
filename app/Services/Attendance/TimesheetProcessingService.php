<?php

namespace App\Services\Attendance;

use App\Enums\OvernightShiftMode;
use App\Enums\CompensationMethod;
use App\Enums\LeaveStatusCode;
use App\Models\AttendanceSetting;
use App\Models\ClockingLog;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeLeave;
use App\Models\EmploymentDetail;
use App\Models\Timesheet;
use App\Services\Employment\EmployeeCompensationResolver;
use App\Models\TimesheetTemplate;
use App\Models\TimesheetTemplateDepartment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetProcessingService
{
    public function __construct(
        private readonly TimesheetHoursCalculator $hoursCalculator,
        private readonly TimesheetOvertimeAllocator $overtimeAllocator,
        private readonly TimesheetLunchBreakResolver $lunchBreakResolver,
        private readonly PublicHolidayPayResolver $holidayPayResolver,
        private readonly TimesheetOverlapValidator $timesheetOverlapValidator,
        private readonly TimesheetLeaveConflictService $leaveConflictService,
        private readonly TimesheetIssueNotifier $issueNotifier,
        private readonly EmployeeCompensationResolver $compensationResolver,
        private readonly CompensationTimesheetHoursService $compensationHoursService,
        private readonly TimesheetOvernightShiftBuilder $overnightShiftBuilder,
        private readonly TimesheetOvernightShiftModeResolver $overnightShiftModeResolver,
        private readonly TimesheetScheduledHoursResolver $scheduledHoursResolver,
    ) {
    }

    /**
     * @param array{startDate?:string, endDate?:string, biometricUserId?:string, payPeriodGroupId?:string, overtimeThresholdHours?:float|int|string} $filters
     * @return array{
     *   totalLogs:int,
     *   groupedDays:int,
     *   processedTimesheets:int,
     *   clockingTimesheets:int,
     *   scheduledTimesheets:int,
     *   regularHours:float,
     *   overtimeHours:float,
     *   unresolvedBiometricUsers:array<int, string>,
     *   warnings:array<int, array{employeeId:string, employeeName:?string, date:string, message:string}>
     * }
     */
    public function process(array $filters = []): array
    {
        $query = ClockingLog::query()
            ->orderBy('biometricUserId')
            ->orderBy('punchDateTime');

        if (!empty($filters['startDate'])) {
            $query->whereDate(
                'punchDateTime',
                '>=',
                Carbon::parse($filters['startDate'])->subDay()->toDateString(),
            );
        }

        if (!empty($filters['endDate'])) {
            $query->whereDate('punchDateTime', '<=', $filters['endDate']);
        }

        if (!empty($filters['biometricUserId'])) {
            $query->where('biometricUserId', $filters['biometricUserId']);
        }

        $logs = $query->get(['biometricUserId', 'deviceId', 'punchDateTime', 'punchType']);
        [$rangeStart, $rangeEnd] = $this->resolveProcessingRange($filters, $logs);

        if (!$rangeStart || !$rangeEnd) {
            return [
                'totalLogs' => 0,
                'groupedDays' => 0,
                'processedTimesheets' => 0,
                'clockingTimesheets' => 0,
                'scheduledTimesheets' => 0,
                'regularHours' => 0.0,
                'overtimeHours' => 0.0,
                'unresolvedBiometricUsers' => [],
                'warnings' => [],
            ];
        }

        $logGroupsByBiometricUser = $this->groupLogsByBiometricUser($logs);
        $groupedLogDays = array_sum(array_map('count', $logGroupsByBiometricUser));
        $biometricUsers = array_keys($logGroupsByBiometricUser);

        if (!empty($filters['biometricUserId'])) {
            $biometricUsers[] = (string) $filters['biometricUserId'];
        }

        $employeeMap = $this->resolveEmployeeMap(array_values(array_unique($biometricUsers)));
        $unresolved = array_values(array_diff(array_keys($logGroupsByBiometricUser), array_keys($employeeMap)));

        $logGroupsByEmployee = [];
        $employeesById = [];

        foreach ($logGroupsByBiometricUser as $biometricUserId => $dateGroups) {
            $employee = $employeeMap[$biometricUserId] ?? null;
            if (!$employee) {
                continue;
            }

            $employeesById[(string) $employee->id] = $employee;

            foreach ($dateGroups as $workDate => $punches) {
                $logGroupsByEmployee[(string) $employee->id][$workDate] = array_merge(
                    $logGroupsByEmployee[(string) $employee->id][$workDate] ?? [],
                    $punches,
                );
            }
        }

        $employmentDetails = $this->loadEmploymentDetails(
            $rangeStart,
            $rangeEnd,
            $filters['biometricUserId'] ?? null,
            $employeeMap,
            $filters,
        );
        $employeeCompensations = $this->loadEmployeeCompensations($rangeStart, $rangeEnd, $employeeMap, $employmentDetails);

        foreach ($employmentDetails as $employeeId => $details) {
            $employee = $details->first()?->employee;
            if ($employee) {
                $employeesById[(string) $employeeId] = $employee;
            }
        }

        $scheduleAssignments = $this->loadScheduleAssignments($employmentDetails, $rangeEnd);
        $holidayMultipliers = $this->holidayPayResolver->multipliersForRange($rangeStart, $rangeEnd);
        $this->holidayPayResolver->primeCache($holidayMultipliers);
        $unpaidLeaveDates = $this->loadUnpaidLeaveDates($rangeStart, $rangeEnd);
        $approvedLeaves = $this->loadApprovedLeaves($rangeStart, $rangeEnd);
        $clockRoundOffMinutes = AttendanceSetting::current()->clockRoundOffMinutes;

        $overnightPayloadsByEmployeeDate = $this->overnightShiftBuilder->buildSlotPayloadsByEmployeeDate(
            $logGroupsByEmployee,
            function (string $employeeId, Carbon $date) use ($employmentDetails): ?int {
                $details = $employmentDetails->get($employeeId, collect());

                return $this->employmentDetailForDate($details, $date)?->departmentId;
            },
            $clockRoundOffMinutes,
            function (string $employeeId, Carbon $date) use ($employmentDetails): OvernightShiftMode {
                $details = $employmentDetails->get($employeeId, collect());
                $departmentId = $this->employmentDetailForDate($details, $date)?->departmentId;

                return $this->overnightShiftModeResolver->forDepartmentId(
                    $departmentId ? (int) $departmentId : null,
                );
            },
        );

        $rowKeys = $this->buildRowKeys(
            $rangeStart,
            $rangeEnd,
            $logGroupsByEmployee,
            $employmentDetails,
            $scheduleAssignments,
            $overnightPayloadsByEmployeeDate,
            $employeeCompensations,
        );

        $warnings = [];
        $processedTimesheets = 0;
        $clockingTimesheets = 0;
        $scheduledTimesheets = 0;
        $regularHours = 0.0;
        $overtimeHours = 0.0;

        $weeksToReallocate = [];

        foreach ($rowKeys as $rowKey) {
            $employeeId = $rowKey['employeeId'];
            $workDate = $rowKey['date'];
            $date = Carbon::parse($workDate);
            $details = $employmentDetails->get($employeeId, collect());
            $employmentDetail = $this->employmentDetailForDate($details, $date);
            $compensation = $this->compensationResolver->compensationForDate(
                $employeeCompensations->get($employeeId, collect()),
                $date,
                $employmentDetail?->id ? (string) $employmentDetail->id : null,
            );
            $payrollSnapshot = $this->compensationResolver->payrollSnapshot($compensation);
            $compensationMethod = CompensationMethod::fromStored($payrollSnapshot['payType'] ?? null);
            $requiresClocking = (bool) ($payrollSnapshot['requiresClocking'] ?? $compensationMethod->defaultRequiresClocking());
            $employee = $employeesById[$employeeId] ?? $employmentDetail?->employee;
            $scheduledHours = $this->scheduledHoursResolver->forEmployeeDate(
                $employeeId,
                $date,
                $employmentDetail?->departmentId,
                $employmentDetail?->id ? (string) $employmentDetail->id : null,
            );
            $punches = collect($logGroupsByEmployee[$employeeId][$workDate] ?? []);
            $prebuiltSlotPayloads = $overnightPayloadsByEmployeeDate[$employeeId][$workDate] ?? null;

            if (
                $requiresClocking
                && $punches->isEmpty()
                && ($prebuiltSlotPayloads === null || $prebuiltSlotPayloads === [])
            ) {
                continue;
            }

            $savedTimesheets = $this->persistTimesheetSlots(
                $employeeId,
                $workDate,
                $punches,
                $scheduledHours,
                $holidayMultipliers[$workDate] ?? null,
                isset($unpaidLeaveDates[$employeeId][$workDate]),
                $employmentDetail,
                $clockRoundOffMinutes,
                $approvedLeaves[$employeeId][$workDate] ?? [],
                $employee,
                $payrollSnapshot,
                $prebuiltSlotPayloads,
            );

            $processedTimesheets += $savedTimesheets->count();
            $hasWorkedHours = $prebuiltSlotPayloads !== null || $punches->isNotEmpty();
            $hasWorkedHours ? $clockingTimesheets += $savedTimesheets->count() : $scheduledTimesheets += $savedTimesheets->count();

            foreach ($savedTimesheets as $savedTimesheet) {
                if (!empty($savedTimesheet->remarks)) {
                    $warnings[] = [
                        'employeeId' => $employeeId,
                        'employeeName' => $this->employeeName($employeesById[$employeeId] ?? null),
                        'date' => $workDate,
                        'message' => (string) $savedTimesheet->remarks,
                    ];
                }
            }

            $weekKey = $employeeId.'|'.$date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $weeksToReallocate[$weekKey] = [$employeeId, $date->copy()];
        }

        $regularHours = 0.0;
        $overtimeHours = 0.0;
        $processedWeeks = [];

        foreach ($weeksToReallocate as [$employeeId, $date]) {
            $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
            $weekKey = $employeeId.'|'.$weekStart->toDateString();

            if (isset($processedWeeks[$weekKey])) {
                continue;
            }

            $processedWeeks[$weekKey] = true;
            $this->overtimeAllocator->redistributeEmployeeWeek((string) $employeeId, $date);

            $weekEnd = $date->copy()->endOfWeek(Carbon::SUNDAY);
            $weekTotals = Timesheet::query()
                ->where('employeeId', $employeeId)
                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->get(['regularHours', 'overtimeHours']);

            $regularHours += (float) $weekTotals->sum('regularHours');
            $overtimeHours += (float) $weekTotals->sum('overtimeHours');
        }

        $this->holidayPayResolver->clearCache();

        return [
            'totalLogs' => $logs->count(),
            'groupedDays' => $groupedLogDays,
            'processedTimesheets' => $processedTimesheets,
            'clockingTimesheets' => $clockingTimesheets,
            'scheduledTimesheets' => $scheduledTimesheets,
            'regularHours' => round($regularHours, 2),
            'overtimeHours' => round($overtimeHours, 2),
            'unresolvedBiometricUsers' => $unresolved,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param Collection<int, ClockingLog> $logs
     * @return array{0:?Carbon, 1:?Carbon}
     */
    private function resolveProcessingRange(array $filters, Collection $logs): array
    {
        $firstPunch = $logs
            ->map(fn (ClockingLog $log) => Carbon::parse($log->punchDateTime))
            ->sortBy(fn (Carbon $punch) => $punch->getTimestamp())
            ->first();
        $lastPunch = $logs
            ->map(fn (ClockingLog $log) => Carbon::parse($log->punchDateTime))
            ->sortByDesc(fn (Carbon $punch) => $punch->getTimestamp())
            ->first();
        $start = !empty($filters['startDate'])
            ? Carbon::parse($filters['startDate'])->startOfDay()
            : ($firstPunch ? $firstPunch->copy()->startOfDay() : null);
        $end = !empty($filters['endDate'])
            ? Carbon::parse($filters['endDate'])->startOfDay()
            : ($lastPunch ? $lastPunch->copy()->startOfDay() : null);

        if ($start && !$end) {
            $end = $start->copy();
        }

        if ($end && !$start) {
            $start = $end->copy();
        }

        return [$start, $end];
    }

    /**
     * @param Collection<int, ClockingLog> $logs
     * @return array<string, array<string, array<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}>>>
     */
    private function groupLogsByBiometricUser(Collection $logs): array
    {
        $grouped = [];

        foreach ($logs as $log) {
            $biometricUserId = trim((string) $log->biometricUserId);
            $punch = Carbon::parse($log->punchDateTime);
            $workDate = $punch->format('Y-m-d');

            $grouped[$biometricUserId][$workDate][] = [
                'punchDateTime' => $punch,
                'deviceId' => $this->nullableString($log->deviceId),
                'punchType' => $this->normalizePunchType($log->punchType),
            ];
        }

        return $grouped;
    }

    /**
     * @param array<int, string> $biometricUsers
     * @return array<string, Employee>
     */
    private function resolveEmployeeMap(array $biometricUsers): array
    {
        if (empty($biometricUsers)) {
            return [];
        }

        $employees = Employee::query()
            ->with('timesheetTemplate')
            ->whereIn('code', $biometricUsers)
            ->orWhereIn('internalId1', $biometricUsers)
            ->orWhereIn('internalId2', $biometricUsers)
            ->get();

        $map = [];

        foreach ($employees as $employee) {
            foreach (['code', 'internalId1', 'internalId2'] as $field) {
                $value = trim((string) ($employee->{$field} ?? ''));
                if ($value !== '' && !isset($map[$value])) {
                    $map[$value] = $employee;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, Employee> $employeeMap
     * @param array{payPeriodGroupId?:string} $filters
     * @return Collection<string, Collection<int, EmploymentDetail>>
     */
    private function loadEmploymentDetails(
        Carbon $rangeStart,
        Carbon $rangeEnd,
        ?string $biometricUserId,
        array $employeeMap,
        array $filters = [],
    ): Collection {
        $query = EmploymentDetail::query()
            ->with(['employee.timesheetTemplate', 'department', 'worksite'])
            ->whereDate('startDate', '<=', $rangeEnd->toDateString())
            ->where(function ($query) use ($rangeStart) {
                $query
                    ->whereNull('endDate')
                    ->orWhereDate('endDate', '>=', $rangeStart->toDateString());
            })
            ->orderByDesc('startDate');

        if (!empty($filters['payPeriodGroupId'])) {
            $query
                ->where('defaultPayPeriodGroupId', (string) $filters['payPeriodGroupId'])
                ->where('isActive', true);
        }

        if ($biometricUserId !== null && $biometricUserId !== '') {
            $employee = $employeeMap[$biometricUserId] ?? null;

            if ($employee) {
                $query->where('employeeId', $employee->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->get()->groupBy(fn (EmploymentDetail $detail) => (string) $detail->employeeId);
    }

    /**
     * @param array<string, Employee> $employeeMap
     * @return Collection<string, Collection<int, EmployeeCompensation>>
     */
    private function loadEmployeeCompensations(
        Carbon $rangeStart,
        Carbon $rangeEnd,
        array $employeeMap,
        Collection $employmentDetails,
    ): Collection {
        $employeeIds = array_values(array_unique(array_merge(
            array_map(
                fn (Employee $employee) => (string) $employee->id,
                array_values($employeeMap),
            ),
            $employmentDetails->keys()->map(fn ($employeeId) => (string) $employeeId)->all(),
        )));

        if ($employeeIds === []) {
            return collect();
        }

        return EmployeeCompensation::query()
            ->whereIn('employeeId', $employeeIds)
            ->whereDate('effectiveDate', '<=', $rangeEnd->toDateString())
            ->where(function ($query) use ($rangeStart) {
                $query->whereNull('endDate')
                    ->orWhereDate('endDate', '>=', $rangeStart->toDateString());
            })
            ->get()
            ->groupBy(fn (EmployeeCompensation $record) => (string) $record->employeeId);
    }

    /**
     * @param Collection<string, Collection<int, EmploymentDetail>> $employmentDetails
     * @return Collection<string, Collection<int, TimesheetTemplateDepartment>>
     */
    private function loadScheduleAssignments(Collection $employmentDetails, Carbon $rangeEnd): Collection
    {
        $departmentIds = $employmentDetails
            ->flatten(1)
            ->pluck('departmentId')
            ->filter()
            ->unique()
            ->values();

        if ($departmentIds->isEmpty()) {
            return collect();
        }

        return TimesheetTemplateDepartment::query()
            ->with('timesheetTemplate')
            ->whereIn('department_id', $departmentIds)
            ->whereDate('effective_date', '<=', $rangeEnd->toDateString())
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy(fn (TimesheetTemplateDepartment $assignment) => (string) $assignment->department_id);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function loadUnpaidLeaveDates(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $leaves = EmployeeLeave::query()
            ->with('leaveType')
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::activeAbsenceStatuses(),
            )))
            ->whereDate('startDate', '<=', $rangeEnd->toDateString())
            ->whereDate('endDate', '>=', $rangeStart->toDateString())
            ->where(function ($query) {
                $query
                    ->whereHas('leaveType', fn ($leaveTypeQuery) => $leaveTypeQuery->where('isPaid', false))
                    ->orWhere('multiplier', '<=', 0);
            })
            ->get();

        $dates = [];

        foreach ($leaves as $leave) {
            $date = Carbon::parse($leave->startDate)->max($rangeStart)->startOfDay();
            $endDate = Carbon::parse($leave->endDate)->min($rangeEnd)->startOfDay();

            while ($date->lte($endDate)) {
                $dates[(string) $leave->employeeId][$date->toDateString()] = true;
                $date->addDay();
            }
        }

        return $dates;
    }

    /**
     * @return array<string, array<string, array<int, EmployeeLeave>>>
     */
    private function loadApprovedLeaves(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $leaves = EmployeeLeave::query()
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::activeAbsenceStatuses(),
            )))
            ->whereDate('startDate', '<=', $rangeEnd->toDateString())
            ->whereDate('endDate', '>=', $rangeStart->toDateString())
            ->get();

        $grouped = [];

        foreach ($leaves as $leave) {
            $date = Carbon::parse($leave->startDate)->max($rangeStart)->startOfDay();
            $endDate = Carbon::parse($leave->endDate)->min($rangeEnd)->startOfDay();

            while ($date->lte($endDate)) {
                $grouped[(string) $leave->employeeId][$date->toDateString()][] = $leave;
                $date->addDay();
            }
        }

        return $grouped;
    }

    /**
     * @param array<string, array<string, array<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}>>> $logGroupsByEmployee
     * @param Collection<string, Collection<int, EmploymentDetail>> $employmentDetails
     * @param Collection<string, Collection<int, TimesheetTemplateDepartment>> $scheduleAssignments
     * @return array<int, array{employeeId:string, date:string}>
     */
    /**
     * @param Collection<string, Collection<int, EmploymentDetail>> $employmentDetails
     * @param Collection<string, Collection<int, TimesheetTemplateDepartment>> $scheduleAssignments
     * @param array<string, array<string, array<int, array<string, mixed>>>> $overnightPayloadsByEmployeeDate
     * @param Collection<string, Collection<int, EmployeeCompensation>> $employeeCompensations
     * @return list<array{employeeId:string, date:string}>
     */
    private function buildRowKeys(
        Carbon $rangeStart,
        Carbon $rangeEnd,
        array $logGroupsByEmployee,
        Collection $employmentDetails,
        Collection $scheduleAssignments,
        array $overnightPayloadsByEmployeeDate = [],
        ?Collection $employeeCompensations = null,
    ): array {
        $employeeCompensations ??= collect();
        $keys = [];

        foreach ($logGroupsByEmployee as $employeeId => $dateGroups) {
            foreach (array_keys($dateGroups) as $workDate) {
                $date = Carbon::parse($workDate);
                if ($date->lt($rangeStart) || $date->gt($rangeEnd)) {
                    continue;
                }

                $keys["{$employeeId}|{$workDate}"] = [
                    'employeeId' => (string) $employeeId,
                    'date' => $workDate,
                ];
            }
        }

        foreach ($overnightPayloadsByEmployeeDate as $employeeId => $dateGroups) {
            foreach (array_keys($dateGroups) as $workDate) {
                $date = Carbon::parse($workDate);
                if ($date->lt($rangeStart) || $date->gt($rangeEnd)) {
                    continue;
                }

                $keys["{$employeeId}|{$workDate}"] = [
                    'employeeId' => (string) $employeeId,
                    'date' => $workDate,
                ];
            }
        }

        foreach ($employmentDetails as $employeeId => $details) {
            $date = $rangeStart->copy();

            while ($date->lte($rangeEnd)) {
                $employmentDetail = $this->employmentDetailForDate($details, $date);

                if ($employmentDetail) {
                    $compensation = $this->compensationResolver->compensationForDate(
                        $employeeCompensations->get((string) $employeeId, collect()),
                        $date,
                        $employmentDetail->id ? (string) $employmentDetail->id : null,
                    );

                    if (
                        $compensation
                        && !$this->compensationResolver->requiresClocking($compensation)
                        && $this->scheduledHoursResolver->forEmployeeDate(
                            (string) $employeeId,
                            $date,
                            $employmentDetail->departmentId,
                            $employmentDetail->id ? (string) $employmentDetail->id : null,
                        ) > 0
                    ) {
                        $workDate = $date->toDateString();
                        $keys["{$employeeId}|{$workDate}"] = [
                            'employeeId' => (string) $employeeId,
                            'date' => $workDate,
                        ];
                    }
                }

                $date->addDay();
            }
        }

        ksort($keys);

        return array_values($keys);
    }

    /**
     * @param Collection<int, EmploymentDetail> $details
     */
    private function employmentDetailForDate(Collection $details, Carbon $date): ?EmploymentDetail
    {
        $matching = $details->filter(function (EmploymentDetail $detail) use ($date) {
            $startDate = Carbon::parse($detail->startDate)->startOfDay();
            $endDate = $detail->endDate ? Carbon::parse($detail->endDate)->startOfDay() : null;

            return $startDate->lte($date) && (!$endDate || $endDate->gte($date));
        });

        return $matching
            ->sort(function (EmploymentDetail $left, EmploymentDetail $right) {
                if ($left->isActive !== $right->isActive) {
                    return $right->isActive <=> $left->isActive;
                }

                return Carbon::parse($right->startDate)->timestamp <=> Carbon::parse($left->startDate)->timestamp;
            })
            ->first();
    }

    /**
     * @param Collection<string, Collection<int, TimesheetTemplateDepartment>> $scheduleAssignments
     */
    private function timesheetTemplateForDate(
        ?Employee $employee,
        mixed $departmentId,
        Carbon $date,
        Collection $scheduleAssignments
    ): ?TimesheetTemplate {
        if ($employee?->timesheetTemplateId) {
            if ($employee->relationLoaded('timesheetTemplate') && $employee->timesheetTemplate) {
                return $employee->timesheetTemplate;
            }

            return TimesheetTemplate::query()->find($employee->timesheetTemplateId);
        }

        if ($departmentId === null) {
            return null;
        }

        $assignments = $scheduleAssignments->get((string) $departmentId, collect());
        $assignment = $assignments->first(
            fn (TimesheetTemplateDepartment $item) => Carbon::parse($item->effective_date)->startOfDay()->lte($date)
        );

        return $assignment?->timesheetTemplate;
    }

    private function isScheduledWorkDay(?TimesheetTemplate $timesheetTemplate, Carbon $date, ?int $departmentId = null): bool
    {
        if (!$timesheetTemplate) {
            return $date->isWeekday();
        }

        if (!$timesheetTemplate->is_active) {
            return false;
        }

        return $timesheetTemplate->daySlotsFor($date->format('D'), $departmentId) !== [];
    }

    private function scheduledHoursForDate(?TimesheetTemplate $timesheetTemplate, Carbon $date, ?int $departmentId = null): float
    {
        if (!$timesheetTemplate) {
            if (!$date->isWeekday()) {
                return 0.0;
            }

            return (float) config('attendance.standard_daily_hours', 8);
        }

        $slots = $timesheetTemplate->daySlotsFor($date->format('D'), $departmentId);
        if ($slots === []) {
            return 0.0;
        }

        $totalMinutes = 0;

        foreach ($slots as $schedule) {
            $start = Carbon::parse($date->toDateString().' '.$schedule['start_time']);
            $end = Carbon::parse($date->toDateString().' '.$schedule['end_time']);

            if ($end->lte($start)) {
                $end->addDay();
            }

            $breakMinutes = LunchBreakHelper::breakMinutesFromSchedule($schedule);

            $totalMinutes += max(0, $start->diffInMinutes($end, false) - $breakMinutes);
        }

        return round($totalMinutes / 60, 2);
    }

    /**
     * @param Collection<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}> $punches
     * @return Collection<int, Timesheet>
     */
    private function persistTimesheetSlots(
        string $employeeId,
        string $workDate,
        Collection $punches,
        float $scheduledHours,
        ?float $holidayPayMultiplier,
        bool $isUnpaidLeave,
        ?EmploymentDetail $employmentDetail,
        int $clockRoundOffMinutes = 30,
        array $approvedLeavesOnDate = [],
        ?Employee $employee = null,
        array $payrollSnapshot = [],
        ?array $prebuiltSlotPayloads = null,
    ): Collection {
        $compensationMethod = CompensationMethod::fromStored($payrollSnapshot['payType'] ?? null);
        $requiresClocking = (bool) ($payrollSnapshot['requiresClocking'] ?? $compensationMethod->defaultRequiresClocking());
        $autoApprove = !$requiresClocking;

        if (
            $punches->isEmpty()
            && ($prebuiltSlotPayloads === null || $prebuiltSlotPayloads === [])
            && $this->leaveConflictService->hasApprovedLeaveOnDate($approvedLeavesOnDate)
        ) {
            Timesheet::query()
                ->where('employeeId', $employeeId)
                ->whereDate('date', $workDate)
                ->whereRaw('UPPER("approvalStatus") != ?', ['APPROVED'])
                ->delete();

            return collect();
        }

        $slotPayloads = [];
        $hasClockedSlots = false;

        if ($prebuiltSlotPayloads !== null && $prebuiltSlotPayloads !== []) {
            $slotPayloads = $prebuiltSlotPayloads;
            $hasClockedSlots = true;
        } elseif ($punches->isNotEmpty()) {
            $slotCalc = $this->hoursCalculator->calculateSlots($punches, $clockRoundOffMinutes);
            $workDateCarbon = Carbon::parse($workDate);
            $departmentId = $employmentDetail?->departmentId ? (int) $employmentDetail->departmentId : null;

            $dayLunchApplied = false;

            foreach ($slotCalc['slots'] as $index => $slot) {
                $lunchSettings = $this->lunchBreakResolver->lunchSettingsFor(
                    $employeeId,
                    $workDateCarbon,
                    $departmentId,
                    $index,
                );
                $lunchMinutes = $dayLunchApplied
                    ? 0
                    : LunchBreakHelper::breakMinutes(
                        $lunchSettings['include_lunch_hour'],
                        $lunchSettings['lunch_hour_hours'],
                    );

                if ($lunchMinutes > 0) {
                    $dayLunchApplied = true;
                }

                $slotPayloads[] = [
                    'slotIndex' => $index,
                    'clockInTime' => $slot['clockInTime'],
                    'clockInDeviceId' => $slot['clockInDeviceId'],
                    'clockOutTime' => $slot['clockOutTime'],
                    'clockOutDeviceId' => $slot['clockOutDeviceId'],
                    'roundOffClockInTime' => $slot['roundOffClockInTime'],
                    'roundOffClockOutTime' => $slot['roundOffClockOutTime'],
                    'clockedHoursWorked' => $slot['clockedHoursWorked'],
                    'includeLunchHour' => $lunchSettings['include_lunch_hour'],
                    'lunchHourHours' => $lunchSettings['lunch_hour_hours'],
                    'hoursWorked' => LunchBreakHelper::applyBreakToHours((float) $slot['hoursWorked'], $lunchMinutes),
                    'remarks' => !empty($slotCalc['issues']) ? implode(' ', $slotCalc['issues']) : null,
                ];
            }

            $hasClockedSlots = true;
        } elseif (
            !$this->leaveConflictService->hasApprovedLeaveOnDate($approvedLeavesOnDate)
        ) {
            $hasExistingClockedTimesheet = Timesheet::query()
                ->where('employeeId', $employeeId)
                ->whereDate('date', $workDate)
                ->where(function ($query) {
                    $query->whereNotNull('clockInTime')
                        ->orWhereNotNull('roundOffClockInTime');
                })
                ->exists();

            if ($hasExistingClockedTimesheet) {
                return collect();
            }

            $departmentId = $employmentDetail?->departmentId ? (int) $employmentDetail->departmentId : null;
            $lunchSettings = $this->lunchBreakResolver->lunchSettingsFor(
                $employeeId,
                Carbon::parse($workDate),
                $departmentId,
                0,
            );
            $timesheetData = $this->buildTimesheetData(
                $employeeId,
                $workDate,
                $punches,
                $scheduledHours,
                $scheduledHours,
                $holidayPayMultiplier,
                $isUnpaidLeave,
                $employmentDetail,
                $clockRoundOffMinutes,
                $payrollSnapshot,
            );
            $slotPayloads[] = array_merge($timesheetData, [
                'slotIndex' => 0,
                'includeLunchHour' => $lunchSettings['include_lunch_hour'],
                'lunchHourHours' => $lunchSettings['lunch_hour_hours'],
            ]);
        }

        if ($hasClockedSlots && $slotPayloads !== []) {
            $overlapIssues = $this->timesheetOverlapValidator->overlappingSlotIssues(
                collect($slotPayloads)->map(fn (array $slot) => [
                    'roundOffClockInTime' => $slot['roundOffClockInTime'],
                    'roundOffClockOutTime' => $slot['roundOffClockOutTime'],
                ])->all(),
            );

            if ($overlapIssues !== []) {
                $overlapMessage = implode(' ', $overlapIssues);
                $slotPayloads = array_map(function (array $slot) use ($overlapMessage) {
                    $existing = trim((string) ($slot['remarks'] ?? ''));
                    $slot['remarks'] = $existing === ''
                        ? $overlapMessage
                        : $existing . ' ' . $overlapMessage;

                    return $slot;
                }, $slotPayloads);
            }

            $slotPayloads = $this->applyLeaveConflictsToSlots(
                $slotPayloads,
                $approvedLeavesOnDate,
                $workDate,
                CompensationMethod::fromStored($payrollSnapshot['payType'] ?? null),
            );

            $compensationMethod = CompensationMethod::fromStored($payrollSnapshot['payType'] ?? null);
            $requiresClocking = (bool) ($payrollSnapshot['requiresClocking'] ?? $compensationMethod->defaultRequiresClocking());
            $slotPayloads = array_map(
                fn (array $slot) => $this->compensationHoursService->applyToSlot(
                    $slot,
                    $compensationMethod,
                    $requiresClocking,
                    $scheduledHours,
                    $holidayPayMultiplier,
                    $isUnpaidLeave,
                ),
                $slotPayloads,
            );
        }

        if ($slotPayloads === []) {
            Timesheet::query()
                ->where('employeeId', $employeeId)
                ->whereDate('date', $workDate)
                ->whereRaw('UPPER("approvalStatus") != ?', ['APPROVED'])
                ->delete();

            return collect();
        }

        $saved = collect();

        foreach ($slotPayloads as $payload) {
            $timesheet = Timesheet::query()->firstOrNew([
                'employeeId' => $employeeId,
                'date' => $workDate,
                'slotIndex' => $payload['slotIndex'],
            ]);

            if (!$timesheet->exists) {
                if ($autoApprove && !$hasClockedSlots) {
                    $timesheet->approvalStatus = 'APPROVED';
                    $timesheet->approvedAt = now();
                } else {
                    $timesheet->approvalStatus = 'PENDING';
                }
            } elseif (!in_array(strtoupper((string) $timesheet->approvalStatus), ['APPROVED', 'REJECTED'], true)) {
                if ($autoApprove && !$hasClockedSlots) {
                    $timesheet->approvalStatus = 'APPROVED';
                    $timesheet->approvedAt = now();
                } else {
                    $timesheet->approvalStatus = 'PENDING';
                }
            }

            if ($hasClockedSlots) {
                $timesheet->fill([
                    'clockInTime' => $payload['clockInTime'],
                    'clockInDeviceId' => $payload['clockInDeviceId'],
                    'clockOutTime' => $payload['clockOutTime'],
                    'clockOutDeviceId' => $payload['clockOutDeviceId'],
                    'roundOffClockInTime' => $payload['roundOffClockInTime'],
                    'roundOffClockOutTime' => $payload['roundOffClockOutTime'],
                    'clockedHoursWorked' => round((float) $payload['clockedHoursWorked'], 2),
                    'includeLunchHour' => (bool) ($payload['includeLunchHour'] ?? false),
                    'lunchHourHours' => round((float) ($payload['lunchHourHours'] ?? 0), 2),
                    'hoursWorked' => round((float) ($payload['hoursWorked'] ?? 0), 2),
                    'regularHours' => round((float) ($payload['regularHours'] ?? 0), 2),
                    'overtimeHours' => round((float) ($payload['overtimeHours'] ?? 0), 2),
                    'holidayHours' => round((float) ($payload['holidayHours'] ?? 0), 2),
                    'unpaidHours' => round((float) ($payload['unpaidHours'] ?? 0), 2),
                    'isPaid' => (bool) ($payload['isPaid'] ?? true),
                    'paidHours' => round((float) ($payload['paidHours'] ?? 0), 2),
                    'workingStatus' => $payload['workingStatus'] ?? ($holidayPayMultiplier !== null ? 'HOLIDAY' : 'REGULAR'),
                    'departmentId' => $employmentDetail?->departmentId,
                    'employmentDetailId' => $employmentDetail?->id,
                    'employeeCompensationId' => $payrollSnapshot['employeeCompensationId'] ?? null,
                    'worksiteId' => $employmentDetail?->worksiteId,
                    'payType' => $payrollSnapshot['payType'] ?? CompensationMethod::HourlyOt->value,
                    'hourlyRate' => $payrollSnapshot['hourlyRate'] ?? null,
                    'weeklySalary' => $payrollSnapshot['weeklySalary'] ?? null,
                    'baseSalary' => $payrollSnapshot['baseSalary'] ?? null,
                    'remarks' => $payload['remarks'] ?? null,
                ]);
            } else {
                unset($payload['slotIndex']);
                $timesheet->fill($payload);
            }

            $timesheet->save();
            $saved->push($timesheet);

            if (
                $employee
                && is_string($timesheet->remarks)
                && str_contains($timesheet->remarks, TimesheetLeaveConflictService::CONFLICT_MESSAGE)
            ) {
                $this->issueNotifier->notifyLeaveConflict(
                    $timesheet,
                    $employee,
                    TimesheetLeaveConflictService::CONFLICT_MESSAGE,
                );
            }
        }

        $maxSlotIndex = count($slotPayloads);
        Timesheet::query()
            ->where('employeeId', $employeeId)
            ->whereDate('date', $workDate)
            ->where('slotIndex', '>=', $maxSlotIndex)
            ->whereRaw('UPPER("approvalStatus") != ?', ['APPROVED'])
            ->delete();

        return $saved;
    }

    /**
     * @param array<int, array<string, mixed>> $slotPayloads
     * @param array<int, EmployeeLeave> $approvedLeavesOnDate
     * @return array<int, array<string, mixed>>
     */
    private function applyLeaveConflictsToSlots(
        array $slotPayloads,
        array $approvedLeavesOnDate,
        string $workDate,
        CompensationMethod $compensationMethod,
    ): array {
        if ($approvedLeavesOnDate === []) {
            return $slotPayloads;
        }

        return array_map(function (array $slot) use ($approvedLeavesOnDate, $workDate, $compensationMethod) {
            $conflicts = $this->leaveConflictService->conflictingLeaves(
                $approvedLeavesOnDate,
                $workDate,
                isset($slot['roundOffClockInTime']) ? (string) $slot['roundOffClockInTime'] : null,
                isset($slot['roundOffClockOutTime']) ? (string) $slot['roundOffClockOutTime'] : null,
            );

            if ($conflicts->isEmpty()) {
                return $slot;
            }

            $message = TimesheetLeaveConflictService::CONFLICT_MESSAGE;
            $existing = trim((string) ($slot['remarks'] ?? ''));
            if ($existing === '') {
                $slot['remarks'] = $message;
            } elseif (!str_contains($existing, $message)) {
                $slot['remarks'] = $existing.' '.$message;
            } else {
                $slot['remarks'] = $existing;
            }

            $slot['hoursWorked'] = $compensationMethod->isBaseBased()
                ? (float) ($slot['hoursWorked'] ?? 0)
                : 0.0;

            return $slot;
        }, $slotPayloads);
    }

    /**
     * @param Collection<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}> $punches
     * @return array{
     *   employeeId:string,
     *   date:string,
     *   clockInTime:?string,
     *   clockInDeviceId:?string,
     *   clockOutTime:?string,
     *   clockOutDeviceId:?string,
     *   roundOffClockInTime:?string,
     *   roundOffClockOutTime:?string,
     *   clockedHoursWorked:float,
     *   hoursWorked:float,
     *   regularHours:float,
     *   overtimeHours:float,
     *   holidayHours:float,
     *   unpaidHours:float,
     *   workingStatus:string,
     *   departmentId:mixed,
     *   worksiteId:mixed,
     *   payType:string,
     *   hourlyRate:?float,
     *   baseSalary:?float,
     *   remarks:?string
     * }
     */
    private function buildTimesheetData(
        string $employeeId,
        string $workDate,
        Collection $punches,
        float $threshold,
        float $scheduledHours,
        ?float $holidayPayMultiplier,
        bool $isUnpaidLeave,
        ?EmploymentDetail $employmentDetail,
        int $clockRoundOffMinutes = 30,
        array $payrollSnapshot = [],
    ): array {
        $compensationMethod = CompensationMethod::fromStored($payrollSnapshot['payType'] ?? null);
        $requiresClocking = (bool) ($payrollSnapshot['requiresClocking'] ?? $compensationMethod->defaultRequiresClocking());
        $calculatedHours = $this->hoursCalculator->calculate($punches, $threshold, $clockRoundOffMinutes);
        $payHours = $this->compensationHoursService->buildNoPunchDay(
            $compensationMethod,
            $requiresClocking,
            $scheduledHours,
            $holidayPayMultiplier,
            $isUnpaidLeave,
        );

        return [
            'employeeId' => $employeeId,
            'date' => $workDate,
            'employmentDetailId' => $employmentDetail?->id,
            'employeeCompensationId' => $payrollSnapshot['employeeCompensationId'] ?? null,
            'clockInTime' => $calculatedHours['clockInTime'],
            'clockInDeviceId' => $calculatedHours['clockInDeviceId'],
            'clockOutTime' => $calculatedHours['clockOutTime'],
            'clockOutDeviceId' => $calculatedHours['clockOutDeviceId'],
            'roundOffClockInTime' => $calculatedHours['roundOffClockInTime'],
            'roundOffClockOutTime' => $calculatedHours['roundOffClockOutTime'],
            'clockedHoursWorked' => round((float) $calculatedHours['clockedHoursWorked'], 2),
            'hoursWorked' => $payHours['hoursWorked'],
            'regularHours' => $payHours['regularHours'],
            'overtimeHours' => $payHours['overtimeHours'],
            'holidayHours' => $payHours['holidayHours'],
            'unpaidHours' => $payHours['unpaidHours'],
            'isPaid' => $payHours['isPaid'] ?? true,
            'paidHours' => $payHours['paidHours'] ?? 0,
            'workingStatus' => $payHours['workingStatus'],
            'departmentId' => $employmentDetail?->departmentId,
            'worksiteId' => $employmentDetail?->worksiteId,
            'payType' => $compensationMethod->storedPayType(),
            'hourlyRate' => $payrollSnapshot['hourlyRate'] ?? null,
            'weeklySalary' => $payrollSnapshot['weeklySalary'] ?? null,
            'baseSalary' => $payrollSnapshot['baseSalary'] ?? null,
            'remarks' => !empty($calculatedHours['issues']) ? implode(' ', $calculatedHours['issues']) : null,
        ];
    }

    private function employeeName(?Employee $employee): ?string
    {
        if (!$employee) {
            return null;
        }

        $name = trim(sprintf('%s %s', $employee->firstName ?? '', $employee->lastName ?? ''));

        return $name !== '' ? $name : null;
    }

    private function normalizePunchType(mixed $value): ?string
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));

        return in_array($normalized, ['IN', 'OUT'], true) ? $normalized : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }
}
