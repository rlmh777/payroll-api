<?php

namespace App\Modules\Payroll\Services;

use App\Enums\CompensationMethod;
use App\Enums\PayrollItemOccurrence;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeDefaultAllowance;
use App\Models\EmployeeDefaultDeduction;
use App\Models\EmployeeGroup;
use App\Models\EmployeeGroupMember;
use App\Models\EmployeePoolPoint;
use App\Models\PayrollEarningCode;
use App\Models\PoolDistributionType;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use App\Services\SocialSecurity\SocialSecurityContributionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class LaborCostToDateReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_GROUP = 'group';

    private const GRATUITY_KEY = 'gratuity';

    private const GRATUITY_POINTS_KEY = 'gratuityPoints';

    public function __construct(
        private readonly SocialSecurityContributionService $socialSecurityContributionService,
        private readonly IncomeTaxCalculationService $incomeTaxCalculationService,
        private readonly EmployeeCompensationResolver $compensationResolver,
        private readonly PayrollFlatBaseScopeService $payrollFlatBaseScopeService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        Carbon $startDate,
        Carbon $endDate,
        string $scope,
        ?int $departmentId = null,
        ?string $employeeGroupId = null,
    ): array {
        $scope = strtolower(trim($scope));
        if (! in_array($scope, [self::SCOPE_COMPANY, self::SCOPE_DEPARTMENT, self::SCOPE_GROUP], true)) {
            throw new InvalidArgumentException('Scope must be company, department, or group.');
        }
        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }
        if ($scope === self::SCOPE_DEPARTMENT && ($departmentId === null || $departmentId <= 0)) {
            throw new InvalidArgumentException('Department is required when generating by department.');
        }
        if ($scope === self::SCOPE_GROUP && ! filled($employeeGroupId)) {
            throw new InvalidArgumentException('Employee group is required when generating by group.');
        }

        $earningCodes = PayrollEarningCode::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $timesheets = $this->loadTimesheets($startDate, $endDate, $scope, $departmentId);
        $employeeIds = $timesheets->pluck('employeeId')->map(fn ($id) => (string) $id)->unique()->values();

        if ($scope === self::SCOPE_GROUP) {
            $memberIds = $this->groupMemberIds((string) $employeeGroupId, $endDate);
            $employeeIds = $employeeIds->intersect($memberIds)->values();
            $timesheets = $timesheets->filter(
                fn (Timesheet $timesheet) => $memberIds->contains((string) $timesheet->employeeId),
            );
        }

        $employees = $employeeIds->isEmpty()
            ? collect()
            : Employee::query()
                ->with(['person', 'payrateFrequency'])
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

        $allowancesByEmployee = $this->loadDefaultAllowances($employeeIds);
        $gratuityType = $this->resolveGratuityPoolType();
        $gratuityPointsByEmployee = $this->loadGratuityPoints($employeeIds, $endDate, $gratuityType);
        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);
        $mondays = PayPeriodHelper::countMondays($startDate, $endDate);
        $weeksInPeriod = max(1, $mondays);
        $tipsColumnKey = $this->findTipsColumnKey($earningCodes);

        $rows = [];
        foreach ($employeeIds as $employeeId) {
            $employee = $employees->get($employeeId);
            if (! $employee) {
                continue;
            }

            $employeeTimesheets = $timesheets
                ->filter(fn (Timesheet $timesheet) => (string) $timesheet->employeeId === $employeeId)
                ->values();

            $row = $this->buildEmployeeRow(
                $employee,
                $employeeTimesheets,
                $compensationsByEmployee->get($employeeId, collect()),
                $allowancesByEmployee[$employeeId] ?? ['lines' => []],
                $earningCodes,
                $startDate,
                $endDate,
                $otMultiplier,
                $mondays,
                $weeksInPeriod,
                (float) ($gratuityPointsByEmployee[$employeeId] ?? 0),
                $tipsColumnKey,
            );
            $rows[] = $row;
        }

        usort($rows, function (array $left, array $right): int {
            return [mb_strtolower((string) $left['employeeName']), (string) $left['employeeCode']]
                <=> [mb_strtolower((string) $right['employeeName']), (string) $right['employeeCode']];
        });

        $columns = $this->buildColumns($earningCodes);
        $totals = [];
        foreach ($columns as $column) {
            if (($column['type'] ?? '') !== 'money' && ($column['type'] ?? '') !== 'number') {
                continue;
            }
            $key = $column['key'];
            $totals[$key] = round(array_sum(array_map(
                fn (array $row) => (float) ($row['values'][$key] ?? 0),
                $rows,
            )), $key === self::GRATUITY_POINTS_KEY ? 4 : 2);
        }

        $deductionsByEmployee = $this->loadDefaultDeductions(collect($rows)->pluck('employeeId'));

        foreach ($rows as &$row) {
            $otherDeductions = (float) ($deductionsByEmployee[$row['employeeId']] ?? 0);
            $row['values']['otherDeductions'] = round($otherDeductions, 2);
            $row['values']['net'] = round(
                (float) $row['values']['gross']
                - (float) $row['values']['employeeSocialSecurity']
                - (float) $row['values']['incomeTax']
                - $otherDeductions,
                2,
            );
        }
        unset($row);

        $totals['otherDeductions'] = round(array_sum(array_map(
            fn (array $row) => (float) ($row['values']['otherDeductions'] ?? 0),
            $rows,
        )), 2);
        $totals['net'] = round(array_sum(array_map(
            fn (array $row) => (float) ($row['values']['net'] ?? 0),
            $rows,
        )), 2);

        return [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'scope' => $scope,
            'scopeLabel' => $this->scopeLabel($scope, $departmentId, $employeeGroupId),
            'departmentId' => $departmentId,
            'employeeGroupId' => $employeeGroupId,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
            'employeeCount' => count($rows),
        ];
    }

    /**
     * @return Collection<int, Timesheet>
     */
    private function loadTimesheets(
        Carbon $startDate,
        Carbon $endDate,
        string $scope,
        ?int $departmentId,
    ): Collection {
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
    private function groupMemberIds(string $groupId, Carbon $asOfDate): Collection
    {
        $group = EmployeeGroup::query()->find($groupId);
        if (! $group) {
            throw new InvalidArgumentException('Employee group was not found.');
        }

        return EmployeeGroupMember::query()
            ->where('employeeGroupId', $groupId)
            ->active($asOfDate->toDateString())
            ->pluck('employeeId')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     * @param Collection<int, EmployeeCompensation> $compensations
     * @param array<string, mixed> $allowanceData
     * @param Collection<int, PayrollEarningCode> $earningCodes
     * @return array<string, mixed>
     */
    private function buildEmployeeRow(
        Employee $employee,
        Collection $timesheets,
        Collection $compensations,
        array $allowanceData,
        Collection $earningCodes,
        Carbon $startDate,
        Carbon $endDate,
        float $otMultiplier,
        int $mondays,
        float $weeksInPeriod,
        float $gratuityPoints,
        ?string $tipsColumnKey,
    ): array {
        $values = [];
        foreach ($earningCodes as $code) {
            $values[$this->earningKey($code)] = 0.0;
        }

        $departmentNames = [];
        foreach ($timesheets as $timesheet) {
            $name = trim((string) ($timesheet->department?->name ?? ''));
            if ($name !== '') {
                $departmentNames[$name] = true;
            }

            if (! (bool) $timesheet->isPaid) {
                continue;
            }

            $rate = $this->hourlyRateForTimesheet($timesheet);
            $method = CompensationMethod::fromStored($timesheet->payType);
            $regular = round((float) $timesheet->regularHours * $rate, 2);
            $overtime = $method->allowsOvertime()
                ? round((float) $timesheet->overtimeHours * $rate * $otMultiplier, 2)
                : 0.0;
            $holiday = round((float) $timesheet->holidayHours * $rate, 2);

            $this->addEarning($values, $earningCodes, PayrollEarningCode::SOURCE_TIMESHEET_REGULAR, $regular);
            $this->addEarning($values, $earningCodes, PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME, $overtime);
            $this->addEarning($values, $earningCodes, PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY, $holiday);
        }

        $compensation = $this->compensationResolver->compensationForDate($compensations, $endDate);
        $frequencyId = $employee->payrateFrequencyId !== null ? (int) $employee->payrateFrequencyId : null;
        $leaveDailyRate = $this->compensationResolver->leaveDailyRate(
            $compensation,
            $frequencyId,
            $startDate,
            $endDate,
        );
        $vacation = $this->payrollFlatBaseScopeService->payableVacationLeaveAttribution(
            (string) $employee->id,
            $startDate,
            $endDate,
            $leaveDailyRate !== null ? round($leaveDailyRate * max(1, $startDate->diffInDays($endDate) + 1), 2) : null,
            null,
        );
        $this->addEarning(
            $values,
            $earningCodes,
            PayrollEarningCode::SOURCE_VACATION,
            (float) ($vacation['amount'] ?? 0),
        );

        $codesById = $earningCodes->keyBy('id');
        $fallback = $earningCodes->first(
            fn (PayrollEarningCode $code) => $code->source === PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK,
        );
        foreach ($allowanceData['lines'] ?? [] as $line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $code = isset($line['payrollEarningCodeId'])
                ? $codesById->get((int) $line['payrollEarningCodeId'])
                : null;
            $code ??= $fallback;
            if ($code) {
                $key = $this->earningKey($code);
                $values[$key] = round((float) ($values[$key] ?? 0) + $amount, 2);
            }
        }

        $tipsAmount = $tipsColumnKey !== null ? (float) ($values[$tipsColumnKey] ?? 0) : 0.0;
        $gratuityBase = $tipsAmount;
        foreach ($earningCodes as $code) {
            if (strtolower(trim((string) $code->name)) === 'gratuity'
                || strtoupper(trim((string) $code->code)) === 'GRATUITY') {
                $gratuityBase = round($gratuityBase + (float) ($values[$this->earningKey($code)] ?? 0), 2);
            }
        }
        $values[self::GRATUITY_KEY] = round($gratuityBase * 0.30, 2);
        $values[self::GRATUITY_POINTS_KEY] = round($gratuityPoints, 4);

        $payRate = $this->compensationResolver->effectiveHourlyRate($compensation);
        $values['payRate'] = $payRate !== null ? round($payRate, 2) : null;
        $values['payRateChangedDate'] = $this->payRateChangedDate($compensations, $startDate, $endDate, $compensation);

        $taxableGross = 0.0;
        foreach ($earningCodes as $code) {
            $taxableGross = round($taxableGross + (float) ($values[$this->earningKey($code)] ?? 0), 2);
        }
        $taxableGross = round($taxableGross + (float) $values[self::GRATUITY_KEY], 2);
        $values['gross'] = $taxableGross;

        $ss = $this->socialSecurityContributionService->calculate(
            $employee,
            $endDate,
            $mondays > 0 ? round($taxableGross / $mondays, 2) : 0.0,
            $weeksInPeriod,
        );
        $tax = $this->incomeTaxCalculationService->calculate(
            $employee,
            $endDate,
            $taxableGross,
            $employee->payrateFrequency?->name,
        );
        $values['employeeSocialSecurity'] = round((float) $ss['employee_amount'], 2);
        $values['employerSocialSecurity'] = round((float) $ss['employer_amount'], 2);
        $values['incomeTax'] = round((float) $tax['income_tax_amount'], 2);
        $values['otherDeductions'] = 0.0;
        $values['net'] = 0.0;

        $person = $employee->person;
        $employeeName = trim(collect([
            $person?->firstName,
            $person?->middleName,
            $person?->lastName,
        ])->filter()->implode(' '));

        return [
            'employeeId' => (string) $employee->id,
            'employeeCode' => $employee->code,
            'employeeName' => $employeeName !== '' ? $employeeName : 'Unknown employee',
            'departments' => implode(', ', array_keys($departmentNames)),
            'values' => $values,
        ];
    }

    /**
     * @param Collection<int, PayrollEarningCode> $earningCodes
     * @return list<array<string, mixed>>
     */
    private function buildColumns(Collection $earningCodes): array
    {
        $columns = [
            ['key' => 'employeeCode', 'label' => 'Employee code', 'type' => 'text', 'defaultVisible' => true],
            ['key' => 'employeeName', 'label' => 'Employee', 'type' => 'text', 'defaultVisible' => true],
            ['key' => 'departments', 'label' => 'Department(s)', 'type' => 'text', 'defaultVisible' => true],
        ];

        $tipsIndex = null;
        foreach ($earningCodes as $code) {
            $columns[] = [
                'key' => $this->earningKey($code),
                'label' => trim((string) $code->name) !== '' ? trim((string) $code->name) : (string) $code->code,
                'type' => 'money',
                'defaultVisible' => true,
                'earningCodeId' => $code->id,
            ];
            if ($this->isTipsCode($code)) {
                $tipsIndex = count($columns) - 1;
            }
        }

        $gratuityColumns = [
            ['key' => self::GRATUITY_KEY, 'label' => '30% Gratuity', 'type' => 'money', 'defaultVisible' => true],
            ['key' => self::GRATUITY_POINTS_KEY, 'label' => 'Gratuity points', 'type' => 'number', 'defaultVisible' => true],
        ];
        $insertAt = $tipsIndex !== null ? $tipsIndex + 1 : count($columns);
        array_splice($columns, $insertAt, 0, $gratuityColumns);

        return array_merge($columns, [
            ['key' => 'payRate', 'label' => 'Pay rate', 'type' => 'money', 'defaultVisible' => true],
            ['key' => 'payRateChangedDate', 'label' => 'Date pay rate changed', 'type' => 'date', 'defaultVisible' => true],
            ['key' => 'employeeSocialSecurity', 'label' => 'SS employee', 'type' => 'money', 'defaultVisible' => true],
            ['key' => 'employerSocialSecurity', 'label' => 'SS employer', 'type' => 'money', 'defaultVisible' => true],
            ['key' => 'gross', 'label' => 'Gross', 'type' => 'money', 'defaultVisible' => true],
            ['key' => 'net', 'label' => 'Net', 'type' => 'money', 'defaultVisible' => true],
        ]);
    }

    /**
     * @param array<string, float> $values
     * @param Collection<int, PayrollEarningCode> $earningCodes
     */
    private function addEarning(array &$values, Collection $earningCodes, string $source, float $amount): void
    {
        if ($amount == 0.0) {
            return;
        }

        $code = $earningCodes->first(fn (PayrollEarningCode $item) => $item->source === $source);
        if (! $code) {
            return;
        }

        $key = $this->earningKey($code);
        $values[$key] = round((float) ($values[$key] ?? 0) + $amount, 2);
    }

    private function earningKey(PayrollEarningCode $code): string
    {
        return 'earning:'.strtoupper(trim((string) $code->code));
    }

    private function isTipsCode(PayrollEarningCode $code): bool
    {
        return strtoupper(trim((string) $code->code)) === 'TIPS'
            || strtolower(trim((string) $code->name)) === 'tips';
    }

    /**
     * @param Collection<int, PayrollEarningCode> $earningCodes
     */
    private function findTipsColumnKey(Collection $earningCodes): ?string
    {
        $code = $earningCodes->first(fn (PayrollEarningCode $item) => $this->isTipsCode($item));

        return $code ? $this->earningKey($code) : null;
    }

    private function hourlyRateForTimesheet(Timesheet $timesheet): float
    {
        $hourlyRate = (float) ($timesheet->hourlyRate ?? 0);
        if ($hourlyRate > 0) {
            return $hourlyRate;
        }

        $baseSalary = (float) ($timesheet->baseSalary ?? 0);
        if ($baseSalary > 0) {
            return $this->compensationResolver->hourlyRateFromBaseSalary($baseSalary);
        }

        return 0.0;
    }

    /**
     * @param Collection<int, EmployeeCompensation> $compensations
     */
    private function payRateChangedDate(
        Collection $compensations,
        Carbon $startDate,
        Carbon $endDate,
        ?EmployeeCompensation $current,
    ): ?string {
        $changedInRange = $compensations
            ->filter(function (EmployeeCompensation $record) use ($startDate, $endDate) {
                if (! $record->effectiveDate) {
                    return false;
                }
                $effective = Carbon::parse($record->effectiveDate)->startOfDay();

                return $effective->betweenIncluded($startDate, $endDate);
            })
            ->sortByDesc(fn (EmployeeCompensation $record) => (string) $record->effectiveDate)
            ->first();

        $source = $changedInRange ?? $current;
        if (! $source?->effectiveDate) {
            return null;
        }

        return Carbon::parse($source->effectiveDate)->toDateString();
    }

    private function resolveGratuityPoolType(): ?PoolDistributionType
    {
        $types = PoolDistributionType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return $types->first(function (PoolDistributionType $type) {
            $code = strtoupper(trim((string) $type->code));
            $name = strtolower(trim((string) $type->name));

            return $code === 'GRATUITY' || $name === 'gratuity';
        }) ?? $types->first(function (PoolDistributionType $type) {
            return strtoupper(trim((string) $type->code)) === 'TIPS'
                || strtolower(trim((string) $type->name)) === 'tips';
        });
    }

    /**
     * @param Collection<int, string> $employeeIds
     * @return array<string, float>
     */
    private function loadGratuityPoints(Collection $employeeIds, Carbon $asOfDate, ?PoolDistributionType $type): array
    {
        if ($employeeIds->isEmpty() || $type === null) {
            return [];
        }

        $asOf = $asOfDate->toDateString();
        $points = EmployeePoolPoint::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('pool_distribution_type_id', $type->id)
            ->whereDate('effective_date', '<=', $asOf)
            ->where(function ($query) use ($asOf) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $asOf);
            })
            ->orderByDesc('effective_date')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $group) => round((float) $group->first()?->points, 4));

        return $points->all();
    }

    /**
     * @param Collection<int, string> $employeeIds
     * @return array<string, array{lines: list<array<string, mixed>>}>
     */
    private function loadDefaultAllowances(Collection $employeeIds): array
    {
        $results = [];
        foreach ($employeeIds as $employeeId) {
            $results[(string) $employeeId] = ['lines' => []];
        }

        if ($employeeIds->isEmpty()) {
            return $results;
        }

        $defaults = EmployeeDefaultAllowance::query()
            ->with('allowance')
            ->whereIn('employeeId', $employeeIds)
            ->get();

        foreach ($defaults as $default) {
            if (PayrollItemOccurrence::fromStored($default->occurrence) !== PayrollItemOccurrence::EveryPayroll) {
                continue;
            }

            $employeeId = (string) $default->employeeId;
            $results[$employeeId]['lines'][] = [
                'amount' => round((float) $default->amount, 2),
                'payrollEarningCodeId' => $default->allowance?->payroll_earning_code_id,
            ];
        }

        return $results;
    }

    /**
     * @param Collection<int, string> $employeeIds
     * @return array<string, float>
     */
    private function loadDefaultDeductions(Collection $employeeIds): array
    {
        $results = [];
        foreach ($employeeIds as $employeeId) {
            $results[(string) $employeeId] = 0.0;
        }

        if ($employeeIds->isEmpty()) {
            return $results;
        }

        $defaults = EmployeeDefaultDeduction::query()
            ->whereIn('employeeId', $employeeIds)
            ->get();

        foreach ($defaults as $default) {
            if (PayrollItemOccurrence::fromStored($default->occurrence ?? null) !== PayrollItemOccurrence::EveryPayroll) {
                continue;
            }

            $employeeId = (string) $default->employeeId;
            $results[$employeeId] = round(($results[$employeeId] ?? 0) + (float) $default->amount, 2);
        }

        return $results;
    }

    private function scopeLabel(string $scope, ?int $departmentId, ?string $employeeGroupId): string
    {
        if ($scope === self::SCOPE_DEPARTMENT) {
            $name = Department::query()->whereKey($departmentId)->value('name');

            return $name ? "Department — {$name}" : 'Department';
        }

        if ($scope === self::SCOPE_GROUP) {
            $name = EmployeeGroup::query()->whereKey($employeeGroupId)->value('name');

            return $name ? "Group — {$name}" : 'Group';
        }

        return 'Entire company';
    }
}
