<?php

namespace App\Modules\Payroll\Services;

use App\Enums\LeaveStatusCode;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeLeave;
use App\Models\EmployeePoolPoint;
use App\Models\EmploymentDetail;
use App\Models\PayrollEarningCode;
use App\Models\PayrollRun;
use App\Models\PayrollRunPoolTotal;
use App\Models\PoolDistributionType;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\PublicHolidayPayResolver;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PayrollMasterSheetReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public function __construct(
        private readonly EmployeeCompensationResolver $compensationResolver,
        private readonly TimesheetEarningAllocator $earningAllocator,
        private readonly PublicHolidayPayResolver $holidayPayResolver,
    ) {
    }

    /**
     * @param list<array{year?: mixed, month?: mixed}> $periods
     * @return array<string, mixed>
     */
    public function build(string $scope, ?int $departmentId, array $periods): array
    {
        $scope = strtolower(trim($scope));
        if (! in_array($scope, [self::SCOPE_COMPANY, self::SCOPE_DEPARTMENT], true)) {
            throw new InvalidArgumentException('Scope must be company or department.');
        }
        if ($scope === self::SCOPE_DEPARTMENT && ($departmentId === null || $departmentId <= 0)) {
            throw new InvalidArgumentException('Department is required when generating by department.');
        }

        $normalizedPeriods = $this->normalizePeriods($periods);
        if ($normalizedPeriods === []) {
            throw new InvalidArgumentException('Select at least one year and month.');
        }

        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);
        $gratuityType = $this->resolveGratuityPoolType();
        $company = Company::query()->orderBy('id')->first();
        $companyName = trim((string) ($company?->legalName ?? $company?->alias ?? '')) ?: 'Company';
        $scopeLabel = $this->scopeLabel($scope, $departmentId);
        $earningCodes = PayrollEarningCode::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $earningColumns = $this->earningAllocator->columnsFor($earningCodes);

        $months = [];
        foreach ($normalizedPeriods as $period) {
            $months[] = $this->buildMonth(
                (int) $period['year'],
                (int) $period['month'],
                $scope,
                $departmentId,
                $otMultiplier,
                $gratuityType,
                $companyName,
                $earningCodes,
                $earningColumns,
            );
        }

        return [
            'companyName' => $companyName,
            'title' => 'Department Payroll Register',
            'scope' => $scope,
            'scopeLabel' => $scopeLabel,
            'departmentId' => $scope === self::SCOPE_DEPARTMENT ? $departmentId : null,
            'earningColumns' => $earningColumns,
            'months' => $months,
        ];
    }

    /**
     * @param list<array{year?: mixed, month?: mixed}> $periods
     * @return list<array{year: int, month: int}>
     */
    private function normalizePeriods(array $periods): array
    {
        $unique = [];
        foreach ($periods as $period) {
            $year = (int) ($period['year'] ?? 0);
            $month = (int) ($period['month'] ?? 0);
            if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
                throw new InvalidArgumentException('Each period must include a valid year and month.');
            }
            $unique[sprintf('%04d-%02d', $year, $month)] = [
                'year' => $year,
                'month' => $month,
            ];
        }

        ksort($unique);

        if (count($unique) > 36) {
            throw new InvalidArgumentException('Select at most 36 months.');
        }

        return array_values($unique);
    }

    /**
     * @param Collection<int, PayrollEarningCode> $earningCodes
     * @param list<array{key: string, code: string|null, name: string, source: string|null, payMultiplier: float|null}> $earningColumns
     * @return array<string, mixed>
     */
    private function buildMonth(
        int $year,
        int $month,
        string $scope,
        ?int $departmentId,
        float $otMultiplier,
        ?PoolDistributionType $gratuityType,
        string $companyName,
        Collection $earningCodes,
        array &$earningColumns,
    ): array {
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth()->startOfDay();
        $label = $startDate->format('F Y').' Payroll';

        $timesheets = $this->loadTimesheets($startDate, $endDate, $scope, $departmentId);
        $timesheetEmployeeIds = $timesheets
            ->pluck('employeeId')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
        $pointEmployeeIds = $this->loadEmployeeIdsWithGratuityPoints($endDate, $gratuityType);
        $employeeIds = $timesheetEmployeeIds->merge($pointEmployeeIds)->unique()->values();

        $employees = $employeeIds->isEmpty()
            ? collect()
            : Employee::query()
                ->with(['person', 'employmentDetails.jobTitle', 'employmentDetails.department'])
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

        $gratuityPointsByEmployee = $this->loadGratuityPoints($employeeIds, $endDate, $gratuityType);
        $poolAmount = $this->monthlyGratuityPool($startDate, $endDate, $gratuityType);
        $mondays = max(1, PayPeriodHelper::countMondays($startDate, $endDate));
        $holidayMultipliers = $this->holidayPayResolver->multipliersForRange($startDate, $endDate);
        foreach (array_unique(array_values($holidayMultipliers)) as $multiplier) {
            $this->earningAllocator->ensureHolidayColumn($earningColumns, $earningCodes, (float) $multiplier);
        }
        $vacationByEmployee = $this->loadVacationByEmployee($employeeIds, $startDate, $endDate, $compensationsByEmployee);

        $drafts = [];
        foreach ($employeeIds as $employeeId) {
            $employee = $employees->get($employeeId);
            if (! $employee) {
                continue;
            }

            $employeeTimesheets = $timesheets
                ->filter(fn (Timesheet $timesheet) => (string) $timesheet->employeeId === $employeeId)
                ->values();

            $row = $this->buildEmployeeDraft(
                $employee,
                $employeeTimesheets,
                $compensationsByEmployee->get($employeeId, collect()),
                (float) ($gratuityPointsByEmployee[$employeeId] ?? 0),
                $endDate,
                $otMultiplier,
                $mondays,
                $earningCodes,
                $earningColumns,
                $holidayMultipliers,
                $vacationByEmployee[$employeeId] ?? ['hours' => 0.0, 'amount' => 0.0],
            );

            if ($scope === self::SCOPE_DEPARTMENT && (int) $row['departmentId'] !== (int) $departmentId) {
                continue;
            }

            $drafts[] = $row;
        }

        $weightedPoints = array_sum(array_map(
            fn (array $row) => (float) $row['weightedPoints'],
            $drafts,
        ));
        $gratuityRate = $weightedPoints > 0 ? round($poolAmount / $weightedPoints, 4) : 0.0;

        $departments = [];
        foreach ($drafts as $draft) {
            $row = $this->finalizeEmployeeRow($draft, $gratuityRate);
            $key = $row['departmentId'] === null ? 'unassigned' : (string) $row['departmentId'];
            if (! isset($departments[$key])) {
                $departments[$key] = [
                    'departmentId' => $row['departmentId'],
                    'departmentName' => $row['departmentName'],
                    'rows' => [],
                ];
            }
            $departments[$key]['rows'][] = $row;
        }

        $departmentList = collect($departments)
            ->map(function (array $department) {
                usort($department['rows'], function (array $left, array $right): int {
                    return [mb_strtolower((string) $left['employeeName']), (string) $left['employeeCode']]
                        <=> [mb_strtolower((string) $right['employeeName']), (string) $right['employeeCode']];
                });
                $department['totals'] = $this->totalsFromRows($department['rows'], $earningColumns);

                return $department;
            })
            ->sortBy(function (array $department) {
                $unassigned = $department['departmentId'] === null ? 1 : 0;

                return sprintf('%d-%s', $unassigned, mb_strtolower((string) $department['departmentName']));
            })
            ->values();

        $allRows = $departmentList->flatMap(fn (array $department) => $department['rows'])->all();

        return [
            'year' => $year,
            'month' => $month,
            'label' => $label,
            'sheetName' => $startDate->format('F'),
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'companyName' => $companyName,
            'gratuityRate' => $gratuityRate,
            'gratuityPoolAmount' => round($poolAmount, 2),
            'departments' => $departmentList->all(),
            'totals' => $this->totalsFromRows($allRows, $earningColumns),
            'employeeCount' => count($allRows),
        ];
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     * @param Collection<int, EmployeeCompensation> $compensations
     * @param Collection<int, PayrollEarningCode> $earningCodes
     * @param list<array{key: string, code: string|null, name: string, source: string|null, payMultiplier: float|null}> $earningColumns
     * @param array<string, float> $holidayMultipliers
     * @param array{hours: float, amount: float} $vacation
     * @return array<string, mixed>
     */
    private function buildEmployeeDraft(
        Employee $employee,
        Collection $timesheets,
        Collection $compensations,
        float $gratuityPoints,
        Carbon $asOfDate,
        float $otMultiplier,
        int $mondays,
        Collection $earningCodes,
        array $earningColumns,
        array $holidayMultipliers,
        array $vacation,
    ): array {
        $earnings = $this->emptyEarnings($earningColumns);
        $workingTotal = 0.0;
        $totalHours = 0.0;
        $hoursByDepartment = [];
        $lastRate = 0.0;
        $compensation = $this->compensationResolver->compensationForDate($compensations, $asOfDate);
        $standardDailyHours = $this->compensationResolver->standardWeeklyHours($compensation) / 5;
        $dailyRate = (float) ($this->compensationResolver->effectiveDailyRate($compensation) ?? 0);

        foreach ($timesheets as $timesheet) {
            $rate = $this->hourlyRateForTimesheet($timesheet, $compensations);
            if ($rate > 0) {
                $lastRate = $rate;
            }

            $dateKey = $timesheet->date ? Carbon::parse($timesheet->date)->toDateString() : '';
            $holidayMultiplier = $dateKey !== '' ? ($holidayMultipliers[$dateKey] ?? null) : null;
            $allocations = $this->earningAllocator->allocate(
                $timesheet,
                $earningCodes,
                $holidayMultiplier,
                $rate,
                $dailyRate,
                $standardDailyHours,
                $otMultiplier,
            );

            foreach ($allocations as $allocation) {
                $key = $allocation['key'];
                if (! isset($earnings[$key])) {
                    $earnings[$key] = ['hours' => 0.0, 'amount' => 0.0];
                }
                $earnings[$key]['hours'] = round($earnings[$key]['hours'] + $allocation['hours'], 2);
                $earnings[$key]['amount'] = round($earnings[$key]['amount'] + $allocation['amount'], 2);
                $totalHours += $allocation['hours'];
                $workingTotal += $allocation['amount'];

                $departmentKey = $timesheet->departmentId !== null ? (int) $timesheet->departmentId : 0;
                $hoursByDepartment[$departmentKey] = ($hoursByDepartment[$departmentKey] ?? 0) + $allocation['hours'];
            }
        }

        $vacationKey = $this->vacationColumnKey($earningColumns);
        if ($vacationKey !== null && ((float) $vacation['hours'] > 0 || (float) $vacation['amount'] > 0)) {
            $earnings[$vacationKey]['hours'] = round($earnings[$vacationKey]['hours'] + (float) $vacation['hours'], 2);
            $earnings[$vacationKey]['amount'] = round($earnings[$vacationKey]['amount'] + (float) $vacation['amount'], 2);
            $totalHours += (float) $vacation['hours'];
            $workingTotal += (float) $vacation['amount'];
        }

        $employmentDetail = $this->employmentDetailForDate($employee->employmentDetails, $asOfDate);
        $departmentId = $this->primaryDepartmentId($hoursByDepartment, $employmentDetail);
        $departmentName = $this->departmentName($departmentId, $timesheets, $employmentDetail);

        if ($lastRate <= 0) {
            $lastRate = (float) ($this->compensationResolver->effectiveHourlyRate($compensation) ?? 0);
        }

        $totalHours = round($totalHours, 2);
        $workingTotal = round($workingTotal, 2);
        $expectedHours = $this->compensationResolver->standardWeeklyHours($compensation) * $mondays;
        $ratio = $expectedHours > 0 ? $totalHours / $expectedHours : ($totalHours > 0 ? 1.0 : 0.0);
        $gratuityPercent = (int) min(100, max(0, (int) round($ratio * 10) * 10));
        $weightedPoints = round($gratuityPoints * ($gratuityPercent / 100), 4);

        $person = $employee->person;
        $employeeName = trim((string) implode(' ', array_filter([
            $person?->firstName ?? null,
            $person?->middleName ?? null,
            $person?->lastName ?? null,
        ])));

        return [
            'employeeId' => (string) $employee->id,
            'employeeCode' => $employee->code,
            'employeeName' => $employeeName !== '' ? $employeeName : 'Unknown employee',
            'jobDescription' => trim((string) ($employmentDetail?->jobTitle?->name ?? '')),
            'departmentId' => $departmentId,
            'departmentName' => $departmentName,
            'hourlyBase' => round($lastRate, 2),
            'earnings' => $earnings,
            'totalHours' => $totalHours,
            'workingTotal' => $workingTotal,
            'gratuityPoints' => round($gratuityPoints, 4),
            'gratuityPercentWorked' => $gratuityPercent,
            'weightedPoints' => $weightedPoints,
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function finalizeEmployeeRow(array $draft, float $gratuityRate): array
    {
        $payout = round((float) $draft['weightedPoints'] * $gratuityRate, 2);
        $grossSalary = round((float) $draft['workingTotal'] + $payout, 2);
        $totalHours = (float) $draft['totalHours'];
        unset($draft['weightedPoints']);

        return array_merge($draft, [
            'gratuityPayout' => $payout,
            'grossSalary' => $grossSalary,
            'totalGrossPerHour' => $totalHours > 0 ? round($grossSalary / $totalHours, 2) : 0.0,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array{key: string, code: string|null, name: string, source: string|null, payMultiplier: float|null}> $earningColumns
     * @return array<string, mixed>
     */
    private function totalsFromRows(array $rows, array $earningColumns): array
    {
        $earnings = $this->emptyEarnings($earningColumns);
        $totals = [
            'totalHours' => 0.0,
            'workingTotal' => 0.0,
            'gratuityPoints' => 0.0,
            'gratuityPayout' => 0.0,
            'grossSalary' => 0.0,
        ];
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }
            foreach ($earnings as $key => $value) {
                $earnings[$key]['hours'] += (float) ($row['earnings'][$key]['hours'] ?? 0);
                $earnings[$key]['amount'] += (float) ($row['earnings'][$key]['amount'] ?? 0);
            }
        }
        foreach ($totals as $key => $value) {
            $totals[$key] = round($value, $key === 'gratuityPoints' ? 4 : 2);
        }
        foreach ($earnings as $key => $value) {
            $earnings[$key]['hours'] = round($value['hours'], 2);
            $earnings[$key]['amount'] = round($value['amount'], 2);
        }
        $totals['earnings'] = $earnings;
        $totals['totalGrossPerHour'] = $totals['totalHours'] > 0
            ? round($totals['grossSalary'] / $totals['totalHours'], 2)
            : 0.0;
        $totals['employeeCount'] = count($rows);

        return $totals;
    }

    /**
     * @param list<array{key: string, code: string|null, name: string, source: string|null, payMultiplier: float|null}> $earningColumns
     * @return array<string, array{hours: float, amount: float}>
     */
    private function emptyEarnings(array $earningColumns): array
    {
        $earnings = [];
        foreach ($earningColumns as $column) {
            $earnings[$column['key']] = ['hours' => 0.0, 'amount' => 0.0];
        }

        return $earnings;
    }

    /**
     * @param list<array{key: string, source: string|null}> $earningColumns
     */
    private function vacationColumnKey(array $earningColumns): ?string
    {
        foreach ($earningColumns as $column) {
            if (($column['source'] ?? null) === PayrollEarningCode::SOURCE_VACATION) {
                return $column['key'];
            }
        }

        return null;
    }

    /**
     * @param Collection<int, string> $employeeIds
     * @param Collection<string, Collection<int, EmployeeCompensation>> $compensationsByEmployee
     * @return array<string, array{hours: float, amount: float}>
     */
    private function loadVacationByEmployee(
        Collection $employeeIds,
        Carbon $startDate,
        Carbon $endDate,
        Collection $compensationsByEmployee,
    ): array {
        if ($employeeIds->isEmpty()) {
            return [];
        }

        $hoursPerDay = (float) config('attendance.standard_daily_hours', 8);
        $leaves = EmployeeLeave::query()
            ->with('leaveType')
            ->whereIn('employeeId', $employeeIds)
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::activeAbsenceStatuses(),
            )))
            ->whereDate('startDate', '<=', $endDate->toDateString())
            ->whereDate('endDate', '>=', $startDate->toDateString())
            ->get();

        $byEmployee = [];
        foreach ($leaves as $leave) {
            if (! $leave->leaveType?->isVacation()) {
                continue;
            }
            $multiplier = (float) ($leave->multiplier ?? 1);
            if ($multiplier <= 0) {
                continue;
            }

            $cursor = Carbon::parse($leave->startDate)->max($startDate)->startOfDay();
            $leaveEnd = Carbon::parse($leave->endDate)->min($endDate)->startOfDay();
            $days = 0;
            while ($cursor->lte($leaveEnd)) {
                $days++;
                $cursor->addDay();
            }
            $days = (int) round($days * $multiplier);
            if ($days <= 0) {
                continue;
            }

            $employeeId = (string) $leave->employeeId;
            $compensation = $this->compensationResolver->compensationForDate(
                $compensationsByEmployee->get($employeeId, collect()),
                $endDate,
            );
            $rate = (float) ($this->compensationResolver->effectiveHourlyRate($compensation) ?? 0);
            $hours = round($days * $hoursPerDay, 2);
            $byEmployee[$employeeId] ??= ['hours' => 0.0, 'amount' => 0.0];
            $byEmployee[$employeeId]['hours'] = round($byEmployee[$employeeId]['hours'] + $hours, 2);
            $byEmployee[$employeeId]['amount'] = round($byEmployee[$employeeId]['amount'] + ($hours * $rate), 2);
        }

        return $byEmployee;
    }

    /**
     * @param array<int, float> $hoursByDepartment
     */
    private function primaryDepartmentId(array $hoursByDepartment, ?EmploymentDetail $employmentDetail): ?int
    {
        if ($hoursByDepartment !== []) {
            arsort($hoursByDepartment);
            $departmentId = (int) array_key_first($hoursByDepartment);

            return $departmentId > 0 ? $departmentId : null;
        }

        return $employmentDetail?->departmentId !== null ? (int) $employmentDetail->departmentId : null;
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function departmentName(
        ?int $departmentId,
        Collection $timesheets,
        ?EmploymentDetail $employmentDetail,
    ): string {
        if ($departmentId !== null) {
            $fromTimesheet = $timesheets->first(
                fn (Timesheet $timesheet) => (int) $timesheet->departmentId === $departmentId,
            );
            $name = trim((string) ($fromTimesheet?->department?->name ?? ''));
            if ($name !== '') {
                return $name;
            }
            if ((int) $employmentDetail?->departmentId === $departmentId) {
                $name = trim((string) ($employmentDetail?->department?->name ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }

            return (string) (Department::query()->whereKey($departmentId)->value('name') ?? 'Unassigned');
        }

        $name = trim((string) ($employmentDetail?->department?->name ?? ''));

        return $name !== '' ? $name : 'Unassigned';
    }

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
    private function loadEmployeeIdsWithGratuityPoints(Carbon $asOfDate, ?PoolDistributionType $type): Collection
    {
        if ($type === null) {
            return collect();
        }

        $asOf = $asOfDate->toDateString();

        return EmployeePoolPoint::query()
            ->where('pool_distribution_type_id', $type->id)
            ->whereDate('effective_date', '<=', $asOf)
            ->where(function ($query) use ($asOf) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $asOf);
            })
            ->pluck('employee_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
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

    private function monthlyGratuityPool(
        Carbon $startDate,
        Carbon $endDate,
        ?PoolDistributionType $type,
    ): float {
        if ($type === null) {
            return 0.0;
        }

        $runIds = PayrollRun::query()
            ->whereRaw('LOWER(COALESCE(status, \'\')) = ?', ['posted'])
            ->whereHas('payPeriodSchedule', function ($query) use ($startDate, $endDate) {
                $query
                    ->whereDate('start_date', '<=', $endDate->toDateString())
                    ->whereDate('end_date', '>=', $startDate->toDateString());
            })
            ->pluck('id');

        if ($runIds->isEmpty()) {
            return 0.0;
        }

        return round((float) PayrollRunPoolTotal::query()
            ->whereIn('payroll_run_id', $runIds)
            ->where('pool_distribution_type_id', $type->id)
            ->sum('total_amount'), 2);
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

    private function scopeLabel(string $scope, ?int $departmentId): string
    {
        if ($scope === self::SCOPE_DEPARTMENT) {
            $name = Department::query()->whereKey($departmentId)->value('name');

            return $name ? "Department — {$name}" : 'Department';
        }

        return 'Entire company';
    }
}
