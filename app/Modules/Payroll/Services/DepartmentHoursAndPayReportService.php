<?php

namespace App\Modules\Payroll\Services;

use App\Enums\CompensationMethod;
use App\Enums\LeaveStatusCode;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeGroup;
use App\Models\EmployeeGroupMember;
use App\Models\EmployeeLeave;
use App\Models\EmployeePoolPoint;
use App\Models\EmploymentDetail;
use App\Models\Payroll;
use App\Models\PayrollEarningCode;
use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use App\Models\PayrollRunPoolDistribution;
use App\Models\PoolDistributionType;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class DepartmentHoursAndPayReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_GROUP = 'group';

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
        ?string $employeeGroupId = null,
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
            $employeeGroupId,
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
        ?string $employeeGroupId = null,
        array $sortBy = [],
        ?string $payrollRunId = null,
        ?string $payPeriodGroupName = null,
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

        $sortBy = $this->normalizeSortBy($sortBy);
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
        $sharesByEmployee = $this->loadSharePoints($employeeIds, $endDate);
        $vacationByEmployee = $this->loadVacation($employeeIds, $startDate, $endDate);
        $priorYtdByEmployee = $this->loadPriorYtd($employeeIds, $startDate);
        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);
        $hoursPerDay = (float) config('attendance.standard_daily_hours', 8);

        $rows = [];
        foreach ($employeeIds as $employeeId) {
            $employee = $employees->get($employeeId);
            if (! $employee) {
                continue;
            }

            $rows[] = $this->buildEmployeeRow(
                $employee,
                $timesheets->filter(fn (Timesheet $timesheet) => (string) $timesheet->employeeId === $employeeId)->values(),
                $compensationsByEmployee->get($employeeId, collect()),
                $earningAggByEmployee->get($employeeId, []),
                $poolAggByEmployee->get($employeeId, []),
                (float) ($sharesByEmployee[$employeeId] ?? 0),
                $vacationByEmployee[$employeeId] ?? ['days' => 0],
                (float) ($priorYtdByEmployee[$employeeId] ?? 0),
                $endDate,
                $otMultiplier,
                $hoursPerDay,
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

        $numericKeys = [
            'shares',
            'biweeklyBaseSalary',
            'regularHours',
            'overtimeHours',
            'holidayHours',
            'vacationHours',
            'totalHours',
            'totalPay',
            'staffGratuity',
            'tips',
            'specialPay',
            'reservationCommission',
            'combinedYtdPay',
        ];
        $totals = [];
        foreach ($numericKeys as $key) {
            $totals[$key] = round((float) $rows->sum($key), $key === 'shares' ? 4 : 2);
        }

        return [
            'period' => filled($payrollRunId) ? self::PERIOD_PAYROLL_RUN : self::PERIOD_DATES,
            'payrollRunId' => $payrollRunId,
            'payPeriodGroupName' => $payPeriodGroupName,
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'scope' => $scope,
            'scopeLabel' => $this->scopeLabel($scope, $departmentId, $employeeGroupId),
            'departmentId' => $departmentId,
            'employeeGroupId' => $employeeGroupId,
            'sortBy' => $sortBy,
            'rows' => $rows->all(),
            'totals' => $totals,
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
     * @param array<string, float> $earnings
     * @param array<string, float> $pools
     * @param array{days: int} $vacation
     * @return array<string, mixed>
     */
    private function buildEmployeeRow(
        Employee $employee,
        Collection $timesheets,
        Collection $compensations,
        array $earnings,
        array $pools,
        float $shares,
        array $vacation,
        float $priorYtd,
        Carbon $asOfDate,
        float $otMultiplier,
        float $hoursPerDay,
    ): array {
        $regularHours = 0.0;
        $overtimeHours = 0.0;
        $holidayHours = 0.0;
        $regularPay = 0.0;
        $overtimePay = 0.0;
        $holidayPay = 0.0;
        $departmentNames = [];

        foreach ($timesheets as $timesheet) {
            $name = trim((string) ($timesheet->department?->name ?? ''));
            if ($name !== '') {
                $departmentNames[$name] = true;
            }

            $rate = $this->hourlyRateForTimesheet($timesheet, $compensations);
            $regularHours += (float) ($timesheet->regularHours ?? 0);
            $overtimeHours += (float) ($timesheet->overtimeHours ?? 0);
            $holidayHours += (float) ($timesheet->holidayHours ?? 0);

            if (! (bool) $timesheet->isPaid) {
                continue;
            }

            $method = CompensationMethod::fromStored($timesheet->payType);
            $regularPay += round((float) $timesheet->regularHours * $rate, 2);
            $holidayPay += round((float) $timesheet->holidayHours * $rate, 2);
            if ($method->allowsOvertime()) {
                $overtimePay += round((float) $timesheet->overtimeHours * $rate * $otMultiplier, 2);
            }
        }

        $employmentDetail = $this->employmentDetailForDate($employee->employmentDetails, $asOfDate);
        $compensation = $this->compensationResolver->compensationForDate(
            $compensations,
            $asOfDate,
            $employmentDetail?->id ? (string) $employmentDetail->id : null,
        );
        $hourlyRate = (float) ($this->compensationResolver->effectiveHourlyRate($compensation) ?? 0);
        $vacationDays = (int) ($vacation['days'] ?? 0);
        $vacationHours = round($vacationDays * $hoursPerDay, 2);
        $vacationPay = $hourlyRate > 0
            ? round($vacationHours * $hourlyRate, 2)
            : 0.0;

        $postedRegular = round((float) ($earnings['regularPay'] ?? 0), 2);
        $postedOvertime = round((float) ($earnings['overtimePay'] ?? 0), 2);
        $postedHoliday = round((float) ($earnings['holidayPay'] ?? 0), 2);
        $postedVacation = round((float) ($earnings['vacationPay'] ?? 0), 2);
        $totalPay = round(
            ($postedRegular > 0 ? $postedRegular : $regularPay)
            + ($postedOvertime > 0 ? $postedOvertime : $overtimePay)
            + ($postedHoliday > 0 ? $postedHoliday : $holidayPay)
            + ($postedVacation > 0 ? $postedVacation : $vacationPay),
            2,
        );

        $tips = round((float) ($pools['tips'] ?? 0) + (float) ($earnings['tips'] ?? 0), 2);
        $staffGratuity = round($tips * 0.30, 2);
        $specialPay = round((float) ($pools['specialPay'] ?? 0) + (float) ($earnings['specialPay'] ?? 0), 2);
        $reservationCommission = round(
            (float) ($pools['reservationCommission'] ?? 0) + (float) ($earnings['reservationCommission'] ?? 0),
            2,
        );
        $combinedPeriod = round($totalPay + $staffGratuity + $tips + $specialPay + $reservationCommission, 2);

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
            'department' => $department,
            'employeeName' => $employeeName !== '' ? $employeeName : 'Unknown employee',
            'startDate' => $employmentDetail?->startDate
                ? Carbon::parse($employmentDetail->startDate)->toDateString()
                : null,
            'shares' => round($shares, 4),
            'position' => trim((string) ($employmentDetail?->jobTitle?->name ?? '')),
            'biweeklyBaseSalary' => $this->biweeklyBaseSalary($compensation),
            'regularHours' => round($regularHours, 2),
            'overtimeHours' => round($overtimeHours, 2),
            'holidayHours' => round($holidayHours, 2),
            'vacationHours' => $vacationHours,
            'totalHours' => round($regularHours + $overtimeHours + $holidayHours + $vacationHours, 2),
            'totalPay' => $totalPay,
            'staffGratuity' => $staffGratuity,
            'tips' => $tips,
            'specialPay' => $specialPay,
            'reservationCommission' => $reservationCommission,
            'combinedYtdPay' => round($priorYtd + $combinedPeriod, 2),
        ];
    }

    private function biweeklyBaseSalary(?EmployeeCompensation $compensation): ?float
    {
        $yearly = (float) ($this->compensationResolver->effectiveYearlyRate($compensation) ?? 0);
        if ($yearly > 0) {
            return round($yearly / 26, 2);
        }

        $hourly = (float) ($this->compensationResolver->effectiveHourlyRate($compensation) ?? 0);
        if ($hourly <= 0) {
            return null;
        }

        $weeklyHours = $this->compensationResolver->standardWeeklyHours($compensation);

        return round($hourly * $weeklyHours * 2, 2);
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
     * @param Collection<int, string> $employeeIds
     * @return array<string, array{days: int}>
     */
    private function loadVacation(Collection $employeeIds, Carbon $startDate, Carbon $endDate): array
    {
        if ($employeeIds->isEmpty()) {
            return [];
        }

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
            $byEmployee[$employeeId]['days'] = (int) (($byEmployee[$employeeId]['days'] ?? 0) + $days);
        }

        return $byEmployee;
    }

    /**
     * @param Collection<int, string> $employeeIds
     * @return array<string, float>
     */
    private function loadSharePoints(Collection $employeeIds, Carbon $asOfDate): array
    {
        if ($employeeIds->isEmpty()) {
            return [];
        }

        $type = PoolDistributionType::query()
            ->where('is_active', true)
            ->get()
            ->first(function (PoolDistributionType $item) {
                $code = strtoupper(trim((string) $item->code));
                $name = strtolower(trim((string) $item->name));

                return $code === 'SHARES' || $name === 'shares';
            });
        if (! $type) {
            return [];
        }

        $asOf = $asOfDate->toDateString();

        return EmployeePoolPoint::query()
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
            ->map(fn (Collection $group) => round((float) $group->first()?->points, 4))
            ->all();
    }

    /**
     * @param Collection<int, string> $employeeIds
     * @return array<string, float>
     */
    private function loadPriorYtd(Collection $employeeIds, Carbon $startDate): array
    {
        if ($employeeIds->isEmpty()) {
            return [];
        }

        $yearStart = $startDate->copy()->startOfYear();

        return Payroll::query()
            ->selectRaw('"employeeId", SUM("taxableGross") as ytd_earnings')
            ->whereIn('employeeId', $employeeIds)
            ->whereDate('date', '>=', $yearStart->toDateString())
            ->whereDate('date', '<', $startDate->toDateString())
            ->where(function ($query) {
                $query->whereDoesntHave('payrollRun')
                    ->orWhereHas('payrollRun', fn ($runQuery) => $runQuery->whereRaw('LOWER(status) = ?', ['posted']));
            })
            ->groupBy('employeeId')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->employeeId => round((float) ($row->ytd_earnings ?? 0), 2)])
            ->all();
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

        $codesById = PayrollEarningCode::query()->get(['id', 'source', 'code', 'name'])->keyBy('id');
        $rows = PayrollEarningLine::query()
            ->whereIn('payroll_run_id', $payrollRunIds)
            ->selectRaw('"employeeId", payroll_earning_code_id, source_type, SUM(amount) as total_amount')
            ->groupBy('employeeId', 'payroll_earning_code_id', 'source_type')
            ->get();

        $byEmployee = [];
        foreach ($rows as $row) {
            $employeeId = (string) $row->employeeId;
            $code = $codesById->get((int) $row->payroll_earning_code_id);
            $amount = round((float) ($row->total_amount ?? 0), 2);
            $source = (string) ($code?->source ?? '');
            $sourceType = strtoupper((string) ($row->source_type ?? ''));
            $byEmployee[$employeeId] ??= [
                'regularPay' => 0.0,
                'overtimePay' => 0.0,
                'holidayPay' => 0.0,
                'vacationPay' => 0.0,
                'tips' => 0.0,
                'specialPay' => 0.0,
                'reservationCommission' => 0.0,
            ];

            if ($source === PayrollEarningCode::SOURCE_TIMESHEET_REGULAR) {
                $byEmployee[$employeeId]['regularPay'] = round($byEmployee[$employeeId]['regularPay'] + $amount, 2);
                continue;
            }
            if ($source === PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME) {
                $byEmployee[$employeeId]['overtimePay'] = round($byEmployee[$employeeId]['overtimePay'] + $amount, 2);
                continue;
            }
            if ($source === PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY) {
                $byEmployee[$employeeId]['holidayPay'] = round($byEmployee[$employeeId]['holidayPay'] + $amount, 2);
                continue;
            }
            if ($source === PayrollEarningCode::SOURCE_VACATION) {
                $byEmployee[$employeeId]['vacationPay'] = round($byEmployee[$employeeId]['vacationPay'] + $amount, 2);
                continue;
            }
            if ($sourceType === 'POOL') {
                continue;
            }
            if ($this->isTipsCode($code)) {
                $byEmployee[$employeeId]['tips'] = round($byEmployee[$employeeId]['tips'] + $amount, 2);
                continue;
            }
            if ($this->isReservationCommissionCode($code)) {
                $byEmployee[$employeeId]['reservationCommission'] = round(
                    $byEmployee[$employeeId]['reservationCommission'] + $amount,
                    2,
                );
                continue;
            }
            $byEmployee[$employeeId]['specialPay'] = round($byEmployee[$employeeId]['specialPay'] + $amount, 2);
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
            ->with('poolDistributionType:id,code,name')
            ->whereIn('payroll_run_id', $payrollRunIds)
            ->where('amount', '>', 0)
            ->get();

        $byEmployee = [];
        foreach ($rows as $row) {
            $employeeId = (string) $row->employee_id;
            $type = $row->poolDistributionType;
            $amount = round((float) ($row->amount ?? 0), 2);
            $byEmployee[$employeeId] ??= [
                'tips' => 0.0,
                'specialPay' => 0.0,
                'reservationCommission' => 0.0,
            ];

            if ($this->isTipsType($type)) {
                $byEmployee[$employeeId]['tips'] = round($byEmployee[$employeeId]['tips'] + $amount, 2);
            } elseif ($this->isReservationCommissionType($type)) {
                $byEmployee[$employeeId]['reservationCommission'] = round(
                    $byEmployee[$employeeId]['reservationCommission'] + $amount,
                    2,
                );
            } elseif (! $this->isSharesType($type)) {
                $byEmployee[$employeeId]['specialPay'] = round($byEmployee[$employeeId]['specialPay'] + $amount, 2);
            }
        }

        return collect($byEmployee);
    }

    private function isTipsCode(?PayrollEarningCode $code): bool
    {
        if (! $code) {
            return false;
        }

        return strtoupper(trim((string) $code->code)) === 'TIPS'
            || strtolower(trim((string) $code->name)) === 'tips';
    }

    private function isReservationCommissionCode(?PayrollEarningCode $code): bool
    {
        if (! $code) {
            return false;
        }

        return $this->looksLikeReservationCommission((string) $code->code, (string) $code->name);
    }

    private function isTipsType(?PoolDistributionType $type): bool
    {
        if (! $type) {
            return false;
        }

        return strtoupper(trim((string) $type->code)) === 'TIPS'
            || strtolower(trim((string) $type->name)) === 'tips';
    }

    private function isSharesType(?PoolDistributionType $type): bool
    {
        if (! $type) {
            return false;
        }

        return strtoupper(trim((string) $type->code)) === 'SHARES'
            || strtolower(trim((string) $type->name)) === 'shares';
    }

    private function isReservationCommissionType(?PoolDistributionType $type): bool
    {
        if (! $type) {
            return false;
        }

        return $this->looksLikeReservationCommission((string) $type->code, (string) $type->name);
    }

    private function looksLikeReservationCommission(string $code, string $name): bool
    {
        $normalizedCode = strtoupper(trim($code));
        $normalizedName = strtolower(trim($name));

        return in_array($normalizedCode, ['RESV', 'RESVCOMM', 'RESV_COMM', 'COMM', 'COMMISSION', 'TRIPS'], true)
            || str_contains($normalizedName, 'reservation')
            || str_contains($normalizedName, 'commission')
            || $normalizedName === 'trips';
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
