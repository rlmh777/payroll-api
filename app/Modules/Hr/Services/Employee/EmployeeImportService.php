<?php

namespace App\Modules\Hr\Services\Employee;

use App\Enums\CompensationMethod;
use App\Enums\CompensationReason;
use App\Models\Account;
use App\Models\CitizenshipStatus;
use App\Models\ClockingLog;
use App\Models\ContractType;
use App\Models\Country;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeStatus;
use App\Models\EmploymentDetail;
use App\Models\EmploymentStatus;
use App\Models\Gender;
use App\Models\Honorific;
use App\Models\Locality;
use App\Models\PaymentMethod;
use App\Models\PayPeriodGroup;
use App\Models\PayrateFrequency;
use App\Models\ScheduledWork;
use App\Models\TimesheetTemplate;
use App\Models\Worksite;
use App\Modules\Hr\Services\Attendance\ScheduledWorkOverlapValidator;
use App\Modules\Hr\Services\EmployeePersonSync;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeImportService
{
    /** @var array<string, array<string, mixed>> */
    private array $lookupCache = [];

    public function __construct(
        private readonly EmployeeCompensationResolver $compensationResolver,
        private readonly ScheduledWorkOverlapValidator $overlapValidator,
    ) {
    }

    /**
     * @param array{
     *     employees?: list<array<string, mixed>>,
     *     employment?: list<array<string, mixed>>,
     *     compensation?: list<array<string, mixed>>,
     *     scheduledWork?: list<array<string, mixed>>,
     *     clockingLogs?: list<array<string, mixed>>
     * } $payload
     * @return array{
     *     summary: array<string, int>,
     *     errors: list<array{sheet: string, row: int|null, code: string|null, message: string}>,
     *     createdEmployeeIds: list<string>
     * }
     */
    public function import(array $payload): array
    {
        $summary = [
            'employeesCreated' => 0,
            'employmentCreated' => 0,
            'compensationCreated' => 0,
            'scheduledWorkCreated' => 0,
            'clockingLogsCreated' => 0,
            'failed' => 0,
        ];
        $errors = [];
        $createdEmployeeIds = [];

        foreach ($payload['employees'] ?? [] as $index => $row) {
            if (!is_array($row) || $this->isBlankRow($row)) {
                continue;
            }

            $rowNumber = $index + 2;
            $code = $this->stringValue($row['code'] ?? null);

            try {
                $employee = DB::transaction(function () use ($row) {
                    return $this->createEmployeeFromRow($row);
                });
                $summary['employeesCreated']++;
                $createdEmployeeIds[] = (string) $employee->id;
            } catch (Throwable $e) {
                $summary['failed']++;
                $errors[] = $this->error('Employees', $rowNumber, $code, $this->exceptionMessage($e));
            }
        }

        foreach ($payload['employment'] ?? [] as $index => $row) {
            if (!is_array($row) || $this->isBlankRow($row)) {
                continue;
            }

            $rowNumber = $index + 2;
            $code = $this->stringValue($row['employeeCode'] ?? null);

            try {
                DB::transaction(function () use ($row) {
                    $this->createEmploymentFromRow($row);
                });
                $summary['employmentCreated']++;
            } catch (Throwable $e) {
                $summary['failed']++;
                $errors[] = $this->error('Employment', $rowNumber, $code, $this->exceptionMessage($e));
            }
        }

        foreach ($payload['compensation'] ?? [] as $index => $row) {
            if (!is_array($row) || $this->isBlankRow($row)) {
                continue;
            }

            $rowNumber = $index + 2;
            $code = $this->stringValue($row['employeeCode'] ?? null);

            try {
                DB::transaction(function () use ($row) {
                    $this->createCompensationFromRow($row);
                });
                $summary['compensationCreated']++;
            } catch (Throwable $e) {
                $summary['failed']++;
                $errors[] = $this->error('Compensation', $rowNumber, $code, $this->exceptionMessage($e));
            }
        }

        foreach ($payload['scheduledWork'] ?? [] as $index => $row) {
            if (!is_array($row) || $this->isBlankRow($row)) {
                continue;
            }

            $rowNumber = $index + 2;
            $code = $this->stringValue($row['employeeCode'] ?? null);

            try {
                DB::transaction(function () use ($row) {
                    $this->createScheduledWorkFromRow($row);
                });
                $summary['scheduledWorkCreated']++;
            } catch (Throwable $e) {
                $summary['failed']++;
                $errors[] = $this->error('ScheduledWork', $rowNumber, $code, $this->exceptionMessage($e));
            }
        }

        $clockingRows = $payload['clockingLogs'] ?? [];
        if ($clockingRows !== []) {
            if (!$this->clockingLogsAvailable()) {
                $errors[] = $this->error(
                    'ClockingLogs',
                    null,
                    null,
                    'Clocking log import skipped: clocking_log table or model is unavailable.',
                );
            } else {
                foreach ($clockingRows as $index => $row) {
                    if (!is_array($row) || $this->isBlankRow($row)) {
                        continue;
                    }

                    $rowNumber = $index + 2;
                    $code = $this->stringValue($row['biometricUserId'] ?? null);

                    try {
                        DB::transaction(function () use ($row) {
                            $this->createClockingLogFromRow($row);
                        });
                        $summary['clockingLogsCreated']++;
                    } catch (Throwable $e) {
                        $summary['failed']++;
                        $errors[] = $this->error('ClockingLogs', $rowNumber, $code, $this->exceptionMessage($e));
                    }
                }
            }
        }

        return [
            'summary' => $summary,
            'errors' => $errors,
            'createdEmployeeIds' => $createdEmployeeIds,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createEmployeeFromRow(array $row): Employee
    {
        $code = $this->requireString($row, 'code', 'Employee code is required.');

        if (Employee::query()->where('code', $code)->exists()) {
            throw new \RuntimeException("Employee code already exists: {$code}");
        }

        $localityId = $this->requireLookupId(
            Locality::class,
            $this->requireString($row, 'localityName', 'localityName is required.'),
            'localityName',
        );
        $genderId = $this->requireLookupId(
            Gender::class,
            $this->requireString($row, 'genderName', 'genderName is required.'),
            'genderName',
        );
        $payrateFrequencyId = $this->optionalLookupId(
            PayrateFrequency::class,
            $this->stringValue($row['payrateFrequencyName'] ?? null),
            'payrateFrequencyName',
        );
        $paymentMethodId = $this->requireLookupId(
            PaymentMethod::class,
            $this->requireString($row, 'paymentMethodName', 'paymentMethodName is required.'),
            'paymentMethodName',
        );

        $honorificId = $this->optionalLookupId(
            Honorific::class,
            $this->stringValue($row['honorificName'] ?? null),
            'honorificName',
        );
        $citizenshipStatusId = $this->optionalLookupId(
            CitizenshipStatus::class,
            $this->stringValue($row['citizenshipStatusName'] ?? null),
            'citizenshipStatusName',
        );
        $nationalityId = $this->optionalLookupId(
            Country::class,
            $this->stringValue($row['nationalityName'] ?? null),
            'nationalityName',
        );
        $employeeStatusId = $this->optionalLookupId(
            EmployeeStatus::class,
            $this->stringValue($row['employeeStatusName'] ?? null),
            'employeeStatusName',
        );
        $employmentStatusId = $this->optionalLookupId(
            EmploymentStatus::class,
            $this->stringValue($row['employmentStatusName'] ?? null),
            'employmentStatusName',
        );
        $timesheetTemplateId = $this->optionalLookupId(
            TimesheetTemplate::class,
            $this->stringValue($row['timesheetTemplateName'] ?? null),
            'timesheetTemplateName',
        );

        $attributes = [
            'code' => $code,
            'honorificId' => $honorificId,
            'firstName' => $this->requireString($row, 'firstName', 'firstName is required.'),
            'middleName' => $this->nullableString($row['middleName'] ?? null),
            'lastName' => $this->requireString($row, 'lastName', 'lastName is required.'),
            'maidenName' => $this->nullableString($row['maidenName'] ?? null),
            'birthdate' => $this->requireString($row, 'birthdate', 'birthdate is required.'),
            'address1' => $this->requireString($row, 'address1', 'address1 is required.'),
            'address2' => $this->nullableString($row['address2'] ?? null),
            'localityId' => $localityId,
            'phone' => $this->nullableString($row['phone'] ?? null),
            'email' => $this->nullableString($row['email'] ?? null),
            'genderId' => $genderId,
            'socialSecurityNumber' => $this->requireString($row, 'socialSecurityNumber', 'socialSecurityNumber is required.'),
            'socialSecurityExpirationDate' => $this->nullableDate($row['socialSecurityExpirationDate'] ?? null),
            'taxIdentificationNumber' => $this->nullableString($row['taxIdentificationNumber'] ?? null),
            'passportNumber' => $this->nullableString($row['passportNumber'] ?? null),
            'votersId' => $this->nullableString($row['votersId'] ?? null),
            'citizenshipStatusId' => $citizenshipStatusId,
            'nationalityId' => $nationalityId,
            'payrateFrequencyId' => $payrateFrequencyId,
            'paymentMethodId' => $paymentMethodId,
            'employeeStatusId' => $employeeStatusId,
            'employmentStatusId' => $employmentStatusId,
            'timesheetTemplateId' => $timesheetTemplateId,
            'internalId1' => $this->nullableString($row['internalId1'] ?? null),
            'internalId2' => $this->nullableString($row['internalId2'] ?? null),
            'notes' => $this->nullableString($row['notes'] ?? null),
            'health' => $this->nullableString($row['health'] ?? null),
            'unionMembership' => $this->nullableString($row['unionMembership'] ?? null),
        ];

        return EmployeePersonSync::create($attributes);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createEmploymentFromRow(array $row): EmploymentDetail
    {
        $code = $this->requireString($row, 'employeeCode', 'employeeCode is required.');
        $employee = $this->findEmployeeByCode($code);

        $departmentId = $this->requireLookupId(
            Department::class,
            $this->requireString($row, 'departmentName', 'departmentName is required.'),
            'departmentName',
        );
        $worksiteId = $this->requireLookupId(
            Worksite::class,
            $this->requireString($row, 'worksiteName', 'worksiteName is required.'),
            'worksiteName',
        );
        $accountId = $this->requireAccountId(
            $this->requireString($row, 'accountCode', 'accountCode is required.'),
        );
        $contractTypeId = $this->requireLookupId(
            ContractType::class,
            $this->requireString($row, 'contractTypeName', 'contractTypeName is required.'),
            'contractTypeName',
        );
        $payPeriodGroupId = $this->requireLookupId(
            PayPeriodGroup::class,
            $this->requireString($row, 'payPeriodGroupName', 'payPeriodGroupName is required.'),
            'payPeriodGroupName',
        );

        return EmploymentDetail::create([
            'id' => (string) Str::uuid(),
            'employeeId' => $employee->id,
            'departmentId' => $departmentId,
            'worksiteId' => $worksiteId,
            'accountId' => $accountId,
            'contractTypeId' => $contractTypeId,
            'defaultPayPeriodGroupId' => $payPeriodGroupId,
            'startDate' => $this->requireString($row, 'startDate', 'startDate is required.'),
            'endDate' => $this->nullableDate($row['endDate'] ?? null),
            'jobTitle' => $this->nullableString($row['jobTitle'] ?? null),
            'requiresClocking' => $this->toBool($row['requiresClocking'] ?? false),
            'isActive' => $this->toBool($row['isActive'] ?? true, true),
            'benefits' => trim((string) ($row['benefits'] ?? '')),
            'employmentPolicies' => trim((string) ($row['employmentPolicies'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createCompensationFromRow(array $row): EmployeeCompensation
    {
        $code = $this->requireString($row, 'employeeCode', 'employeeCode is required.');
        $employee = $this->findEmployeeByCode($code);

        $employmentDetail = EmploymentDetail::query()
            ->where('employeeId', $employee->id)
            ->where('isActive', true)
            ->orderByDesc('startDate')
            ->first();

        if (!$employmentDetail) {
            throw new \RuntimeException("No active employment detail found for employee code: {$code}");
        }

        $methodRaw = strtoupper($this->requireString($row, 'compensationMethod', 'compensationMethod is required.'));
        $allowedMethods = array_merge(CompensationMethod::values(), [
            'HOURLY',
            'SALARY_NO_CLOCK',
            'BASE_SALARY',
            'WEEKLY_SALARY_OT',
            'WEEKLY_SALARY',
        ]);
        if (!in_array($methodRaw, $allowedMethods, true)) {
            throw new \RuntimeException("Invalid compensationMethod: {$methodRaw}");
        }
        $method = CompensationMethod::fromStored($methodRaw);

        $reasonType = strtoupper($this->requireString($row, 'reasonType', 'reasonType is required.'));
        if (!in_array($reasonType, CompensationReason::values(), true)) {
            throw new \RuntimeException("Invalid reasonType: {$reasonType}");
        }

        $isActive = $this->toBool($row['isActive'] ?? true, true);
        if ($isActive) {
            $exists = EmployeeCompensation::query()
                ->where('employmentDetailId', $employmentDetail->id)
                ->where('isActive', true)
                ->exists();

            if ($exists) {
                throw new \RuntimeException('This employment contract already has active compensation.');
            }
        }

        $payload = [
            'employeeId' => $employee->id,
            'employmentDetailId' => $employmentDetail->id,
            'effectiveDate' => $this->requireString($row, 'effectiveDate', 'effectiveDate is required.'),
            'endDate' => $this->nullableDate($row['endDate'] ?? null),
            'isActive' => $isActive,
            'compensationMethod' => $method->storedPayType(),
            'requiresClocking' => array_key_exists('requiresClocking', $row)
                ? $this->toBool($row['requiresClocking'])
                : $method->defaultRequiresClocking(),
            'hourlyRate' => $this->nullableFloat($row['hourlyRate'] ?? null),
            'yearlyRate' => $this->nullableFloat($row['yearlyRate'] ?? null),
            'standardWeeklyHours' => $this->nullableFloat($row['standardWeeklyHours'] ?? null)
                ?? EmployeeCompensationResolver::DEFAULT_STANDARD_WEEKLY_HOURS,
            'payscale' => $this->nullableString($row['payscale'] ?? null),
            'payscalePoint' => $this->nullableString($row['payscalePoint'] ?? null),
            'reasonType' => $reasonType,
            'reasonNote' => $this->nullableString($row['reasonNote'] ?? null),
        ];

        $payload = $this->normalizeCompensationRates($payload, $method);
        $this->assertCompensationRates($payload, $method);

        return EmployeeCompensation::create(array_merge($payload, [
            'id' => (string) Str::uuid(),
        ]));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createScheduledWorkFromRow(array $row): ScheduledWork
    {
        $code = $this->requireString($row, 'employeeCode', 'employeeCode is required.');
        $employee = $this->findEmployeeByCode($code);

        $departmentId = $this->optionalLookupId(
            Department::class,
            $this->stringValue($row['departmentName'] ?? null),
            'departmentName',
        );
        $worksiteId = $this->optionalLookupId(
            Worksite::class,
            $this->stringValue($row['worksiteName'] ?? null),
            'worksiteName',
        );

        $startDate = $this->requireString($row, 'startDate', 'startDate is required.');
        $endDate = $this->nullableDate($row['endDate'] ?? null) ?? $startDate;
        if ($endDate < $startDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $startTime = $this->normalizeTime($row['startTime'] ?? null) ?? '09:00';
        $endTime = $this->normalizeTime($row['endTime'] ?? null) ?? '17:00';

        $employmentDetailId = EmploymentDetail::query()
            ->where('employeeId', $employee->id)
            ->where('isActive', true)
            ->orderByDesc('startDate')
            ->value('id');

        $this->overlapValidator->validate(
            (string) $employee->id,
            $startDate,
            $endDate,
            $startTime,
            $endTime,
        );

        return ScheduledWork::create([
            'employeeId' => $employee->id,
            'employmentDetailId' => $employmentDetailId,
            'departmentId' => $departmentId,
            'worksiteId' => $worksiteId,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'description' => $this->requireString($row, 'description', 'description is required.'),
            'rate' => $this->nullableFloat($row['rate'] ?? null) ?? 1,
            'includeLunchHour' => $this->toBool($row['includeLunchHour'] ?? false),
            'lunchHourHours' => $this->nullableFloat($row['lunchHourHours'] ?? null) ?? 1,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createClockingLogFromRow(array $row): ClockingLog
    {
        $biometricUserId = $this->requireString($row, 'biometricUserId', 'biometricUserId is required.');
        $deviceId = $this->requireString($row, 'deviceId', 'deviceId is required.');
        $punchDateTime = $this->requireString($row, 'punchDateTime', 'punchDateTime is required.');
        $punchType = $this->nullableString($row['punchType'] ?? null);

        if ($punchType !== null) {
            $punchType = strtoupper($punchType);
            if (!in_array($punchType, ['IN', 'OUT'], true)) {
                throw new \RuntimeException("Invalid punchType: {$punchType}");
            }
        }

        return ClockingLog::create([
            'id' => (string) Str::uuid(),
            'biometricUserId' => $biometricUserId,
            'deviceId' => $deviceId,
            'punchDateTime' => $punchDateTime,
            'punchType' => $punchType,
        ]);
    }

    private function clockingLogsAvailable(): bool
    {
        try {
            return class_exists(ClockingLog::class)
                && Schema::hasTable((new ClockingLog())->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    private function findEmployeeByCode(string $code): Employee
    {
        $employee = Employee::query()->where('code', $code)->first();
        if (!$employee) {
            throw new \RuntimeException("Employee not found for code: {$code}");
        }

        return $employee;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeCompensationRates(array $payload, CompensationMethod $method): array
    {
        $hourlyRate = (float) ($payload['hourlyRate'] ?? 0);
        $yearlyRate = (float) ($payload['yearlyRate'] ?? 0);
        $standardWeeklyHours = (float) (
            $payload['standardWeeklyHours']
            ?? EmployeeCompensationResolver::DEFAULT_STANDARD_WEEKLY_HOURS
        );

        $payload['standardWeeklyHours'] = $standardWeeklyHours;
        $compensation = new EmployeeCompensation(['standardWeeklyHours' => $standardWeeklyHours]);

        if ($method->isHourlyBased()) {
            $payload['hourlyRate'] = $hourlyRate;
            $payload['yearlyRate'] = (float) (
                $this->compensationResolver->derivedYearlyRateFromHourly($hourlyRate, $compensation) ?? 0.0
            );
            $payload['requiresClocking'] = true;
        } else {
            if ($hourlyRate <= 0 && $yearlyRate > 0) {
                $hourlyRate = (float) (
                    $this->compensationResolver->derivedHourlyRateFromYearly($yearlyRate, $compensation) ?? 0.0
                );
            }
            $payload['hourlyRate'] = $hourlyRate;
            $payload['yearlyRate'] = $yearlyRate;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertCompensationRates(array $payload, CompensationMethod $method): void
    {
        $hourlyRate = (float) ($payload['hourlyRate'] ?? 0);
        $yearlyRate = (float) ($payload['yearlyRate'] ?? 0);
        $standardWeeklyHours = (float) ($payload['standardWeeklyHours'] ?? 0);

        if ($method->isHourlyBased() && $hourlyRate <= 0) {
            throw new \RuntimeException('Hourly rate is required for hourly pay methods.');
        }

        if ($method->isBaseBased() && $yearlyRate <= 0) {
            throw new \RuntimeException('Annual base rate is required for base-rate pay methods.');
        }

        if ($standardWeeklyHours <= 0) {
            throw new \RuntimeException('Standard weekly hours must be greater than zero.');
        }
    }

    /**
     * @param class-string $modelClass
     */
    private function requireLookupId(string $modelClass, string $name, string $label): mixed
    {
        $id = $this->optionalLookupId($modelClass, $name, $label);
        if ($id === null) {
            throw new \RuntimeException("Unknown {$label}: {$name}");
        }

        return $id;
    }

    /**
     * @param class-string $modelClass
     */
    private function optionalLookupId(string $modelClass, ?string $name, string $label): mixed
    {
        if ($name === null || $name === '') {
            return null;
        }

        $cacheKey = $modelClass.'|'.$label.'|'.mb_strtolower($name);
        if (array_key_exists($cacheKey, $this->lookupCache)) {
            $cached = $this->lookupCache[$cacheKey];
            if ($cached === null) {
                throw new \RuntimeException("Unknown {$label}: {$name}");
            }

            return $cached;
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new $modelClass();
        $record = $model::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (!$record) {
            $this->lookupCache[$cacheKey] = null;
            throw new \RuntimeException("Unknown {$label}: {$name}");
        }

        $this->lookupCache[$cacheKey] = $record->getKey();

        return $record->getKey();
    }

    private function requireAccountId(string $code): string
    {
        $cacheKey = Account::class.'|code1|'.mb_strtolower($code);
        if (array_key_exists($cacheKey, $this->lookupCache)) {
            $cached = $this->lookupCache[$cacheKey];
            if ($cached === null) {
                throw new \RuntimeException("Unknown accountCode: {$code}");
            }

            return (string) $cached;
        }

        $account = Account::query()
            ->whereRaw('LOWER(code1) = ?', [mb_strtolower($code)])
            ->first();

        if (!$account) {
            $this->lookupCache[$cacheKey] = null;
            throw new \RuntimeException("Unknown accountCode: {$code}");
        }

        $this->lookupCache[$cacheKey] = $account->id;

        return (string) $account->id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requireString(array $row, string $key, string $message): string
    {
        $value = $this->stringValue($row[$key] ?? null);
        if ($value === null) {
            throw new \RuntimeException($message);
        }

        return $value;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableString(mixed $value): ?string
    {
        return $this->stringValue($value);
    }

    private function nullableDate(mixed $value): ?string
    {
        $string = $this->stringValue($value);

        return $string;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \RuntimeException('Expected a numeric value.');
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

    private function normalizeTime(mixed $value): ?string
    {
        $string = $this->stringValue($value);
        if ($string === null) {
            return null;
        }

        // Accept HH:mm or HH:mm:ss
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $string) === 1) {
            $parts = explode(':', $string);

            return sprintf('%02d:%02d', (int) $parts[0], (int) $parts[1]);
        }

        try {
            return \Carbon\Carbon::parse($string)->format('H:i');
        } catch (Throwable) {
            throw new \RuntimeException("Invalid time value: {$string}");
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->stringValue($value) !== null) {
                return false;
            }
        }

        return true;
    }

    private function exceptionMessage(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $messages = collect($e->errors())->flatten()->filter()->values();

            return $messages->isNotEmpty()
                ? $messages->implode(' ')
                : ($e->getMessage() ?: 'Validation failed.');
        }

        $message = trim($e->getMessage());

        return $message !== '' ? $message : 'Unknown import error.';
    }

    /**
     * @return array{sheet: string, row: int|null, code: string|null, message: string}
     */
    private function error(string $sheet, ?int $row, ?string $code, string $message): array
    {
        return [
            'sheet' => $sheet,
            'row' => $row,
            'code' => $code,
            'message' => $message !== '' ? $message : 'Unknown import error.',
        ];
    }
}
