<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\LeaveStatusCode;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmploymentDetail;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimesheetImportService
{
    public function __construct(
        private readonly TimesheetScopeService $timesheetScope,
        private readonly TimesheetRoundOffService $timesheetRoundOffService,
        private readonly TimesheetLunchBreakResolver $lunchBreakResolver,
        private readonly TimesheetOverlapValidator $timesheetOverlapValidator,
        private readonly TimesheetLeaveConflictService $leaveConflictService,
        private readonly TimesheetIssueNotifier $issueNotifier,
        private readonly TimesheetEditLockService $timesheetEditLockService,
    ) {
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, errorCount: int, rows: list<array<string, mixed>>}
     */
    public function import(?User $user, array $rows): array
    {
        /** @var list<array<string, mixed>> $prepared */
        $prepared = [];

        foreach (array_values($rows) as $index => $row) {
            $prepared[] = $this->prepareRow($user, $row, $index + 1, $prepared);
        }

        $errorCount = count(array_filter($prepared, fn (array $row) => $row['errors'] !== []));
        if ($errorCount > 0 || $prepared === []) {
            return [
                'created' => 0,
                'errorCount' => $errorCount,
                'rows' => $this->publicRows($prepared),
            ];
        }

        $created = 0;
        DB::transaction(function () use ($user, $prepared, &$created) {
            foreach ($prepared as $row) {
                $this->createTimesheet($user, $row);
                $created++;
            }
        });

        return [
            'created' => $created,
            'errorCount' => 0,
            'rows' => $this->publicRows($prepared),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $accepted
     * @return array<string, mixed>
     */
    private function prepareRow(?User $user, array $row, int $rowNumber, array $accepted): array
    {
        $employeeCode = trim((string) ($row['employeeCode'] ?? ''));
        $employeeName = trim((string) ($row['employeeName'] ?? ''));
        $departmentName = trim((string) ($row['departmentName'] ?? ''));
        $type = trim((string) ($row['type'] ?? ''));
        $errors = [];

        $workDate = $this->nullableDate($row['date'] ?? null);
        $timeIn = $this->normalizeTime($row['timeIn'] ?? null);
        $timeOut = $this->normalizeTime($row['timeOut'] ?? null);
        $rawLunch = $row['lunchHours'] ?? null;
        $lunchHours = $this->lunchHours($rawLunch);
        if ($rawLunch !== null && $rawLunch !== '' && ! is_numeric($rawLunch)) {
            $errors[] = 'Lunch hours must be a number.';
        }

        $employee = $employeeCode !== '' ? $this->resolveEmployee($employeeCode) : null;
        $employmentDetail = null;
        $departmentId = null;
        $resolvedDepartmentName = $departmentName !== '' ? $departmentName : null;
        $clockIn = null;
        $clockOut = null;

        if ($employeeCode === '') {
            $errors[] = 'Employee code is required.';
        } elseif ($employee === null) {
            $errors[] = "Employee code {$employeeCode} was not found.";
        } elseif ($employee === false) {
            $errors[] = "Employee code {$employeeCode} matches more than one employee.";
            $employee = null;
        } else {
            $storedName = trim(implode(' ', array_filter([
                $employee->person?->firstName,
                $employee->person?->lastName,
            ])));
            if ($storedName !== '') {
                $employeeName = $storedName;
            }
        }

        if ($workDate === null) {
            $errors[] = 'Date is required.';
        }

        if ($timeIn === null) {
            $errors[] = 'Time in is required.';
        }

        if ($timeOut === null) {
            $errors[] = 'Time out is required.';
        }

        if ($lunchHours !== null && $lunchHours > 8) {
            $errors[] = 'Lunch hours must be between 0 and 8.';
        }

        if ($employee instanceof Employee && $workDate !== null) {
            $employee->loadMissing('employmentDetails');
            $employmentDetail = $this->employmentDetailForDate($employee, Carbon::parse($workDate));
            if (! $employmentDetail) {
                $errors[] = 'No employment assignment was found for this employee on that date.';
            } elseif ($user && ! $this->timesheetScope->canAccessEmployee($user, $employee, $employmentDetail)) {
                $errors[] = 'You are not allowed to create timesheets for this employee.';
            }
        }

        if ($departmentName !== '') {
            $departmentId = $this->departmentId($departmentName);
            if ($departmentId === null) {
                $errors[] = "Department {$departmentName} was not found.";
            }
        } elseif ($employmentDetail?->departmentId) {
            $departmentId = (int) $employmentDetail->departmentId;
            $resolvedDepartmentName = Department::query()->whereKey($departmentId)->value('name');
        }

        if ($workDate !== null && $timeIn !== null && $timeOut !== null) {
            $clockIn = Carbon::parse("{$workDate} {$timeIn}");
            $clockOut = Carbon::parse("{$workDate} {$timeOut}");
            if ($clockOut->lte($clockIn)) {
                $clockOut->addDay();
            }
        }

        if ($employee instanceof Employee && $clockIn && $clockOut && $errors === []) {
            $probe = new Timesheet([
                'employeeId' => $employee->id,
                'date' => $workDate,
                'departmentId' => $departmentId,
            ]);
            $lock = $this->timesheetEditLockService->lockInfo($probe);
            if ($lock['isLocked']) {
                $errors[] = $lock['lockReason'] ?? 'This timesheet date is locked.';
            }

            try {
                $this->timesheetOverlapValidator->assertNoOverlapWithExisting(
                    (string) $employee->id,
                    (string) $workDate,
                    $clockIn,
                    $clockOut,
                    null,
                );
            } catch (ValidationException $exception) {
                $messages = collect($exception->errors())->flatten()->filter()->values()->all();
                $errors = array_merge($errors, $messages !== [] ? $messages : ['This time overlaps an existing timesheet.']);
            }

            foreach ($accepted as $prior) {
                if (($prior['employeeId'] ?? null) !== (string) $employee->id || $prior['errors'] !== []) {
                    continue;
                }
                $priorIn = $prior['clockIn'] ?? null;
                $priorOut = $prior['clockOut'] ?? null;
                if ($priorIn instanceof Carbon && $priorOut instanceof Carbon && $clockIn->lt($priorOut) && $priorIn->lt($clockOut)) {
                    $errors[] = 'This time overlaps another row in the file for the same employee.';
                    break;
                }
            }
        }

        return [
            'rowNumber' => $rowNumber,
            'employeeCode' => $employeeCode,
            'employeeName' => $employeeName !== '' ? $employeeName : null,
            'employeeId' => $employee instanceof Employee ? (string) $employee->id : null,
            'employee' => $employee instanceof Employee ? $employee : null,
            'departmentName' => $resolvedDepartmentName,
            'departmentId' => $departmentId,
            'employmentDetail' => $employmentDetail,
            'date' => $workDate,
            'timeIn' => $timeIn,
            'timeOut' => $timeOut,
            'clockIn' => $clockIn,
            'clockOut' => $clockOut,
            'type' => $type,
            'lunchHours' => $lunchHours,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createTimesheet(?User $user, array $row): void
    {
        /** @var Employee $employee */
        $employee = $row['employee'];
        /** @var EmploymentDetail $employmentDetail */
        $employmentDetail = $row['employmentDetail'];
        /** @var Carbon $clockIn */
        $clockIn = $row['clockIn'];
        /** @var Carbon $clockOut */
        $clockOut = $row['clockOut'];
        $workDate = (string) $row['date'];
        $departmentId = $row['departmentId'] !== null ? (int) $row['departmentId'] : null;

        $slotIndex = $this->nextSlotIndex((string) $employee->id, $workDate);
        $type = trim((string) ($row['type'] ?? ''));
        $comment = $type !== '' && strcasecmp($type, 'regular') !== 0 ? $type : null;

        $timesheet = new Timesheet([
            'employeeId' => $employee->id,
            'date' => $workDate,
            'slotIndex' => $slotIndex,
            'employmentDetailId' => $employmentDetail->id,
            'departmentId' => $departmentId,
            'worksiteId' => $employmentDetail->worksiteId,
            'approvalStatus' => $this->timesheetScope->initialApprovalStatus($employee),
            'clockInTime' => $clockIn,
            'clockOutTime' => $clockOut,
            'roundOffClockInTime' => $clockIn,
            'roundOffClockOutTime' => $clockOut,
            'comment' => $comment,
            'updatedBy' => $user?->id,
        ]);
        $timesheet->setRelation('employmentDetail', $employmentDetail);

        $lunchHours = $row['lunchHours'];
        if ($lunchHours !== null) {
            $timesheet->includeLunchHour = $lunchHours > 0;
            $timesheet->lunchHourHours = $lunchHours;
        } else {
            $lunchSettings = $this->lunchBreakResolver->lunchSettingsFor(
                (string) $employee->id,
                Carbon::parse($workDate),
                $departmentId,
                $slotIndex,
            );
            $timesheet->includeLunchHour = $lunchSettings['include_lunch_hour'];
            $timesheet->lunchHourHours = $lunchSettings['lunch_hour_hours'];
        }

        $timesheet->save();
        $this->timesheetRoundOffService->recalculate($timesheet, $clockIn, $clockOut);
        $timesheet->refresh();

        if ((int) ($timesheet->departmentId ?? 0) !== (int) ($departmentId ?? 0)) {
            $timesheet->departmentId = $departmentId;
        }

        $timesheet->approvalStatus = $this->timesheetScope->initialApprovalStatus($employee);
        $timesheet->comment = $comment;
        $timesheet->updatedBy = $user?->id;
        $timesheet->save();

        $this->applyLeaveConflictIfNeeded($timesheet, $clockIn, $clockOut);
    }

    private function applyLeaveConflictIfNeeded(Timesheet $timesheet, Carbon $clockIn, Carbon $clockOut): void
    {
        $workDate = $timesheet->date->format('Y-m-d');
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
            $clockIn->format('Y-m-d H:i:s'),
            $clockOut->format('Y-m-d H:i:s'),
        );

        if ($conflicts->isEmpty()) {
            return;
        }

        $message = TimesheetLeaveConflictService::CONFLICT_MESSAGE;
        $remarks = trim((string) $timesheet->remarks);
        $timesheet->remarks = $remarks === '' || ! str_contains($remarks, $message)
            ? trim($remarks.' '.$message)
            : $remarks;
        $timesheet->hoursWorked = 0;
        $timesheet->regularHours = 0;
        $timesheet->overtimeHours = 0;
        $timesheet->isPaid = false;
        $timesheet->paidHours = 0;
        $timesheet->save();

        $employee = Employee::query()->find($timesheet->employeeId);
        if ($employee) {
            $this->issueNotifier->notifyLeaveConflict($timesheet, $employee, $message);
        }
    }

    private function nextSlotIndex(string $employeeId, string $workDate): int
    {
        $maxSlot = Timesheet::query()
            ->where('employeeId', $employeeId)
            ->whereDate('date', $workDate)
            ->max('slotIndex');

        return $maxSlot === null ? 0 : ((int) $maxSlot) + 1;
    }

    /**
     * @return Employee|false|null false when the code matches more than one employee
     */
    private function resolveEmployee(string $code): Employee|false|null
    {
        $normalized = mb_strtolower(trim($code));
        $matches = Employee::query()
            ->with('person')
            ->where(function ($query) use ($normalized) {
                foreach (['code', 'internalId1', 'internalId2'] as $column) {
                    $query->orWhereRaw('lower(btrim("'.$column.'")) = ?', [$normalized]);
                }
            })
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            return false;
        }

        return $matches->first();
    }

    private function departmentId(string $name): ?int
    {
        $id = Department::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function employmentDetailForDate(Employee $employee, Carbon $workDate): ?EmploymentDetail
    {
        /** @var Collection<int, EmploymentDetail> $details */
        $details = $employee->employmentDetails;

        return $details
            ->filter(function (EmploymentDetail $detail) use ($workDate) {
                $start = Carbon::parse($detail->startDate);
                $end = $detail->endDate ? Carbon::parse($detail->endDate) : null;

                return $workDate->gte($start) && ($end === null || $workDate->lte($end));
            })
            ->sort(function (EmploymentDetail $left, EmploymentDetail $right) {
                if ($left->isActive !== $right->isActive) {
                    return $right->isActive <=> $left->isActive;
                }

                return Carbon::parse($right->startDate)->timestamp
                    <=> Carbon::parse($left->startDate)->timestamp;
            })
            ->first();
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))->toDateString();
        }

        if (is_numeric($value)) {
            try {
                return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $value))->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))->format('H:i');
        }

        if (is_numeric($value)) {
            $seconds = (int) round(((float) $value) * 24 * 60 * 60);
            $hours = intdiv($seconds, 3600) % 24;
            $minutes = intdiv($seconds % 3600, 60);

            return sprintf('%02d:%02d', $hours, $minutes);
        }

        $text = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap]m)?$/i', $text, $matches) === 1) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $meridiem = strtolower((string) ($matches[3] ?? ''));
            if ($meridiem === 'pm' && $hours < 12) {
                $hours += 12;
            }
            if ($meridiem === 'am' && $hours === 12) {
                $hours = 0;
            }
            if ($hours > 23 || $minutes > 59) {
                return null;
            }

            return sprintf('%02d:%02d', $hours, $minutes);
        }

        try {
            return Carbon::parse($text)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function lunchHours(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round(abs((float) $value), 2);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function publicRows(array $rows): array
    {
        return array_map(fn (array $row) => [
            'rowNumber' => $row['rowNumber'],
            'employeeCode' => $row['employeeCode'],
            'employeeName' => $row['employeeName'],
            'departmentName' => $row['departmentName'],
            'date' => $row['date'],
            'timeIn' => $row['timeIn'],
            'timeOut' => $row['timeOut'],
            'type' => $row['type'] !== '' ? $row['type'] : null,
            'lunchHours' => $row['lunchHours'],
            'errors' => $row['errors'],
        ], $rows);
    }
}
