<?php

namespace App\Services\Attendance;

use App\Models\Calendar;
use App\Models\ClockingLog;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmploymentDetail;
use App\Models\Timesheet;
use App\Models\WorkTimesheet;
use App\Models\WorkTimesheetDepartment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetProcessingService
{
    public function __construct(
        private readonly TimesheetHoursCalculator $hoursCalculator
    ) {
    }

    /**
     * @param array{startDate?:string, endDate?:string, biometricUserId?:string, overtimeThresholdHours?:float|int|string} $filters
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
            $query->whereDate('punchDateTime', '>=', $filters['startDate']);
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
        );

        foreach ($employmentDetails as $employeeId => $details) {
            $employee = $details->first()?->employee;
            if ($employee) {
                $employeesById[(string) $employeeId] = $employee;
            }
        }

        $scheduleAssignments = $this->loadScheduleAssignments($employmentDetails, $rangeEnd);
        $holidayDates = Calendar::query()
            ->where('type', 'holiday')
            ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [Carbon::parse($date)->toDateString() => true])
            ->all();
        $unpaidLeaveDates = $this->loadUnpaidLeaveDates($rangeStart, $rangeEnd);

        $rowKeys = $this->buildRowKeys(
            $rangeStart,
            $rangeEnd,
            $logGroupsByEmployee,
            $employmentDetails,
            $scheduleAssignments,
        );

        $warnings = [];
        $processedTimesheets = 0;
        $clockingTimesheets = 0;
        $scheduledTimesheets = 0;
        $regularHours = 0.0;
        $overtimeHours = 0.0;

        foreach ($rowKeys as $rowKey) {
            $employeeId = $rowKey['employeeId'];
            $workDate = $rowKey['date'];
            $date = Carbon::parse($workDate);
            $details = $employmentDetails->get($employeeId, collect());
            $employmentDetail = $this->employmentDetailForDate($details, $date);
            $workTimesheet = $this->workTimesheetForDate(
                $employmentDetail?->departmentId,
                $date,
                $scheduleAssignments,
            );
            $scheduledHours = $this->scheduledHoursForDate($workTimesheet, $date);
            $punches = collect($logGroupsByEmployee[$employeeId][$workDate] ?? []);
            $threshold = isset($filters['overtimeThresholdHours'])
                ? (float) $filters['overtimeThresholdHours']
                : ($scheduledHours > 0 ? $scheduledHours : (float) config('attendance.standard_daily_hours', 8));

            $timesheetData = $this->buildTimesheetData(
                $employeeId,
                $workDate,
                $punches,
                $threshold,
                $scheduledHours,
                isset($holidayDates[$workDate]),
                isset($unpaidLeaveDates[$employeeId][$workDate]),
                $employmentDetail,
            );

            $timesheet = Timesheet::query()->firstOrNew([
                'employeeId' => $employeeId,
                'date' => $workDate,
            ]);

            $timesheet->fill($timesheetData);

            if (!$timesheet->exists) {
                $timesheet->approvalStatus = 'PENDING';
            } elseif (!in_array(strtoupper((string) $timesheet->approvalStatus), ['APPROVED', 'REJECTED'], true)) {
                $timesheet->approvalStatus = 'PENDING';
            }

            $timesheet->save();
            $processedTimesheets++;
            $punches->isEmpty() ? $scheduledTimesheets++ : $clockingTimesheets++;
            $regularHours += (float) $timesheetData['regularHours'];
            $overtimeHours += (float) $timesheetData['overtimeHours'];

            if (!empty($timesheetData['remarks'])) {
                $warnings[] = [
                    'employeeId' => $employeeId,
                    'employeeName' => $this->employeeName($employeesById[$employeeId] ?? null),
                    'date' => $workDate,
                    'message' => (string) $timesheetData['remarks'],
                ];
            }
        }

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
     * @return Collection<string, Collection<int, EmploymentDetail>>
     */
    private function loadEmploymentDetails(
        Carbon $rangeStart,
        Carbon $rangeEnd,
        ?string $biometricUserId,
        array $employeeMap
    ): Collection {
        $query = EmploymentDetail::query()
            ->with(['employee', 'payrateFrequency', 'department', 'worksite'])
            ->whereDate('startDate', '<=', $rangeEnd->toDateString())
            ->where(function ($query) use ($rangeStart) {
                $query
                    ->whereNull('endDate')
                    ->orWhereDate('endDate', '>=', $rangeStart->toDateString());
            })
            ->orderByDesc('startDate');

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
     * @param Collection<string, Collection<int, EmploymentDetail>> $employmentDetails
     * @return Collection<string, Collection<int, WorkTimesheetDepartment>>
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

        return WorkTimesheetDepartment::query()
            ->with('workTimesheet')
            ->whereIn('department_id', $departmentIds)
            ->whereDate('effective_date', '<=', $rangeEnd->toDateString())
            ->orderByDesc('effective_date')
            ->get()
            ->groupBy(fn (WorkTimesheetDepartment $assignment) => (string) $assignment->department_id);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function loadUnpaidLeaveDates(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $leaves = EmployeeLeave::query()
            ->with('leaveType')
            ->whereRaw('UPPER("approvalStatus") = ?', ['APPROVED'])
            ->whereDate('startDate', '<=', $rangeEnd->toDateString())
            ->whereDate('endDate', '>=', $rangeStart->toDateString())
            ->where(function ($query) {
                $query
                    ->where('multiplier', '<=', 0)
                    ->orWhereHas('leaveType', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%unpaid%']));
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
     * @param array<string, array<string, array<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}>>> $logGroupsByEmployee
     * @param Collection<string, Collection<int, EmploymentDetail>> $employmentDetails
     * @param Collection<string, Collection<int, WorkTimesheetDepartment>> $scheduleAssignments
     * @return array<int, array{employeeId:string, date:string}>
     */
    private function buildRowKeys(
        Carbon $rangeStart,
        Carbon $rangeEnd,
        array $logGroupsByEmployee,
        Collection $employmentDetails,
        Collection $scheduleAssignments
    ): array {
        $keys = [];

        foreach ($logGroupsByEmployee as $employeeId => $dateGroups) {
            foreach (array_keys($dateGroups) as $workDate) {
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
                    $workTimesheet = $this->workTimesheetForDate(
                        $employmentDetail->departmentId,
                        $date,
                        $scheduleAssignments,
                    );

                    if ($this->isScheduledWorkDay($workTimesheet, $date)) {
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
        return $details->first(function (EmploymentDetail $detail) use ($date) {
            $startDate = Carbon::parse($detail->startDate)->startOfDay();
            $endDate = $detail->endDate ? Carbon::parse($detail->endDate)->startOfDay() : null;

            return $startDate->lte($date) && (!$endDate || $endDate->gte($date));
        });
    }

    /**
     * @param Collection<string, Collection<int, WorkTimesheetDepartment>> $scheduleAssignments
     */
    private function workTimesheetForDate(
        mixed $departmentId,
        Carbon $date,
        Collection $scheduleAssignments
    ): ?WorkTimesheet {
        if ($departmentId === null) {
            return null;
        }

        $assignments = $scheduleAssignments->get((string) $departmentId, collect());
        $assignment = $assignments->first(
            fn (WorkTimesheetDepartment $item) => Carbon::parse($item->effective_date)->startOfDay()->lte($date)
        );

        return $assignment?->workTimesheet;
    }

    private function isScheduledWorkDay(?WorkTimesheet $workTimesheet, Carbon $date): bool
    {
        if (!$workTimesheet) {
            return $date->isWeekday();
        }

        if (!$workTimesheet->is_active) {
            return false;
        }

        $days = is_array($workTimesheet->days) ? $workTimesheet->days : [];

        return in_array($date->format('D'), $days, true);
    }

    private function scheduledHoursForDate(?WorkTimesheet $workTimesheet, Carbon $date): float
    {
        if (!$this->isScheduledWorkDay($workTimesheet, $date)) {
            return 0.0;
        }

        if (!$workTimesheet) {
            return (float) config('attendance.standard_daily_hours', 8);
        }

        $start = Carbon::parse($date->toDateString().' '.$workTimesheet->start_time);
        $end = Carbon::parse($date->toDateString().' '.$workTimesheet->end_time);

        if ($end->lte($start)) {
            $end->addDay();
        }

        $minutes = max(0, $start->diffInMinutes($end, false) - (int) $workTimesheet->break_minutes);

        return round($minutes / 60, 2);
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
        bool $isHoliday,
        bool $isUnpaidLeave,
        ?EmploymentDetail $employmentDetail
    ): array {
        $payType = $this->payType($employmentDetail);
        $calculatedHours = $this->hoursCalculator->calculate($punches, $threshold);
        $hoursWorked = $calculatedHours['hoursWorked'];
        $regularHours = $calculatedHours['regularHours'];
        $overtimeHours = $calculatedHours['overtimeHours'];
        $holidayHours = $isHoliday ? $scheduledHours : 0.0;
        $unpaidHours = 0.0;
        $workingStatus = $overtimeHours > 0 ? 'OVERTIME' : 'REGULAR';

        if ($punches->isEmpty() && $scheduledHours > 0) {
            if ($isHoliday) {
                $workingStatus = 'HOLIDAY';
            } elseif ($isUnpaidLeave) {
                $hoursWorked = 0.0;
                $regularHours = 0.0;
                $unpaidHours = $scheduledHours;
                $workingStatus = 'UNPAID';
            } elseif ($payType === 'BASE_SALARY') {
                $hoursWorked = $scheduledHours;
                $regularHours = $scheduledHours;
            } else {
                $hoursWorked = 0.0;
                $regularHours = 0.0;
                $unpaidHours = $scheduledHours;
                $workingStatus = 'UNPAID';
            }
        } elseif ($isHoliday) {
            $workingStatus = 'HOLIDAY';
        }

        return [
            'employeeId' => $employeeId,
            'date' => $workDate,
            'clockInTime' => $calculatedHours['clockInTime'],
            'clockInDeviceId' => $calculatedHours['clockInDeviceId'],
            'clockOutTime' => $calculatedHours['clockOutTime'],
            'clockOutDeviceId' => $calculatedHours['clockOutDeviceId'],
            'hoursWorked' => round($hoursWorked, 2),
            'regularHours' => round($regularHours, 2),
            'overtimeHours' => round($overtimeHours, 2),
            'holidayHours' => round($holidayHours, 2),
            'unpaidHours' => round($unpaidHours, 2),
            'workingStatus' => $workingStatus,
            'departmentId' => $employmentDetail?->departmentId,
            'worksiteId' => $employmentDetail?->worksiteId,
            'payType' => $payType,
            'hourlyRate' => $employmentDetail ? (float) $employmentDetail->hourlyRate : null,
            'baseSalary' => $payType === 'BASE_SALARY' && $employmentDetail
                ? (float) $employmentDetail->totalRate
                : null,
            'remarks' => !empty($calculatedHours['issues']) ? implode(' ', $calculatedHours['issues']) : null,
        ];
    }

    private function payType(?EmploymentDetail $employmentDetail): string
    {
        if (!$employmentDetail) {
            return 'HOURLY';
        }

        if ((float) $employmentDetail->hourlyRate > 0) {
            return 'HOURLY';
        }

        $frequency = strtolower(trim((string) ($employmentDetail->payrateFrequency?->name ?? '')));

        if (str_contains($frequency, 'hour')) {
            return 'HOURLY';
        }

        if ((float) $employmentDetail->totalRate > 0) {
            return 'BASE_SALARY';
        }

        return 'HOURLY';
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
