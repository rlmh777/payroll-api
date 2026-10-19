<?php

namespace App\Modules\Payroll\Services;

use App\Enums\CompensationMethod;
use App\Enums\LeaveStatusCode;
use App\Models\Company;
use App\Models\Department;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeLeave;
use App\Models\PayrollEarningCode;
use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CompanyPayrollAnalysisByDepartmentReportService
{
    public const PERIOD_DATES = 'dates';

    public const PERIOD_PAYROLL_RUN = 'payroll_run';

    public function __construct(
        private readonly EmployeeCompensationResolver $compensationResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFromPayrollRun(PayrollRun $payrollRun): array
    {
        $payrollRun->load(['payPeriodSchedule.payPeriodGroup']);
        $schedule = $payrollRun->payPeriodSchedule;

        if (! $schedule?->start_date || ! $schedule?->end_date) {
            throw new InvalidArgumentException('Pay period schedule is missing for this payroll run.');
        }

        return $this->build(
            Carbon::parse($schedule->start_date)->startOfDay(),
            Carbon::parse($schedule->end_date)->startOfDay(),
            (string) $payrollRun->id,
            $payrollRun->payroll_number !== null ? (int) $payrollRun->payroll_number : null,
            $schedule->payPeriodGroup?->name,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        Carbon $startDate,
        Carbon $endDate,
        ?string $payrollRunId = null,
        ?int $payrollNumber = null,
        ?string $payPeriodGroupName = null,
    ): array {
        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }

        $timesheets = Timesheet::query()
            ->with(['department:id,name'])
            ->whereDate('date', '>=', $startDate->toDateString())
            ->whereDate('date', '<=', $endDate->toDateString())
            ->whereRaw("UPPER(COALESCE(\"approvalStatus\", '')) NOT IN (?, ?, ?)", ['REJECTED', 'DENIED', 'CANCELLED'])
            ->orderBy('date')
            ->get();

        $employeeIds = $timesheets->pluck('employeeId')->map(fn ($id) => (string) $id)->unique()->values();
        $compensationsByEmployee = $employeeIds->isEmpty()
            ? collect()
            : EmployeeCompensation::query()
                ->whereIn('employeeId', $employeeIds)
                ->orderByDesc('effectiveDate')
                ->get()
                ->groupBy('employeeId');

        $otMultiplier = (float) config('payroll.overtime_multiplier', 1.5);
        $hoursPerDay = (float) config('attendance.standard_daily_hours', 8);
        $buckets = [];

        foreach ($timesheets as $timesheet) {
            $bucket = &$this->bucket($buckets, $timesheet->departmentId, $timesheet->department?->name);
            $employeeId = (string) $timesheet->employeeId;
            $bucket['employeeIds'][$employeeId] = true;

            $rate = $this->hourlyRateForTimesheet($timesheet, $compensationsByEmployee->get($employeeId, collect()));
            $bucket['regularHours'] += (float) ($timesheet->regularHours ?? 0);
            $bucket['overtimeHours'] += (float) ($timesheet->overtimeHours ?? 0);
            $bucket['holidayHours'] += (float) ($timesheet->holidayHours ?? 0);

            if ((bool) $timesheet->isPaid) {
                $method = CompensationMethod::fromStored($timesheet->payType);
                $bucket['regularPay'] += round((float) $timesheet->regularHours * $rate, 2);
                $bucket['holidayPay'] += round((float) $timesheet->holidayHours * $rate, 2);
                if ($method->allowsOvertime()) {
                    $bucket['overtimePay'] += round((float) $timesheet->overtimeHours * $rate * $otMultiplier, 2);
                }
            }
            unset($bucket);
        }

        $this->addVacation($buckets, $startDate, $endDate, $hoursPerDay, $compensationsByEmployee);

        $payrollRunIds = filled($payrollRunId)
            ? collect([(string) $payrollRunId])
            : $this->overlappingPostedPayrollRunIds($startDate, $endDate);
        $this->applyPostedEarnings($buckets, $payrollRunIds);

        $rows = collect($buckets)
            ->map(fn (array $bucket) => $this->finalizeRow($bucket))
            ->sortBy(function (array $row) {
                $unassigned = $row['departmentId'] === null ? 1 : 0;

                return sprintf('%d-%s', $unassigned, mb_strtolower($row['departmentName']));
            })
            ->values();

        $totals = $this->emptyBucket(null, 'TOTAL ALL');
        foreach ($rows as $row) {
            $totals['employeeCount'] = (int) ($totals['employeeCount'] ?? 0) + (int) $row['employeeCount'];
            foreach ([
                'regularHours', 'regularPay', 'overtimeHours', 'overtimePay',
                'holidayHours', 'holidayPay', 'vacationHours', 'vacationPay',
                'totalHours', 'totalPay',
            ] as $key) {
                $totals[$key] = round((float) ($totals[$key] ?? 0) + (float) $row[$key], 2);
            }
        }
        $totalsRow = $this->finalizeRow($totals);
        $totalsRow['departmentName'] = 'TOTAL ALL';
        $totalsRow['employeeCount'] = (int) ($totals['employeeCount'] ?? 0);

        $company = Company::query()->orderBy('id')->first();

        return [
            'companyName' => trim((string) ($company?->legalName ?? $company?->alias ?? '')) ?: 'Company',
            'title' => 'Company Payroll Analysis by Department',
            'period' => filled($payrollRunId) ? self::PERIOD_PAYROLL_RUN : self::PERIOD_DATES,
            'payrollRunId' => $payrollRunId,
            'payrollNumber' => $payrollNumber,
            'payPeriodGroupName' => $payPeriodGroupName,
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'rows' => $rows->all(),
            'totals' => $totalsRow,
            'employeeCount' => (int) $totalsRow['employeeCount'],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @return array<string, mixed>
     */
    private function &bucket(array &$buckets, mixed $departmentId, ?string $departmentName): array
    {
        $id = $departmentId !== null ? (int) $departmentId : null;
        $key = $id === null ? 'unassigned' : (string) $id;
        if (! isset($buckets[$key])) {
            $name = trim((string) $departmentName);
            if ($name === '' && $id !== null) {
                $name = (string) (Department::query()->whereKey($id)->value('name') ?? 'Unassigned');
            }
            $buckets[$key] = $this->emptyBucket($id, $name !== '' ? $name : 'Unassigned');
        }

        return $buckets[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBucket(?int $departmentId, string $departmentName): array
    {
        return [
            'departmentId' => $departmentId,
            'departmentName' => $departmentName,
            'employeeIds' => [],
            'employeeCount' => 0,
            'regularHours' => 0.0,
            'regularPay' => 0.0,
            'overtimeHours' => 0.0,
            'overtimePay' => 0.0,
            'holidayHours' => 0.0,
            'holidayPay' => 0.0,
            'vacationHours' => 0.0,
            'vacationPay' => 0.0,
            'postedRegularHours' => 0.0,
            'postedRegularPay' => 0.0,
            'postedOvertimeHours' => 0.0,
            'postedOvertimePay' => 0.0,
            'postedHolidayHours' => 0.0,
            'postedHolidayPay' => 0.0,
            'postedVacationHours' => 0.0,
            'postedVacationPay' => 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $bucket
     * @return array<string, mixed>
     */
    private function finalizeRow(array $bucket): array
    {
        $regularHours = $this->preferPosted((float) $bucket['regularHours'], (float) ($bucket['postedRegularHours'] ?? 0));
        $regularPay = $this->preferPosted((float) $bucket['regularPay'], (float) ($bucket['postedRegularPay'] ?? 0));
        $overtimeHours = $this->preferPosted((float) $bucket['overtimeHours'], (float) ($bucket['postedOvertimeHours'] ?? 0));
        $overtimePay = $this->preferPosted((float) $bucket['overtimePay'], (float) ($bucket['postedOvertimePay'] ?? 0));
        $holidayHours = $this->preferPosted((float) $bucket['holidayHours'], (float) ($bucket['postedHolidayHours'] ?? 0));
        $holidayPay = $this->preferPosted((float) $bucket['holidayPay'], (float) ($bucket['postedHolidayPay'] ?? 0));
        $vacationHours = $this->preferPosted((float) $bucket['vacationHours'], (float) ($bucket['postedVacationHours'] ?? 0));
        $vacationPay = $this->preferPosted((float) $bucket['vacationPay'], (float) ($bucket['postedVacationPay'] ?? 0));
        $totalHours = round($regularHours + $overtimeHours + $holidayHours + $vacationHours, 2);
        $totalPay = round($regularPay + $overtimePay + $holidayPay + $vacationPay, 2);
        $employeeCount = (int) ($bucket['employeeCount'] ?? 0);
        if ($employeeCount <= 0) {
            $employeeCount = count($bucket['employeeIds'] ?? []);
        }

        return [
            'departmentId' => $bucket['departmentId'],
            'departmentName' => $bucket['departmentName'],
            'employeeCount' => $employeeCount,
            'regularHours' => round($regularHours, 2),
            'regularPay' => round($regularPay, 2),
            'overtimeHours' => round($overtimeHours, 2),
            'overtimePay' => round($overtimePay, 2),
            'holidayHours' => round($holidayHours, 2),
            'holidayPay' => round($holidayPay, 2),
            'vacationHours' => round($vacationHours, 2),
            'vacationPay' => round($vacationPay, 2),
            'totalHours' => $totalHours,
            'totalPay' => $totalPay,
            'averagePayPerHour' => $totalHours > 0 ? round($totalPay / $totalHours, 2) : 0.0,
        ];
    }

    private function preferPosted(float $computed, float $posted): float
    {
        return $posted > 0 ? $posted : $computed;
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @param Collection<string, Collection<int, EmployeeCompensation>> $compensationsByEmployee
     */
    private function addVacation(
        array &$buckets,
        Carbon $startDate,
        Carbon $endDate,
        float $hoursPerDay,
        Collection $compensationsByEmployee,
    ): void {
        $leaves = EmployeeLeave::query()
            ->with(['leaveType', 'department:id,name'])
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::activeAbsenceStatuses(),
            )))
            ->whereDate('startDate', '<=', $endDate->toDateString())
            ->whereDate('endDate', '>=', $startDate->toDateString())
            ->get();

        $missingEmployeeIds = $leaves->pluck('employeeId')->map(fn ($id) => (string) $id)->unique()
            ->diff($compensationsByEmployee->keys()->map(fn ($id) => (string) $id));
        if ($missingEmployeeIds->isNotEmpty()) {
            $extra = EmployeeCompensation::query()
                ->whereIn('employeeId', $missingEmployeeIds)
                ->orderByDesc('effectiveDate')
                ->get()
                ->groupBy('employeeId');
            $compensationsByEmployee = $compensationsByEmployee->union($extra);
        }

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

            $hours = round($days * $hoursPerDay, 2);
            $employeeId = (string) $leave->employeeId;
            $compensation = $this->compensationResolver->compensationForDate(
                $compensationsByEmployee->get($employeeId, collect()),
                $endDate,
            );
            $rate = (float) ($this->compensationResolver->effectiveHourlyRate($compensation) ?? 0);
            $bucket = &$this->bucket($buckets, $leave->departmentId, $leave->department?->name);
            $bucket['employeeIds'][$employeeId] = true;
            $bucket['vacationHours'] += $hours;
            $bucket['vacationPay'] += round($hours * $rate, 2);
            unset($bucket);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $buckets
     * @param Collection<int, string> $payrollRunIds
     */
    private function applyPostedEarnings(array &$buckets, Collection $payrollRunIds): void
    {
        if ($payrollRunIds->isEmpty()) {
            return;
        }

        $codesById = PayrollEarningCode::query()->get(['id', 'source'])->keyBy('id');
        $rows = PayrollEarningLine::query()
            ->whereIn('payroll_run_id', $payrollRunIds)
            ->selectRaw('"departmentId", payroll_earning_code_id, SUM(COALESCE(hours, 0)) as total_hours, SUM(amount) as total_amount')
            ->groupBy('departmentId', 'payroll_earning_code_id')
            ->get();

        foreach ($rows as $row) {
            $source = (string) ($codesById->get((int) $row->payroll_earning_code_id)?->source ?? '');
            $hours = round((float) ($row->total_hours ?? 0), 2);
            $amount = round((float) ($row->total_amount ?? 0), 2);
            $bucket = &$this->bucket($buckets, $row->departmentId, null);
            if ($source === PayrollEarningCode::SOURCE_TIMESHEET_REGULAR) {
                $bucket['postedRegularHours'] += $hours;
                $bucket['postedRegularPay'] += $amount;
            } elseif ($source === PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME) {
                $bucket['postedOvertimeHours'] += $hours;
                $bucket['postedOvertimePay'] += $amount;
            } elseif ($source === PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY) {
                $bucket['postedHolidayHours'] += $hours;
                $bucket['postedHolidayPay'] += $amount;
            } elseif ($source === PayrollEarningCode::SOURCE_VACATION) {
                $bucket['postedVacationHours'] += $hours;
                $bucket['postedVacationPay'] += $amount;
            }
            unset($bucket);
        }
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
}
