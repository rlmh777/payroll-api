<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeReporting;
use App\Models\EmploymentDetail;
use App\Models\ScheduledWork;
use App\Models\User;
use App\Models\Worksite;
use App\Modules\Hr\Services\Employment\EmploymentContractAssignmentService;
use App\Support\Access;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduledWorkImportService
{
    public function __construct(
        private readonly ScheduledWorkOverlapValidator $overlapValidator,
        private readonly EmploymentContractAssignmentService $contractAssignmentService,
        private readonly TimesheetCompensationRecalculationService $timesheetRecalculationService,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{
     *     recordCount: int,
     *     errorCount: int,
     *     validCount: int,
     *     companyWide: bool,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function preview(?User $user, array $rows): array
    {
        $companyWide = Access::canManageCompanyScheduler($user);
        $allowedEmployeeIds = $companyWide ? null : $this->subordinateEmployeeIds($user);

        $previewRows = [];
        $errorCount = 0;
        $validCount = 0;
        /** @var list<array{employeeId: string, startDate: string, endDate: string, startTime: string, endTime: string}> $accepted */
        $accepted = [];

        foreach ($rows as $index => $row) {
            $parsed = $this->validateRow(
                $row,
                $index + 1,
                $allowedEmployeeIds,
                $accepted,
            );
            $previewRows[] = $parsed;
            if ($parsed['errors'] === []) {
                $validCount++;
            } else {
                $errorCount++;
            }

            if ($parsed['errors'] === [] && ! empty($parsed['employeeId'])) {
                $accepted[] = [
                    'employeeId' => (string) $parsed['employeeId'],
                    'startDate' => (string) $parsed['startDate'],
                    'endDate' => (string) $parsed['endDate'],
                    'startTime' => (string) $parsed['startTime'],
                    'endTime' => (string) $parsed['endTime'],
                ];
            }
        }

        return [
            'recordCount' => count($previewRows),
            'errorCount' => $errorCount,
            'validCount' => $validCount,
            'companyWide' => $companyWide,
            'rows' => $previewRows,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{
     *     recordCount: int,
     *     errorCount: int,
     *     validCount: int,
     *     companyWide: bool,
     *     created: int,
     *     rows: list<array<string, mixed>>,
     *     createdRecords: list<ScheduledWork>
     * }
     */
    public function confirm(?User $user, array $rows): array
    {
        $preview = $this->preview($user, $rows);

        if (($preview['errorCount'] ?? 0) > 0 || ($preview['validCount'] ?? 0) === 0) {
            return [
                ...$preview,
                'created' => 0,
                'createdRecords' => [],
            ];
        }

        $createdRecords = DB::transaction(function () use ($preview) {
            $records = [];

            foreach ($preview['rows'] as $row) {
                $payload = [
                    'employeeId' => $row['employeeId'],
                    'employmentDetailId' => $row['employmentDetailId'],
                    'departmentId' => $row['departmentId'],
                    'worksiteId' => $row['worksiteId'],
                    'startDate' => $row['startDate'],
                    'endDate' => $row['endDate'],
                    'startTime' => $row['startTime'],
                    'endTime' => $row['endTime'],
                    'description' => $row['description'],
                    'rate' => $row['rate'],
                    'includeLunchHour' => $row['includeLunchHour'],
                    'lunchHourHours' => $row['lunchHourHours'],
                ];

                $payload = $this->applyEmploymentAssignment($payload);
                $records[] = ScheduledWork::create($payload)->load([
                    'employee',
                    'department',
                    'worksite',
                    'employmentDetail.department',
                    'employmentDetail.worksite',
                    'employmentDetail.contractType',
                    'employmentDetail.defaultPayPeriodGroup',
                ]);
            }

            return $records;
        });

        if ($createdRecords !== []) {
            $startDate = collect($createdRecords)->min(fn (ScheduledWork $item) => Carbon::parse($item->startDate)->toDateString());
            $endDate = collect($createdRecords)->max(fn (ScheduledWork $item) => Carbon::parse($item->endDate)->toDateString());
            $employeeIds = collect($createdRecords)
                ->pluck('employeeId')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values()
                ->all();

            $this->timesheetRecalculationService->recalculate($employeeIds, $startDate, $endDate, false);
        }

        return [
            ...$preview,
            'created' => count($createdRecords),
            'createdRecords' => $createdRecords,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, string>|null  $allowedEmployeeIds
     * @param  list<array{employeeId: string, startDate: string, endDate: string, startTime: string, endTime: string}>  $accepted
     * @return array<string, mixed>
     */
    private function validateRow(
        array $row,
        int $rowNumber,
        ?Collection $allowedEmployeeIds,
        array $accepted,
    ): array {
        $employeeCode = trim((string) ($row['employeeCode'] ?? $row['employeeIdentifier'] ?? ''));
        $departmentName = trim((string) ($row['departmentName'] ?? ''));
        $worksiteName = trim((string) ($row['worksiteName'] ?? ''));
        $startDateRaw = $row['startDate'] ?? null;
        $endDateRaw = $row['endDate'] ?? null;
        $startTimeRaw = $row['startTime'] ?? null;
        $endTimeRaw = $row['endTime'] ?? null;
        $descriptionRaw = $row['description'] ?? null;
        $rateRaw = $row['rate'] ?? null;
        $includeLunchHour = $this->toBool($row['includeLunchHour'] ?? false);
        $lunchHourHoursRaw = $row['lunchHourHours'] ?? null;

        $errors = [];
        $employee = $employeeCode !== '' ? $this->resolveEmployee($employeeCode) : null;
        $startDate = $this->nullableDate($startDateRaw);
        $endDate = $this->nullableDate($endDateRaw) ?? $startDate;
        $startTime = $this->normalizeTime($startTimeRaw) ?? '09:00';
        $endTime = $this->normalizeTime($endTimeRaw) ?? '17:00';
        $description = $this->normalizeDescription($descriptionRaw);
        $rate = $this->nullableFloat($rateRaw) ?? 1.0;
        $lunchHourHours = $this->nullableFloat($lunchHourHoursRaw) ?? 1.0;
        $departmentId = null;
        $worksiteId = null;
        $employmentDetailId = null;
        $employeeName = null;

        if ($employeeCode === '') {
            $errors[] = 'Employee code is required.';
        } elseif (! $employee) {
            $errors[] = "Employee '{$employeeCode}' was not found.";
        } else {
            $employeeName = trim(
                trim((string) ($employee->person?->firstName ?? '')).' '
                .trim((string) ($employee->person?->lastName ?? '')),
            ) ?: (string) $employee->code;

            if ($allowedEmployeeIds !== null && ! $allowedEmployeeIds->contains((string) $employee->id)) {
                $errors[] = 'You can only schedule employees who report to you.';
            }
        }

        if ($startDate === null) {
            $errors[] = 'Start date is required (YYYY-MM-DD).';
        } else {
            $today = Carbon::today()->toDateString();
            if ($endDate !== null && $endDate < $startDate) {
                [$startDate, $endDate] = [$endDate, $startDate];
            }

            if ($endDate !== null && $endDate < $today) {
                $errors[] = 'Shifts must be on today or a future date.';
            } elseif ($startDate < $today) {
                $startDate = $today;
            }
        }

        if ($startTimeRaw !== null && $startTimeRaw !== '' && $this->normalizeTime($startTimeRaw) === null) {
            $errors[] = 'Start time must be HH:mm.';
        }

        if ($endTimeRaw !== null && $endTimeRaw !== '' && $this->normalizeTime($endTimeRaw) === null) {
            $errors[] = 'End time must be HH:mm.';
        }

        if ($departmentName !== '') {
            $departmentId = $this->lookupId(Department::class, $departmentName);
            if ($departmentId === null) {
                $errors[] = "Department '{$departmentName}' was not found.";
            }
        }

        if ($worksiteName !== '') {
            $worksiteId = $this->lookupId(Worksite::class, $worksiteName);
            if ($worksiteId === null) {
                $errors[] = "Worksite '{$worksiteName}' was not found.";
            }
        }

        if ($rate < 0 || $rate > 9.99) {
            $errors[] = 'Rate must be between 0 and 9.99.';
        }

        if ($lunchHourHours < 0 || $lunchHourHours > 8) {
            $errors[] = 'Lunch hours must be between 0 and 8.';
        }

        if ($employee && $errors === [] && $startDate && $endDate) {
            $employmentDetailId = EmploymentDetail::query()
                ->where('employeeId', $employee->id)
                ->where('isActive', true)
                ->orderByDesc('startDate')
                ->value('id');

            if ($departmentId === null && $employmentDetailId) {
                $departmentId = EmploymentDetail::query()
                    ->where('id', $employmentDetailId)
                    ->value('departmentId');
            }

            if ($worksiteId === null && $employmentDetailId) {
                $worksiteId = EmploymentDetail::query()
                    ->where('id', $employmentDetailId)
                    ->value('worksiteId');
            }

            try {
                $this->overlapValidator->validate(
                    (string) $employee->id,
                    $startDate,
                    $endDate,
                    $startTime,
                    $endTime,
                );
            } catch (ValidationException $exception) {
                $messages = collect($exception->errors())->flatten()->filter()->values()->all();
                $errors = array_merge($errors, $messages !== [] ? $messages : ['This shift overlaps an existing schedule.']);
            }

            foreach ($accepted as $prior) {
                if ($prior['employeeId'] !== (string) $employee->id) {
                    continue;
                }

                if ($this->schedulesOverlap(
                    $startDate,
                    $endDate,
                    $startTime,
                    $endTime,
                    $prior['startDate'],
                    $prior['endDate'],
                    $prior['startTime'],
                    $prior['endTime'],
                )) {
                    $errors[] = 'This shift overlaps another row in the same import for the same employee.';
                    break;
                }
            }
        }

        return [
            'rowNumber' => $rowNumber,
            'employeeCode' => $employeeCode,
            'employeeId' => $employee?->id,
            'employeeName' => $employeeName,
            'departmentName' => $departmentName !== '' ? $departmentName : null,
            'departmentId' => $departmentId !== null ? (int) $departmentId : null,
            'worksiteName' => $worksiteName !== '' ? $worksiteName : null,
            'worksiteId' => $worksiteId !== null ? (int) $worksiteId : null,
            'employmentDetailId' => $employmentDetailId ? (string) $employmentDetailId : null,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'description' => $description,
            'rate' => round($rate, 2),
            'includeLunchHour' => $includeLunchHour,
            'lunchHourHours' => round($lunchHourHours, 2),
            'errors' => $errors,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function subordinateEmployeeIds(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        $actor = Employee::query()->where('user_id', $user->id)->first();
        if (! $actor) {
            return collect();
        }

        $fromReporting = EmployeeReporting::query()
            ->where('supervisor_id', $actor->id)
            ->where('is_active', true)
            ->pluck('subordinate_id');

        $fromSupervisorId = Employee::query()
            ->where('supervisorId', $actor->id)
            ->pluck('id');

        return $fromReporting
            ->merge($fromSupervisorId)
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
    }

    private function resolveEmployee(string $identifier): ?Employee
    {
        return Employee::query()
            ->where(function ($query) use ($identifier) {
                $query->where('code', $identifier)
                    ->orWhere('id', $identifier);
            })
            ->first();
    }

    /**
     * @param  class-string  $modelClass
     */
    private function lookupId(string $modelClass, string $name): mixed
    {
        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass();

        return $model::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value($model->getKeyName());
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
            // Excel serial date (days since 1899-12-30), matching client-side xlsx parsing.
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

        if (is_numeric($value)) {
            $seconds = (int) round(((float) $value) * 24 * 60 * 60);
            $hours = intdiv($seconds, 3600) % 24;
            $minutes = intdiv($seconds % 3600, 60);

            return sprintf('%02d:%02d', $hours, $minutes);
        }

        $text = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $text, $matches) === 1) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        try {
            return Carbon::parse($text)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDescription(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 1024);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function toBool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyEmploymentAssignment(array $payload): array
    {
        $contractId = $this->contractAssignmentService->resolveContractId(
            $payload['employeeId'] ?? null,
            $payload['employmentDetailId'] ?? null,
            isset($payload['departmentId']) ? (int) $payload['departmentId'] : null,
            (string) $payload['startDate'],
            (string) $payload['endDate'],
        );

        if ($contractId) {
            $payload['employmentDetailId'] = $contractId;
        }

        return $payload;
    }

    private function schedulesOverlap(
        string $startDateA,
        string $endDateA,
        string $startTimeA,
        string $endTimeA,
        string $startDateB,
        string $endDateB,
        string $startTimeB,
        string $endTimeB,
    ): bool {
        if ($endDateA < $startDateB || $endDateB < $startDateA) {
            return false;
        }

        $cursor = Carbon::parse($startDateA)->startOfDay();
        $last = Carbon::parse($endDateA)->startOfDay();
        $rangeStartB = Carbon::parse($startDateB)->startOfDay();
        $rangeEndB = Carbon::parse($endDateB)->startOfDay();

        while ($cursor->lte($last)) {
            if ($cursor->betweenIncluded($rangeStartB, $rangeEndB)) {
                $startA = Carbon::parse($cursor->toDateString().' '.$startTimeA);
                $endA = Carbon::parse($cursor->toDateString().' '.$endTimeA);
                $startB = Carbon::parse($cursor->toDateString().' '.$startTimeB);
                $endB = Carbon::parse($cursor->toDateString().' '.$endTimeB);

                if ($startA->lt($endB) && $startB->lt($endA)) {
                    return true;
                }
            }

            $cursor->addDay();
        }

        return false;
    }
}
