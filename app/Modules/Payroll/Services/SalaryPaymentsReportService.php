<?php

namespace App\Modules\Payroll\Services;

use App\Enums\CompensationMethod;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmploymentDetail;
use App\Models\PayrollEarningCode;
use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use App\Models\PayrollRunPoolDistribution;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\PublicHolidayPayResolver;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SalaryPaymentsReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const SORT_DEPARTMENT = 'department';

    public const SORT_LAST_NAME = 'lastName';

    public const SORT_FIRST_NAME = 'firstName';

    public const SORT_EMPLOYEE_CODE = 'employeeCode';

    public const CATEGORY_EMPLOYEE = 'Employee';

    public const CATEGORY_DEPARTMENT = 'Department';

    public const CATEGORY_CONTRACT = 'Contract';

    public const CATEGORY_COMPENSATION = 'Compensation';

    public const CATEGORY_EARNINGS = 'Earnings';

    /**
     * @return list<string>
     */
    public static function sortKeys(): array
    {
        return [
            self::SORT_DEPARTMENT,
            self::SORT_LAST_NAME,
            self::SORT_FIRST_NAME,
            self::SORT_EMPLOYEE_CODE,
        ];
    }

    public function __construct(
        private readonly EmployeeCompensationResolver $compensationResolver,
        private readonly TimesheetEarningAllocator $earningAllocator,
        private readonly PublicHolidayPayResolver $holidayPayResolver,
    ) {
    }

    /**
     * @param list<string> $sortBy
     * @return array<string, mixed>
     */
    public function build(
        Carbon $startDate,
        Carbon $endDate,
        string $scope,
        ?int $departmentId = null,
        array $sortBy = [],
    ): array {
        $scope = strtolower(trim($scope));
        if (! in_array($scope, [self::SCOPE_COMPANY, self::SCOPE_DEPARTMENT], true)) {
            throw new InvalidArgumentException('Scope must be company or department.');
        }
        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }
        if ($scope === self::SCOPE_DEPARTMENT && ($departmentId === null || $departmentId <= 0)) {
            throw new InvalidArgumentException('Department is required when generating by department.');
        }

        $sortBy = $this->normalizeSortBy($sortBy);
        $earningCodes = PayrollEarningCode::query()
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);
        $holidayMultipliers = $this->holidayPayResolver->multipliersForRange($startDate, $endDate);

        $timesheets = $this->loadTimesheets($startDate, $endDate, $scope, $departmentId);
        $employeeIds = $timesheets->pluck('employeeId')->map(fn ($id) => (string) $id)->unique()->values();
        $employees = $employeeIds->isEmpty()
            ? collect()
            : Employee::query()
                ->with([
                    'person',
                    'employmentStatus:id,name',
                    'employeeStatus:id,name',
                    'paymentMethod:id,name',
                    'payrateFrequency:id,name',
                    'employmentDetails.department.parent',
                    'employmentDetails.jobTitle:id,name',
                    'employmentDetails.contractType:id,name',
                    'employmentDetails.worksite:id,name',
                ])
                ->whereIn('id', $employeeIds)
                ->get()
                ->keyBy('id');
        $compensationsByEmployee = $employeeIds->isEmpty()
            ? collect()
            : EmployeeCompensation::query()
                ->whereIn('employeeId', $employeeIds)
                ->orderByDesc('effectiveDate')
                ->get()
                ->groupBy('employeeId');

        $rows = [];
        foreach ($employeeIds as $employeeId) {
            $employee = $employees->get($employeeId);
            if (! $employee) {
                continue;
            }

            $employeeTimesheets = $timesheets
                ->filter(fn (Timesheet $timesheet) => (string) $timesheet->employeeId === $employeeId)
                ->values();
            $rows[$employeeId] = $this->buildEmployeeRow(
                $employee,
                $employeeTimesheets,
                $compensationsByEmployee->get($employeeId, collect()),
                $earningCodes,
                $holidayMultipliers,
                $otMultiplier,
                $endDate,
            );
        }

        $payrollRunIds = $this->overlappingPostedPayrollRunIds($startDate, $endDate);
        $this->applyPostedEarnings($rows, $payrollRunIds, $earningCodes, $scope, $departmentId);
        $poolColumns = $this->applyPoolDistributions($rows, $payrollRunIds, $earningCodes, $scope, $departmentId);

        $columns = self::describeColumns($earningCodes, $poolColumns, $this->usedPayKeys($rows));
        $grossColumn = array_pop($columns);
        foreach ($this->syntheticColumns($rows) as $column) {
            $columns[] = $column;
        }
        $columns[] = $grossColumn;

        foreach ($rows as &$row) {
            $row['values']['gross'] = $this->gross($row['values'], $columns);
        }
        unset($row);

        $rowList = array_map(fn (array $row) => $this->publicRow($row), array_values($rows));
        usort($rowList, fn (array $left, array $right) => $this->compareRows($left, $right, $sortBy));

        $departments = $this->groupByDepartment($rowList, $columns);

        return [
            'title' => 'Salary Payments',
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'scope' => $scope,
            'scopeLabel' => $this->scopeLabel($scope, $departmentId),
            'departmentId' => $scope === self::SCOPE_DEPARTMENT ? $departmentId : null,
            'sortBy' => $sortBy,
            'columns' => $columns,
            'departments' => $departments,
            'rows' => $rowList,
            'totals' => $this->totals($rowList, $columns),
            'employeeCount' => count($rowList),
        ];
    }

    /**
     * @param Collection<int, PayrollEarningCode> $earningCodes
     * @param list<array<string, mixed>> $poolColumns
     * @param list<string> $usedPayKeys
     * @return list<array<string, mixed>>
     */
    public static function describeColumns(Collection $earningCodes, array $poolColumns = [], array $usedPayKeys = []): array
    {
        $used = array_fill_keys($usedPayKeys, true);
        $columns = self::profileColumns();

        foreach ($earningCodes as $code) {
            $payKey = 'earning:'.$code->id.':pay';
            if (! $code->is_active && ! isset($used[$payKey])) {
                continue;
            }
            foreach (self::earningCodeColumns($code) as $column) {
                if (isset($used[$column['key']]) && ($column['type'] ?? '') === 'money') {
                    $column['defaultVisible'] = true;
                }
                $columns[] = $column;
            }
        }

        foreach ($poolColumns as $column) {
            $columns[] = $column;
        }

        $columns[] = [
            'key' => 'gross',
            'label' => 'Gross',
            'category' => self::CATEGORY_EARNINGS,
            'group' => null,
            'type' => 'money',
            'defaultVisible' => true,
            'total' => true,
        ];

        return $columns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function profileColumns(): array
    {
        return [
            self::textColumn('employeeCode', 'Code', self::CATEGORY_EMPLOYEE),
            self::textColumn('firstName', 'First name', self::CATEGORY_EMPLOYEE),
            self::textColumn('middleName', 'Middle name', self::CATEGORY_EMPLOYEE),
            self::textColumn('lastName', 'Last name', self::CATEGORY_EMPLOYEE),
            self::textColumn('email', 'Email', self::CATEGORY_EMPLOYEE),
            self::textColumn('phone', 'Phone', self::CATEGORY_EMPLOYEE),
            self::textColumn('employmentStatus', 'Employment status', self::CATEGORY_EMPLOYEE),
            self::textColumn('employeeStatus', 'Employee status', self::CATEGORY_EMPLOYEE),
            self::textColumn('paymentMethod', 'Payment method', self::CATEGORY_EMPLOYEE),
            self::textColumn('payFrequency', 'Pay frequency', self::CATEGORY_EMPLOYEE),
            self::textColumn('department', 'Department', self::CATEGORY_DEPARTMENT),
            self::textColumn('parentDepartment', 'Parent department', self::CATEGORY_DEPARTMENT),
            self::textColumn('jobTitle', 'Job title', self::CATEGORY_CONTRACT),
            self::textColumn('contractType', 'Contract type', self::CATEGORY_CONTRACT),
            self::textColumn('contractStart', 'Contract start', self::CATEGORY_CONTRACT, 'date'),
            self::textColumn('contractEnd', 'Contract end', self::CATEGORY_CONTRACT, 'date'),
            self::textColumn('worksite', 'Worksite', self::CATEGORY_CONTRACT),
            self::textColumn('compensationMethod', 'Compensation method', self::CATEGORY_COMPENSATION),
            self::numberColumn('hourlyRate', 'Hourly rate', self::CATEGORY_COMPENSATION, 'money'),
            self::numberColumn('dailyRate', 'Daily rate', self::CATEGORY_COMPENSATION, 'money'),
            self::numberColumn('yearlyRate', 'Yearly rate', self::CATEGORY_COMPENSATION, 'money'),
            self::numberColumn('weeklyHours', 'Weekly hours', self::CATEGORY_COMPENSATION, 'hours'),
        ];
    }

    /**
     * @param list<string> $sortBy
     * @return list<string>
     */
    private function normalizeSortBy(array $sortBy): array
    {
        $allowed = self::sortKeys();
        $normalized = [];
        foreach ($sortBy as $key) {
            $key = (string) $key;
            if (in_array($key, $allowed, true) && ! in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        return $normalized !== [] ? $normalized : [self::SORT_DEPARTMENT, self::SORT_LAST_NAME];
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     * @param Collection<int, EmployeeCompensation> $compensations
     * @param Collection<int, PayrollEarningCode> $earningCodes
     * @param array<string, float> $holidayMultipliers
     * @return array<string, mixed>
     */
    private function buildEmployeeRow(
        Employee $employee,
        Collection $timesheets,
        Collection $compensations,
        Collection $earningCodes,
        array $holidayMultipliers,
        float $otMultiplier,
        Carbon $asOfDate,
    ): array {
        $values = $this->emptyProfileValues();
        $hoursByDepartment = [];
        $compensation = $this->compensationResolver->compensationForDate($compensations, $asOfDate);
        $standardDailyHours = $this->compensationResolver->standardWeeklyHours($compensation) / 5;
        $dailyRate = (float) ($this->compensationResolver->effectiveDailyRate($compensation) ?? 0);
        $codesByKey = [];
        foreach ($earningCodes as $code) {
            $codesByKey['earning:'.$code->id] = $code;
        }

        foreach ($timesheets as $timesheet) {
            $rate = $this->hourlyRateForTimesheet($timesheet, $compensations);
            $dateKey = $timesheet->date ? Carbon::parse($timesheet->date)->toDateString() : '';
            $allocations = $this->earningAllocator->allocate(
                $timesheet,
                $earningCodes,
                $dateKey !== '' ? ($holidayMultipliers[$dateKey] ?? null) : null,
                $rate,
                $dailyRate,
                $standardDailyHours,
                $otMultiplier,
            );

            $departmentKey = $timesheet->departmentId !== null ? (int) $timesheet->departmentId : 0;
            $departmentName = trim((string) ($timesheet->department?->name ?? ''));
            foreach ($allocations as $allocation) {
                $this->addAllocation($values, $allocation, $codesByKey);
                $hoursByDepartment[$departmentKey]['hours'] = ($hoursByDepartment[$departmentKey]['hours'] ?? 0) + $allocation['hours'];
                $hoursByDepartment[$departmentKey]['name'] = $departmentName !== ''
                    ? $departmentName
                    : ($hoursByDepartment[$departmentKey]['name'] ?? '');
            }
        }

        $department = $this->primaryDepartment($hoursByDepartment, $employee, $asOfDate);
        $detail = $this->employmentDetailForDate($employee->employmentDetails, $asOfDate);
        $compensation = $this->compensationResolver->compensationForDate(
            $compensations,
            $asOfDate,
            $detail?->id ? (string) $detail->id : null,
        );
        $person = $employee->person;
        $firstName = trim((string) ($person?->firstName ?? ''));
        $middleName = trim((string) ($person?->middleName ?? ''));
        $lastName = trim((string) ($person?->lastName ?? ''));
        $employeeName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName])));
        $this->fillProfile($values, $employee, $department, $detail, $compensation, $firstName, $middleName, $lastName);

        return [
            'employeeId' => (string) $employee->id,
            'employeeCode' => (string) ($employee->code ?? ''),
            'firstName' => $firstName,
            'lastName' => $lastName,
            'employeeName' => $employeeName !== '' ? $employeeName : 'Unknown employee',
            'displayName' => $this->displayName((string) ($employee->code ?? ''), $employeeName),
            'departmentId' => $department['id'],
            'departmentName' => $department['name'],
            'values' => $values,
            'postedCodes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param array{key: string, hours: float, amount: float} $allocation
     * @param array<string, PayrollEarningCode> $codesByKey
     */
    private function addAllocation(array &$values, array $allocation, array $codesByKey): void
    {
        $code = $codesByKey[$allocation['key']] ?? null;
        $baseKey = $code ? 'earning:'.$code->id : 'synthetic:'.$allocation['key'];
        $this->addEarningAmount($values, $baseKey, (float) $allocation['hours'], (float) $allocation['amount']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function addEarningAmount(array &$values, string $baseKey, float $hours, float $amount, bool $replace = false): void
    {
        $hoursKey = $baseKey.':hours';
        $payKey = $baseKey.':pay';
        if ($replace) {
            $values[$payKey] = round($amount, 2);
            if ($hours > 0) {
                $values[$hoursKey] = round($hours, 2);
            }

            return;
        }

        $values[$hoursKey] = round((float) ($values[$hoursKey] ?? 0) + $hours, 2);
        $values[$payKey] = round((float) ($values[$payKey] ?? 0) + $amount, 2);
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @param Collection<int, string> $payrollRunIds
     * @param Collection<int, PayrollEarningCode> $earningCodes
     */
    private function applyPostedEarnings(
        array &$rows,
        Collection $payrollRunIds,
        Collection $earningCodes,
        string $scope,
        ?int $departmentId,
    ): void {
        if ($payrollRunIds->isEmpty() || $rows === []) {
            return;
        }

        $codesById = $earningCodes->keyBy('id');
        $query = PayrollEarningLine::query()
            ->whereIn('payroll_run_id', $payrollRunIds)
            ->whereIn('employeeId', array_keys($rows))
            ->whereRaw("UPPER(COALESCE(source_type, '')) <> 'POOL'");
        if ($scope === self::SCOPE_DEPARTMENT && $departmentId !== null) {
            $query->where('departmentId', $departmentId);
        }

        $lines = $query
            ->selectRaw('"employeeId", payroll_earning_code_id, SUM(COALESCE(hours, 0)) as total_hours, SUM(amount) as total_amount')
            ->groupBy('employeeId', 'payroll_earning_code_id')
            ->get();

        foreach ($lines as $line) {
            $employeeId = (string) $line->employeeId;
            if (! isset($rows[$employeeId])) {
                continue;
            }
            $code = $codesById->get((int) $line->payroll_earning_code_id);
            if (! $code) {
                continue;
            }
            $amount = round((float) ($line->total_amount ?? 0), 2);
            $hours = round((float) ($line->total_hours ?? 0), 2);
            if ($amount == 0.0 && $hours == 0.0) {
                continue;
            }

            $baseKey = 'earning:'.$code->id;
            $replacesTimesheet = in_array($code->source, [
                PayrollEarningCode::SOURCE_TIMESHEET_REGULAR,
                PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME,
                PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
                PayrollEarningCode::SOURCE_VACATION,
            ], true);
            $this->addEarningAmount(
                $rows[$employeeId]['values'],
                $baseKey,
                $hours,
                $amount,
                $replacesTimesheet,
            );
            $rows[$employeeId]['postedCodes'][$code->id] = true;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @param Collection<int, string> $payrollRunIds
     * @param Collection<int, PayrollEarningCode> $earningCodes
     * @return list<array<string, mixed>>
     */
    private function applyPoolDistributions(
        array &$rows,
        Collection $payrollRunIds,
        Collection $earningCodes,
        string $scope,
        ?int $departmentId,
    ): array {
        if ($payrollRunIds->isEmpty() || $rows === []) {
            return [];
        }

        $query = PayrollRunPoolDistribution::query()
            ->with('poolDistributionType:id,code,name')
            ->whereIn('payroll_run_id', $payrollRunIds)
            ->whereIn('employee_id', array_keys($rows))
            ->where('amount', '>', 0);
        if ($scope === self::SCOPE_DEPARTMENT && $departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        $poolColumns = [];
        foreach ($query->get() as $distribution) {
            $employeeId = (string) $distribution->employee_id;
            if (! isset($rows[$employeeId])) {
                continue;
            }
            $type = $distribution->poolDistributionType;
            $matched = $this->matchEarningCode($earningCodes, (string) ($type?->code ?? ''), (string) ($type?->name ?? ''));
            if ($matched) {
                $this->addEarningAmount($rows[$employeeId]['values'], 'earning:'.$matched->id, 0, (float) $distribution->amount);
                continue;
            }

            $typeId = (string) ($distribution->pool_distribution_type_id ?? 'unknown');
            $key = 'pool:'.$typeId;
            $rows[$employeeId]['values'][$key] = round(
                (float) ($rows[$employeeId]['values'][$key] ?? 0) + (float) $distribution->amount,
                2,
            );
            $poolColumns[$key] = [
                'key' => $key,
                'label' => trim((string) ($type?->name ?? '')) !== '' ? (string) $type->name : 'Pool '.$typeId,
                'category' => self::CATEGORY_EARNINGS,
                'group' => null,
                'type' => 'money',
                'defaultVisible' => true,
                'total' => true,
            ];
        }

        return array_values($poolColumns);
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return list<string>
     */
    private function usedPayKeys(array $rows): array
    {
        $used = [];
        foreach ($rows as $row) {
            foreach ($row['values'] as $key => $amount) {
                if (! is_string($key) || ! str_ends_with($key, ':pay')) {
                    continue;
                }
                if (abs((float) $amount) > 0.001) {
                    $used[$key] = true;
                }
            }
        }

        return array_keys($used);
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function syntheticColumns(array $rows): array
    {
        $columns = [];
        foreach ($this->usedPayKeys($rows) as $payKey) {
            if (! str_starts_with($payKey, 'synthetic:')) {
                continue;
            }
            $base = substr($payKey, 0, -4);
            $token = substr($base, strlen('synthetic:'));
            $label = str_starts_with($token, 'holiday:')
                ? $this->holidayLabel((float) substr($token, 8))
                : ucfirst(str_replace(['_', ':'], ' ', $token));
            $columns[$base.':hours'] = [
                'key' => $base.':hours',
                'label' => 'Hours',
                'category' => self::CATEGORY_EARNINGS,
                'group' => $label,
                'type' => 'hours',
                'defaultVisible' => true,
                'total' => true,
            ];
            $columns[$payKey] = [
                'key' => $payKey,
                'label' => 'Pay',
                'category' => self::CATEGORY_EARNINGS,
                'group' => $label,
                'type' => 'money',
                'defaultVisible' => true,
                'total' => true,
            ];
        }

        return array_values($columns);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<array<string, mixed>> $columns
     */
    private function gross(array $values, array $columns): float
    {
        $total = 0.0;
        foreach ($columns as $column) {
            if (($column['type'] ?? '') !== 'money' || empty($column['total']) || $column['key'] === 'gross') {
                continue;
            }
            $total += (float) ($values[$column['key']] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    private function groupByDepartment(array $rows, array $columns): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['departmentId'] === null ? 'unassigned' : (string) $row['departmentId'];
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'departmentId' => $row['departmentId'],
                    'departmentName' => $row['departmentName'],
                    'rows' => [],
                ];
            }
            $groups[$key]['rows'][] = $row;
        }

        $list = [];
        foreach ($groups as $group) {
            $group['totals'] = $this->totals($group['rows'], $columns);
            $group['employeeCount'] = count($group['rows']);
            $list[] = $group;
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRow(array $row): array
    {
        unset($row['postedCodes']);
        foreach ($row['values'] as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $row['values'][$key] = round((float) $value, 2);
            }
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $columns
     * @return array<string, float>
     */
    private function totals(array $rows, array $columns): array
    {
        $totals = [];
        foreach ($columns as $column) {
            if (empty($column['total'])) {
                continue;
            }
            $totals[$column['key']] = 0.0;
        }
        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + (float) ($row['values'][$key] ?? 0), 2);
            }
        }

        return $totals;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @param list<string> $sortBy
     */
    private function compareRows(array $left, array $right, array $sortBy): int
    {
        foreach ($sortBy as $key) {
            $comparison = $this->sortValue($left, $key) <=> $this->sortValue($right, $key);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return $this->sortValue($left, self::SORT_EMPLOYEE_CODE) <=> $this->sortValue($right, self::SORT_EMPLOYEE_CODE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sortValue(array $row, string $key): string
    {
        $value = match ($key) {
            self::SORT_DEPARTMENT => (string) ($row['departmentName'] ?? ''),
            self::SORT_LAST_NAME => (string) ($row['lastName'] ?? ''),
            self::SORT_FIRST_NAME => (string) ($row['firstName'] ?? ''),
            default => (string) ($row['employeeCode'] ?? ''),
        };

        return mb_strtolower(trim($value));
    }

    /**
     * @param array<int, array{hours?: float, name?: string}> $hoursByDepartment
     * @return array{id: int|null, name: string, parentName: string}
     */
    private function primaryDepartment(array $hoursByDepartment, Employee $employee, Carbon $asOfDate): array
    {
        if ($hoursByDepartment !== []) {
            uasort($hoursByDepartment, fn (array $left, array $right) => ($right['hours'] ?? 0) <=> ($left['hours'] ?? 0));
            $departmentId = (int) array_key_first($hoursByDepartment);
            if ($departmentId > 0) {
                $department = Department::query()->with('parent:id,name')->find($departmentId);
                $name = trim((string) ($department?->name ?? $hoursByDepartment[$departmentId]['name'] ?? ''));

                return [
                    'id' => $departmentId,
                    'name' => $name !== '' ? $name : 'Unassigned',
                    'parentName' => trim((string) ($department?->parent?->name ?? '')),
                ];
            }
        }

        $detail = $this->employmentDetailForDate($employee->employmentDetails, $asOfDate);
        $departmentId = $detail?->departmentId !== null ? (int) $detail->departmentId : null;
        $name = trim((string) ($detail?->department?->name ?? ''));

        return [
            'id' => $departmentId,
            'name' => $name !== '' ? $name : 'Unassigned',
            'parentName' => trim((string) ($detail?->department?->parent?->name ?? '')),
        ];
    }

    /**
     * @param Collection<int, EmploymentDetail>|mixed $details
     */
    private function employmentDetailForDate(mixed $details, Carbon $date): ?EmploymentDetail
    {
        $collection = $details instanceof Collection ? $details : collect();
        $matching = $collection->filter(function (EmploymentDetail $detail) use ($date) {
            if (! $detail->startDate) {
                return false;
            }
            $startDate = Carbon::parse($detail->startDate)->startOfDay();
            $endDate = $detail->endDate ? Carbon::parse($detail->endDate)->startOfDay() : null;

            return $startDate->lte($date) && (! $endDate || $endDate->gte($date));
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
     * @param array<string, mixed> $values
     * @param array{id: int|null, name: string, parentName: string} $department
     */
    private function fillProfile(
        array &$values,
        Employee $employee,
        array $department,
        ?EmploymentDetail $detail,
        ?EmployeeCompensation $compensation,
        string $firstName,
        string $middleName,
        string $lastName,
    ): void {
        $person = $employee->person;
        $values['employeeCode'] = (string) ($employee->code ?? '');
        $values['firstName'] = $firstName;
        $values['middleName'] = $middleName;
        $values['lastName'] = $lastName;
        $values['email'] = trim((string) ($person?->email ?? ''));
        $values['phone'] = trim((string) ($person?->phone ?? ''));
        $values['employmentStatus'] = trim((string) ($employee->employmentStatus?->name ?? ''));
        $values['employeeStatus'] = trim((string) ($employee->employeeStatus?->name ?? ''));
        $values['paymentMethod'] = trim((string) ($employee->paymentMethod?->name ?? ''));
        $values['payFrequency'] = trim((string) ($employee->payrateFrequency?->name ?? ''));
        $values['department'] = $department['name'];
        $values['parentDepartment'] = $department['parentName'];
        $values['jobTitle'] = trim((string) ($detail?->jobTitle?->name ?? ''));
        $values['contractType'] = trim((string) ($detail?->contractType?->name ?? ''));
        $values['contractStart'] = $detail?->startDate ? Carbon::parse($detail->startDate)->toDateString() : '';
        $values['contractEnd'] = $detail?->endDate ? Carbon::parse($detail->endDate)->toDateString() : '';
        $values['worksite'] = trim((string) ($detail?->worksite?->name ?? ''));
        $values['compensationMethod'] = $compensation ? $this->compensationMethodLabel($compensation->compensationMethod) : '';
        $values['hourlyRate'] = round((float) ($compensation?->hourlyRate ?? 0), 2);
        $values['dailyRate'] = round((float) ($compensation?->dailyRate ?? 0), 2);
        $values['yearlyRate'] = round((float) ($compensation?->yearlyRate ?? 0), 2);
        $values['weeklyHours'] = round((float) ($compensation?->standardWeeklyHours ?? 0), 2);
    }

    private function compensationMethodLabel(?string $stored): string
    {
        return match (CompensationMethod::fromStored($stored)) {
            CompensationMethod::HourlyNoOt => 'Hourly (no overtime)',
            CompensationMethod::HourlyOt => 'Hourly (with overtime)',
            CompensationMethod::BaseNoOt => 'Base rate (no overtime)',
            CompensationMethod::BaseOt => 'Base rate (with overtime)',
            CompensationMethod::DailyRate => 'Daily / trip rate',
        };
    }

    /**
     * @param Collection<int, PayrollEarningCode> $earningCodes
     */
    private function matchEarningCode(Collection $earningCodes, string $code, string $name): ?PayrollEarningCode
    {
        $normalizedCode = strtoupper(trim($code));
        $normalizedName = strtolower(trim($name));
        if ($normalizedCode === '' && $normalizedName === '') {
            return null;
        }

        $exact = $earningCodes->first(function (PayrollEarningCode $earning) use ($normalizedCode, $normalizedName) {
            return ($normalizedCode !== '' && strtoupper((string) $earning->code) === $normalizedCode)
                || ($normalizedName !== '' && strtolower((string) $earning->name) === $normalizedName);
        });
        if ($exact) {
            return $exact;
        }

        return $earningCodes->first(function (PayrollEarningCode $earning) use ($normalizedCode, $normalizedName) {
            $earningCode = strtoupper((string) $earning->code);
            $earningName = strtolower((string) $earning->name);

            return ($normalizedCode !== '' && ($earningCode === $normalizedCode || str_contains($earningName, strtolower($normalizedCode))))
                || ($normalizedName !== '' && str_contains($earningName, $normalizedName));
        });
    }

    private function holidayLabel(float $multiplier): string
    {
        if (abs($multiplier - 1.5) < 0.001) {
            return 'Holiday Pay (1.5 time)';
        }
        if (abs($multiplier - 2.0) < 0.001) {
            return 'Holiday Pay (double time)';
        }
        if (abs($multiplier - 3.0) < 0.001) {
            return 'Holiday Pay (triple time)';
        }

        return 'Holiday Pay ('.$multiplier.' time)';
    }

    /**
     * @return array<string, mixed>
     */
    private static function textColumn(string $key, string $label, string $category, string $type = 'text'): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'group' => null,
            'type' => $type,
            'defaultVisible' => false,
            'total' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function numberColumn(string $key, string $label, string $category, string $type): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'category' => $category,
            'group' => null,
            'type' => $type,
            'defaultVisible' => false,
            'total' => false,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function earningCodeColumns(PayrollEarningCode $code): array
    {
        $tracksHours = in_array($code->source, [
            PayrollEarningCode::SOURCE_TIMESHEET_REGULAR,
            PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME,
            PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
            PayrollEarningCode::SOURCE_VACATION,
        ], true);
        $columns = [];
        if ($tracksHours) {
            $columns[] = [
                'key' => 'earning:'.$code->id.':hours',
                'label' => 'Hours',
                'category' => self::CATEGORY_EARNINGS,
                'group' => (string) $code->name,
                'type' => 'hours',
                'defaultVisible' => true,
                'total' => true,
            ];
        }
        $columns[] = [
            'key' => 'earning:'.$code->id.':pay',
            'label' => $tracksHours ? 'Pay' : (string) $code->name,
            'category' => self::CATEGORY_EARNINGS,
            'group' => $tracksHours ? (string) $code->name : null,
            'type' => 'money',
            'defaultVisible' => $tracksHours,
            'total' => true,
        ];

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProfileValues(): array
    {
        $values = [];
        foreach (self::profileColumns() as $column) {
            $values[$column['key']] = in_array($column['type'], ['money', 'hours', 'number'], true) ? 0.0 : '';
        }

        return $values;
    }

    private function displayName(string $code, string $name): string
    {
        $name = $name !== '' ? $name : 'Unknown employee';
        $code = trim($code);

        return $code !== '' ? $code.' - '.$name : $name;
    }

    private function loadTimesheets(Carbon $startDate, Carbon $endDate, string $scope, ?int $departmentId): Collection
    {
        $query = Timesheet::query()
            ->with(['department:id,name'])
            ->whereDate('date', '>=', $startDate->toDateString())
            ->whereDate('date', '<=', $endDate->toDateString())
            ->whereRaw("UPPER(COALESCE(\"approvalStatus\", '')) NOT IN (?, ?, ?)", ['REJECTED', 'DENIED', 'CANCELLED']);

        if ($scope === self::SCOPE_DEPARTMENT && $departmentId !== null) {
            $query->where('departmentId', $departmentId);
        }

        return $query->orderBy('date')->get();
    }

    /**
     * @return Collection<int, string>
     */
    private function overlappingPostedPayrollRunIds(Carbon $startDate, Carbon $endDate): Collection
    {
        return PayrollRun::query()
            ->whereRaw('LOWER(COALESCE(status, \'\')) = ?', ['posted'])
            ->whereHas('payPeriodSchedule', function ($query) use ($startDate, $endDate) {
                $query
                    ->whereDate('start_date', '<=', $endDate->toDateString())
                    ->whereDate('end_date', '>=', $startDate->toDateString());
            })
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values();
    }

    /**
     * @param Collection<int, EmployeeCompensation> $compensations
     */
    private function hourlyRateForTimesheet(Timesheet $timesheet, Collection $compensations): float
    {
        $hourlyRate = (float) ($timesheet->hourlyRate ?? 0);
        if ($hourlyRate > 0) {
            return $hourlyRate;
        }

        $baseSalary = (float) ($timesheet->baseSalary ?? 0);
        if ($baseSalary > 0) {
            return $this->compensationResolver->hourlyRateFromBaseSalary($baseSalary);
        }

        $date = $timesheet->date ? Carbon::parse($timesheet->date) : Carbon::today();
        $compensation = $this->compensationResolver->compensationForDate(
            $compensations,
            $date,
            $timesheet->employmentDetailId ? (string) $timesheet->employmentDetailId : null,
        );

        return (float) ($this->compensationResolver->effectiveHourlyRate($compensation) ?? 0);
    }

    private function scopeLabel(string $scope, ?int $departmentId): string
    {
        if ($scope === self::SCOPE_DEPARTMENT) {
            $name = Department::query()->whereKey($departmentId)->value('name');

            return $name ? "Department — {$name}" : 'Department';
        }

        return 'Entire company';
    }
}
