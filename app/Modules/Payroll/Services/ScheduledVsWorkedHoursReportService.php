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
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ScheduledVsWorkedHoursReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const PERIOD_DATES = 'dates';

    public const PERIOD_PAYROLL_RUN = 'payroll_run';

    public const SORT_DEPARTMENT = 'department';

    public const SORT_NAME = 'name';

    public const SORT_POSITION = 'position';

    /**
     * @return list<string>
     */
    public static function sortKeys(): array
    {
        return [self::SORT_DEPARTMENT, self::SORT_NAME, self::SORT_POSITION];
    }

    public function __construct(
        private readonly EmployeeCompensationResolver $compensationResolver,
    ) {
    }

    /**
     * @param list<string> $sortBy
     * @return array<string, mixed>
     */
    public function buildFromPayrollRun(
        PayrollRun $payrollRun,
        string $scope,
        ?int $departmentId = null,
        array $sortBy = [],
    ): array {
        $payrollRun->load(['payPeriodSchedule.payPeriodGroup']);
        $schedule = $payrollRun->payPeriodSchedule;

        if (! $schedule?->start_date || ! $schedule?->end_date) {
            throw new InvalidArgumentException('Pay period schedule is missing for this payroll run.');
        }

        return $this->build(
            Carbon::parse($schedule->start_date)->startOfDay(),
            Carbon::parse($schedule->end_date)->startOfDay(),
            $scope,
            $departmentId,
            $sortBy,
            (string) $payrollRun->id,
            $schedule->payPeriodGroup?->name,
        );
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
        ?string $payrollRunId = null,
        ?string $payPeriodGroupName = null,
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
        $timesheets = $this->loadTimesheets($startDate, $endDate, $scope, $departmentId);
        $employeeIds = $timesheets->pluck('employeeId')->map(fn ($id) => (string) $id)->unique()->values();

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

        $payrollRunIds = filled($payrollRunId)
            ? collect([(string) $payrollRunId])
            : $this->overlappingPostedPayrollRunIds($startDate, $endDate);
        $earningAggByEmployee = $this->loadEarningAggregates($payrollRunIds);
        $poolAggByEmployee = $this->loadPoolAggregates($payrollRunIds);
        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);

        $rows = [];
        foreach ($employeeIds as $employeeId) {
            $employee = $employees->get($employeeId);
            if (! $employee) {
                continue;
            }

            $employeeTimesheets = $timesheets
                ->filter(fn (Timesheet $timesheet) => (string) $timesheet->employeeId === $employeeId)
                ->values();

            $rows[] = $this->buildEmployeeRow(
                $employee,
                $employeeTimesheets,
                $compensationsByEmployee->get($employeeId, collect()),
                $earningAggByEmployee->get($employeeId, [
                    'workedAmount' => 0.0,
                    'overtimeAmount' => 0.0,
                    'otherEarningsAmount' => 0.0,
                ]),
                $poolAggByEmployee->get($employeeId, [
                    'tips' => 0.0,
                    'shares' => 0.0,
                    'specialAssignments' => 0.0,
                ]),
                $endDate,
                $otMultiplier,
            );
        }

        $rows = collect($rows)->sort(function (array $left, array $right) use ($sortBy): int {
            foreach ($sortBy as $key) {
                $comparison = $this->sortValue($left, $key) <=> $this->sortValue($right, $key);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return [(string) ($left['employeeCode'] ?? ''), (string) $left['employeeName']]
                <=> [(string) ($right['employeeCode'] ?? ''), (string) $right['employeeName']];
        })->values();

        return [
            'period' => filled($payrollRunId) ? self::PERIOD_PAYROLL_RUN : self::PERIOD_DATES,
            'payrollRunId' => $payrollRunId,
            'payPeriodGroupName' => $payPeriodGroupName,
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'scope' => $scope,
            'scopeLabel' => $this->scopeLabel($scope, $departmentId),
            'departmentId' => $departmentId,
            'sortBy' => $sortBy,
            'rows' => $rows->all(),
            'totals' => [
                'scheduledHours' => round((float) $rows->sum('scheduledHours'), 2),
                'scheduledAmount' => round((float) $rows->sum('scheduledAmount'), 2),
                'workedHours' => round((float) $rows->sum('workedHours'), 2),
                'workedAmount' => round((float) $rows->sum('workedAmount'), 2),
                'overtimeHours' => round((float) $rows->sum('overtimeHours'), 2),
                'overtimeAmount' => round((float) $rows->sum('overtimeAmount'), 2),
                'tips' => round((float) $rows->sum('tips'), 2),
                'shares' => round((float) $rows->sum('shares'), 2),
                'specialAssignments' => round((float) $rows->sum('specialAssignments'), 2),
            ],
            'employeeCount' => $rows->count(),
        ];
    }

    /**
     * @param list<string> $sortBy
     * @return list<string>
     */
    private function normalizeSortBy(array $sortBy): array
    {
        $allowed = array_flip(self::sortKeys());
        $normalized = [];
        foreach ($sortBy as $key) {
            $key = strtolower(trim((string) $key));
            if (! isset($allowed[$key]) || in_array($key, $normalized, true)) {
                continue;
            }
            $normalized[] = $key;
        }

        return $normalized !== [] ? $normalized : [self::SORT_DEPARTMENT, self::SORT_NAME];
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
     * @param Collection<int, Timesheet> $timesheets
     * @param Collection<int, EmployeeCompensation> $compensations
     * @param array<string, float> $earnings
     * @param array<string, float> $pools
     * @return array<string, mixed>
     */
    private function buildEmployeeRow(
        Employee $employee,
        Collection $timesheets,
        Collection $compensations,
        array $earnings,
        array $pools,
        Carbon $asOfDate,
        float $otMultiplier,
    ): array {
        $scheduledHours = 0.0;
        $scheduledAmount = 0.0;
        $workedHours = 0.0;
        $workedAmountFromTimesheets = 0.0;
        $overtimeHours = 0.0;
        $overtimeAmountFromTimesheets = 0.0;
        $departmentNames = [];

        foreach ($timesheets as $timesheet) {
            $name = trim((string) ($timesheet->department?->name ?? ''));
            if ($name !== '') {
                $departmentNames[$name] = true;
            }

            $slotScheduled = $this->scheduledHoursForTimesheet($timesheet);
            $rate = $this->hourlyRateForTimesheet($timesheet, $compensations);
            $scheduledHours += $slotScheduled;
            $scheduledAmount += round($slotScheduled * $rate, 2);

            $workedHours += (float) ($timesheet->hoursWorked ?? 0);
            $overtimeHours += (float) ($timesheet->overtimeHours ?? 0);

            if (! (bool) $timesheet->isPaid) {
                continue;
            }

            $method = CompensationMethod::fromStored($timesheet->payType);
            $regular = round((float) $timesheet->regularHours * $rate, 2);
            $holiday = round((float) $timesheet->holidayHours * $rate, 2);
            $overtime = $method->allowsOvertime()
                ? round((float) $timesheet->overtimeHours * $rate * $otMultiplier, 2)
                : 0.0;
            $workedAmountFromTimesheets += $regular + $holiday;
            $overtimeAmountFromTimesheets += $overtime;
        }

        $postedWorkedAmount = round((float) ($earnings['workedAmount'] ?? 0), 2);
        $postedOvertimeAmount = round((float) ($earnings['overtimeAmount'] ?? 0), 2);
        $workedAmount = $postedWorkedAmount > 0 ? $postedWorkedAmount : round($workedAmountFromTimesheets, 2);
        $overtimeAmount = $postedOvertimeAmount > 0 ? $postedOvertimeAmount : round($overtimeAmountFromTimesheets, 2);

        $specialAssignments = round(
            (float) ($pools['specialAssignments'] ?? 0)
            + (float) ($earnings['otherEarningsAmount'] ?? 0),
            2,
        );

        $employmentDetail = $this->employmentDetailForDate($employee->employmentDetails, $asOfDate);
        $position = trim((string) ($employmentDetail?->jobTitle?->name ?? ''));
        $department = implode(', ', array_keys($departmentNames));
        if ($department === '') {
            $department = trim((string) ($employmentDetail?->department?->name ?? ''));
        }

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
            'department' => $department,
            'position' => $position,
            'scheduledHours' => round($scheduledHours, 2),
            'scheduledAmount' => round($scheduledAmount, 2),
            'workedHours' => round($workedHours, 2),
            'workedAmount' => $workedAmount,
            'overtimeHours' => round($overtimeHours, 2),
            'overtimeAmount' => $overtimeAmount,
            'tips' => round((float) ($pools['tips'] ?? 0), 2),
            'shares' => round((float) ($pools['shares'] ?? 0), 2),
            'specialAssignments' => $specialAssignments,
        ];
    }

    private function scheduledHoursForTimesheet(Timesheet $timesheet): float
    {
        $scheduled = round(
            (float) ($timesheet->regularHours ?? 0)
            + (float) ($timesheet->unpaidHours ?? 0)
            + (float) ($timesheet->holidayHours ?? 0),
            2,
        );
        if ($scheduled > 0) {
            return $scheduled;
        }

        return round(max(
            0.0,
            (float) ($timesheet->hoursWorked ?? 0) - (float) ($timesheet->overtimeHours ?? 0),
        ), 2);
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
     * @param Collection<int, string> $payrollRunIds
     * @return Collection<string, array<string, float>>
     */
    private function loadEarningAggregates(Collection $payrollRunIds): Collection
    {
        if ($payrollRunIds->isEmpty()) {
            return collect();
        }

        $codesById = PayrollEarningCode::query()
            ->get(['id', 'source'])
            ->keyBy('id');

        $rows = PayrollEarningLine::query()
            ->whereIn('payroll_run_id', $payrollRunIds)
            ->selectRaw('"employeeId", payroll_earning_code_id, source_type, SUM(amount) as total_amount')
            ->groupBy('employeeId', 'payroll_earning_code_id', 'source_type')
            ->get();

        $byEmployee = [];
        foreach ($rows as $row) {
            $employeeId = (string) $row->employeeId;
            $source = (string) ($codesById->get((int) $row->payroll_earning_code_id)?->source ?? '');
            $amount = round((float) ($row->total_amount ?? 0), 2);
            $sourceType = strtoupper((string) ($row->source_type ?? ''));

            $byEmployee[$employeeId] ??= [
                'workedAmount' => 0.0,
                'overtimeAmount' => 0.0,
                'otherEarningsAmount' => 0.0,
            ];

            if (in_array($source, [
                PayrollEarningCode::SOURCE_TIMESHEET_REGULAR,
                PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
            ], true)) {
                $byEmployee[$employeeId]['workedAmount'] = round($byEmployee[$employeeId]['workedAmount'] + $amount, 2);
                continue;
            }

            if ($source === PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME) {
                $byEmployee[$employeeId]['overtimeAmount'] = round($byEmployee[$employeeId]['overtimeAmount'] + $amount, 2);
                continue;
            }

            if ($sourceType !== 'POOL') {
                $byEmployee[$employeeId]['otherEarningsAmount'] = round(
                    $byEmployee[$employeeId]['otherEarningsAmount'] + $amount,
                    2
                );
            }
        }

        return collect($byEmployee);
    }

    /**
     * @param Collection<int, string> $payrollRunIds
     * @return Collection<string, array<string, float>>
     */
    private function loadPoolAggregates(Collection $payrollRunIds): Collection
    {
        if ($payrollRunIds->isEmpty()) {
            return collect();
        }

        $rows = PayrollRunPoolDistribution::query()
            ->with('poolDistributionType:id,code')
            ->whereIn('payroll_run_id', $payrollRunIds)
            ->where('amount', '>', 0)
            ->get();

        $byEmployee = [];
        foreach ($rows as $row) {
            $employeeId = (string) $row->employee_id;
            $code = strtoupper((string) ($row->poolDistributionType?->code ?? ''));
            $amount = round((float) ($row->amount ?? 0), 2);

            $byEmployee[$employeeId] ??= [
                'tips' => 0.0,
                'shares' => 0.0,
                'specialAssignments' => 0.0,
            ];

            if ($code === 'TIPS') {
                $byEmployee[$employeeId]['tips'] = round($byEmployee[$employeeId]['tips'] + $amount, 2);
            } elseif ($code === 'SHARES') {
                $byEmployee[$employeeId]['shares'] = round($byEmployee[$employeeId]['shares'] + $amount, 2);
            } else {
                $byEmployee[$employeeId]['specialAssignments'] = round($byEmployee[$employeeId]['specialAssignments'] + $amount, 2);
            }
        }

        return collect($byEmployee);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sortValue(array $row, string $key): string
    {
        return mb_strtolower(trim(match ($key) {
            self::SORT_DEPARTMENT => (string) ($row['department'] ?? ''),
            self::SORT_POSITION => (string) ($row['position'] ?? ''),
            default => (string) ($row['employeeName'] ?? ''),
        }));
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
