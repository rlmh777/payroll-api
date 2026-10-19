<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Payroll;
use App\Models\PayrollEarningCode;
use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PayrollRegistryReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const PERIOD_DATES = 'dates';

    public const PERIOD_PAYROLL_RUN = 'payroll_run';

    /**
     * @return list<string>
     */
    public static function sortKeys(): array
    {
        return ['department', 'lastName', 'firstName', 'employeeCode'];
    }

    public static function earningBucket(?string $source, mixed $multiplier): string
    {
        if ($source === PayrollEarningCode::SOURCE_TIMESHEET_REGULAR) {
            return 'regular';
        }
        if ($source === PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME) {
            return 'overtime';
        }
        if ($source === PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY && self::isDoubleTime($multiplier)) {
            return 'doubletime';
        }
        if ($source === PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY) {
            return 'regular';
        }

        return 'misc';
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
        $this->assertScope($scope, $departmentId);
        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }

        $payrolls = Payroll::query()
            ->with([
                'employee.person',
                'department:id,name',
                'payrollRun.payPeriodSchedule',
            ])
            ->whereDate('date', '>=', $startDate->toDateString())
            ->whereDate('date', '<=', $endDate->toDateString())
            ->whereHas('payrollRun', function ($query) {
                $query->whereRaw("LOWER(COALESCE(status, '')) = 'posted'");
            })
            ->when($scope === self::SCOPE_DEPARTMENT, function ($query) use ($departmentId) {
                $query->where('departmentId', $departmentId);
            })
            ->get();

        return $this->present(
            $payrolls,
            self::PERIOD_DATES,
            $startDate,
            $endDate,
            $scope,
            $departmentId,
            $sortBy,
            null,
        );
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
        $this->assertScope($scope, $departmentId);
        $payrollRun->loadMissing('payPeriodSchedule');

        $payrolls = Payroll::query()
            ->with([
                'employee.person',
                'department:id,name',
                'payrollRun.payPeriodSchedule',
            ])
            ->where('payroll_run_id', $payrollRun->id)
            ->when($scope === self::SCOPE_DEPARTMENT, function ($query) use ($departmentId) {
                $query->where('departmentId', $departmentId);
            })
            ->get();

        $schedule = $payrollRun->payPeriodSchedule;
        $startDate = Carbon::parse($schedule?->start_date ?? $payrolls->min('date') ?? Carbon::today())->startOfDay();
        $endDate = Carbon::parse($schedule?->end_date ?? $payrolls->max('date') ?? $startDate)->startOfDay();

        return $this->present(
            $payrolls,
            self::PERIOD_PAYROLL_RUN,
            $startDate,
            $endDate,
            $scope,
            $departmentId,
            $sortBy,
            $payrollRun,
        );
    }

    /**
     * @param Collection<int, Payroll> $payrolls
     * @param list<string> $sortBy
     * @return array<string, mixed>
     */
    private function present(
        Collection $payrolls,
        string $period,
        Carbon $startDate,
        Carbon $endDate,
        string $scope,
        ?int $departmentId,
        array $sortBy,
        ?PayrollRun $payrollRun,
    ): array {
        $sortBy = $this->normalizeSortBy($sortBy);
        $linesByPayroll = $this->earningLinesByPayroll($payrolls);
        $timesheetHours = $this->timesheetHours($payrolls, $scope, $departmentId);

        $employees = [];
        foreach ($payrolls as $payroll) {
            if (! $payroll->employee) {
                continue;
            }
            $employeeId = (string) $payroll->employeeId;
            $line = $this->payrollLine($payroll, $linesByPayroll->get((string) $payroll->id, collect()), $timesheetHours);
            if (! isset($employees[$employeeId])) {
                $person = $payroll->employee->person;
                $employees[$employeeId] = [
                    'employeeId' => $employeeId,
                    'employeeCode' => (string) ($payroll->employee->code ?? ''),
                    'firstName' => trim((string) ($person?->firstName ?? '')),
                    'lastName' => trim((string) ($person?->lastName ?? '')),
                    'displayName' => $this->displayName($person?->firstName, $person?->lastName),
                    'departmentId' => null,
                    'departmentName' => 'Unassigned',
                    'departmentHours' => [],
                    'lines' => [],
                ];
            }
            $employees[$employeeId]['lines'][] = $line;
            $departmentKey = $line['departmentId'] ?? 0;
            $employees[$employeeId]['departmentHours'][$departmentKey] = [
                'id' => $line['departmentId'],
                'name' => $line['departmentName'],
                'hours' => ($employees[$employeeId]['departmentHours'][$departmentKey]['hours'] ?? 0)
                    + $line['regularHours'] + $line['overtimeHours'] + $line['doubleTimeHours'],
            ];
        }

        $employeeList = [];
        foreach ($employees as $employee) {
            $department = $this->primaryDepartment($employee['departmentHours']);
            $employee['departmentId'] = $department['id'];
            $employee['departmentName'] = $department['name'];
            unset($employee['departmentHours']);
            usort($employee['lines'], function (array $left, array $right) {
                return [$left['processedDate'], $left['payrollNumber']] <=> [$right['processedDate'], $right['payrollNumber']];
            });
            $employee['years'] = $this->groupYears($employee['lines']);
            $employee['totals'] = $this->sumAmounts($employee['lines']);
            unset($employee['lines']);
            $employeeList[] = $employee;
        }

        usort($employeeList, fn (array $left, array $right) => $this->compareEmployees($left, $right, $sortBy));

        $company = $this->companyHeader();

        return [
            'title' => 'Payroll Registry by Employees',
            'company' => $company,
            'period' => $period,
            'periodLabel' => 'From: '.$startDate->format('j-M-Y').' To: '.$endDate->format('j-M-Y'),
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'scope' => $scope,
            'scopeLabel' => $this->scopeLabel($scope, $departmentId),
            'departmentId' => $scope === self::SCOPE_DEPARTMENT ? $departmentId : null,
            'payrollRunId' => $payrollRun?->id,
            'payrollNumber' => $payrollRun?->payroll_number,
            'sortBy' => $sortBy,
            'employees' => $employeeList,
            'totals' => $this->sumAmounts(array_map(fn (array $employee) => $employee['totals'], $employeeList)),
            'employeeCount' => count($employeeList),
        ];
    }

    /**
     * @param Collection<int, Payroll> $payrolls
     * @return Collection<string, Collection<int, PayrollEarningLine>>
     */
    private function earningLinesByPayroll(Collection $payrolls): Collection
    {
        $ids = $payrolls->pluck('id')->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return PayrollEarningLine::query()
            ->with('earningCode:id,source,pay_multiplier')
            ->whereIn('payroll_id', $ids)
            ->get()
            ->groupBy(fn (PayrollEarningLine $line) => (string) $line->payroll_id);
    }

    /**
     * @param Collection<int, Payroll> $payrolls
     * @return array<string, array<string, float>>
     */
    private function timesheetHours(Collection $payrolls, string $scope, ?int $departmentId): array
    {
        if ($payrolls->isEmpty()) {
            return [];
        }

        $min = null;
        $max = null;
        foreach ($payrolls as $payroll) {
            $schedule = $payroll->payrollRun?->payPeriodSchedule;
            $start = Carbon::parse($schedule?->start_date ?? $payroll->date)->startOfDay();
            $end = Carbon::parse($schedule?->end_date ?? $payroll->date)->startOfDay();
            $min = $min === null || $start->lt($min) ? $start->copy() : $min;
            $max = $max === null || $end->gt($max) ? $end->copy() : $max;
        }

        $employeeIds = $payrolls->pluck('employeeId')->map(fn ($id) => (string) $id)->unique()->values();
        $timesheets = Timesheet::query()
            ->whereIn('employeeId', $employeeIds)
            ->whereDate('date', '>=', $min->toDateString())
            ->whereDate('date', '<=', $max->toDateString())
            ->whereRaw("UPPER(COALESCE(\"approvalStatus\", '')) NOT IN (?, ?, ?)", ['REJECTED', 'DENIED', 'CANCELLED'])
            ->when($scope === self::SCOPE_DEPARTMENT && $departmentId !== null, function ($query) use ($departmentId) {
                $query->where('departmentId', $departmentId);
            })
            ->get(['employeeId', 'date', 'regularHours', 'overtimeHours']);

        $hours = [];
        foreach ($payrolls as $payroll) {
            $schedule = $payroll->payrollRun?->payPeriodSchedule;
            $start = Carbon::parse($schedule?->start_date ?? $payroll->date)->toDateString();
            $end = Carbon::parse($schedule?->end_date ?? $payroll->date)->toDateString();
            $employeeId = (string) $payroll->employeeId;
            $regular = 0.0;
            $overtime = 0.0;
            foreach ($timesheets as $timesheet) {
                if ((string) $timesheet->employeeId !== $employeeId) {
                    continue;
                }
                $date = Carbon::parse($timesheet->date)->toDateString();
                if ($date < $start || $date > $end) {
                    continue;
                }
                $regular += (float) $timesheet->regularHours;
                $overtime += (float) $timesheet->overtimeHours;
            }
            $hours[(string) $payroll->id] = [
                'regularHours' => round($regular, 2),
                'overtimeHours' => round($overtime, 2),
            ];
        }

        return $hours;
    }

    /**
     * @param Collection<int, PayrollEarningLine> $lines
     * @param array<string, array<string, float>> $timesheetHours
     * @return array<string, mixed>
     */
    private function payrollLine(Payroll $payroll, Collection $lines, array $timesheetHours): array
    {
        $amounts = $this->emptyAmounts();
        $regularLineHours = 0.0;
        $overtimeLineHours = 0.0;
        $doubleTimeLineHours = 0.0;

        foreach ($lines as $line) {
            $bucket = self::earningBucket($line->earningCode?->source, $line->earningCode?->pay_multiplier);
            $hours = (float) ($line->hours ?? 0);
            $amount = (float) $line->amount;
            if ($bucket === 'regular') {
                $regularLineHours += $hours;
                $amounts['regularPay'] += $amount;
            } elseif ($bucket === 'overtime') {
                $overtimeLineHours += $hours;
                $amounts['overtimePay'] += $amount;
            } elseif ($bucket === 'doubletime') {
                $doubleTimeLineHours += $hours;
                $amounts['doubleTimePay'] += $amount;
            } else {
                $amounts['miscPayments'] += $amount;
            }
        }

        if ($lines->isEmpty()) {
            $amounts['regularPay'] = (float) $payroll->grossSalary;
            $amounts['miscPayments'] = (float) $payroll->totalAllowances;
        }

        $sheet = $timesheetHours[(string) $payroll->id] ?? ['regularHours' => 0, 'overtimeHours' => 0];
        $amounts['regularHours'] = $regularLineHours > 0 ? $regularLineHours : (float) $sheet['regularHours'];
        $amounts['overtimeHours'] = $overtimeLineHours > 0
            ? $overtimeLineHours
            : ((float) $sheet['overtimeHours'] > 0 ? (float) $sheet['overtimeHours'] : (float) $payroll->totalOvertimeHours);
        $amounts['doubleTimeHours'] = $doubleTimeLineHours;
        $amounts['miscDeduction'] = (float) $payroll->totalDeductions;
        $amounts['tax'] = (float) $payroll->incomeTaxAmount;
        $amounts['employeeSocialSecurity'] = (float) $payroll->employeeSocialSecurityAmount;
        $amounts['netPay'] = (float) $payroll->netSalary;
        $amounts['employerSocialSecurity'] = (float) $payroll->employerSocialSecurityAmount;
        $amounts['employerContribution'] = 0.0;
        $amounts = $this->finalizeAmounts($amounts);

        $processed = Carbon::parse($payroll->date);

        return [
            'payrollId' => (string) $payroll->id,
            'payrollRunId' => (string) $payroll->payroll_run_id,
            'processedDate' => $processed->toDateString(),
            'processedDateLabel' => $processed->format('m/d/Y'),
            'period' => (int) $processed->month,
            'year' => (int) $processed->year,
            'payrollNumber' => (int) ($payroll->payrollRun?->payroll_number ?? 0),
            'departmentId' => $payroll->departmentId !== null ? (int) $payroll->departmentId : null,
            'departmentName' => trim((string) ($payroll->department?->name ?? '')) !== ''
                ? (string) $payroll->department->name
                : 'Unassigned',
            ...$amounts,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function groupYears(array $lines): array
    {
        $years = [];
        foreach ($lines as $line) {
            $year = (int) $line['year'];
            if (! isset($years[$year])) {
                $years[$year] = [
                    'year' => $year,
                    'lines' => [],
                ];
            }
            $years[$year]['lines'][] = $line;
        }

        $list = [];
        foreach ($years as $year) {
            $year['payrollCount'] = count($year['lines']);
            $year['totals'] = $this->sumAmounts($year['lines']);
            $list[] = $year;
        }

        return $list;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, float>
     */
    private function sumAmounts(array $rows): array
    {
        $totals = $this->emptyAmounts();
        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (float) ($row[$key] ?? 0);
            }
        }

        return $this->finalizeAmounts($totals);
    }

    /**
     * @param array<string, float> $amounts
     * @return array<string, float>
     */
    private function finalizeAmounts(array $amounts): array
    {
        foreach ($amounts as $key => $value) {
            $amounts[$key] = round((float) $value, 2);
        }
        $amounts['grossPay'] = round(
            $amounts['regularPay'] + $amounts['overtimePay'] + $amounts['doubleTimePay'] + $amounts['miscPayments'],
            2,
        );

        return $amounts;
    }

    /**
     * @return array<string, float>
     */
    private function emptyAmounts(): array
    {
        return [
            'regularHours' => 0.0,
            'regularPay' => 0.0,
            'overtimeHours' => 0.0,
            'overtimePay' => 0.0,
            'doubleTimeHours' => 0.0,
            'doubleTimePay' => 0.0,
            'miscPayments' => 0.0,
            'grossPay' => 0.0,
            'miscDeduction' => 0.0,
            'tax' => 0.0,
            'employeeSocialSecurity' => 0.0,
            'netPay' => 0.0,
            'employerSocialSecurity' => 0.0,
            'employerContribution' => 0.0,
        ];
    }

    /**
     * @param array<int, array{id: int|null, name: string, hours: float}> $hoursByDepartment
     * @return array{id: int|null, name: string}
     */
    private function primaryDepartment(array $hoursByDepartment): array
    {
        if ($hoursByDepartment === []) {
            return ['id' => null, 'name' => 'Unassigned'];
        }
        uasort($hoursByDepartment, fn (array $left, array $right) => ($right['hours'] ?? 0) <=> ($left['hours'] ?? 0));
        $department = reset($hoursByDepartment);

        return [
            'id' => $department['id'],
            'name' => $department['name'] !== '' ? $department['name'] : 'Unassigned',
        ];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @param list<string> $sortBy
     */
    private function compareEmployees(array $left, array $right, array $sortBy): int
    {
        foreach ($sortBy as $key) {
            $comparison = $this->sortValue($left, $key) <=> $this->sortValue($right, $key);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return $this->sortValue($left, 'employeeCode') <=> $this->sortValue($right, 'employeeCode');
    }

    /**
     * @param array<string, mixed> $employee
     */
    private function sortValue(array $employee, string $key): string
    {
        $value = match ($key) {
            'department' => (string) ($employee['departmentName'] ?? ''),
            'lastName' => (string) ($employee['lastName'] ?? ''),
            'firstName' => (string) ($employee['firstName'] ?? ''),
            default => (string) ($employee['employeeCode'] ?? ''),
        };

        return mb_strtolower(trim($value));
    }

    /**
     * @param list<string> $sortBy
     * @return list<string>
     */
    private function normalizeSortBy(array $sortBy): array
    {
        $normalized = [];
        foreach ($sortBy as $key) {
            $key = (string) $key;
            if (in_array($key, self::sortKeys(), true) && ! in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        return $normalized !== [] ? $normalized : ['department', 'lastName'];
    }

    private function assertScope(string $scope, ?int $departmentId): void
    {
        if (! in_array($scope, [self::SCOPE_COMPANY, self::SCOPE_DEPARTMENT], true)) {
            throw new InvalidArgumentException('Scope must be company or department.');
        }
        if ($scope === self::SCOPE_DEPARTMENT && ($departmentId === null || $departmentId <= 0)) {
            throw new InvalidArgumentException('Department is required when generating by department.');
        }
    }

    private function scopeLabel(string $scope, ?int $departmentId): string
    {
        if ($scope === self::SCOPE_DEPARTMENT) {
            $name = \App\Models\Department::query()->whereKey($departmentId)->value('name');

            return $name ? "Department — {$name}" : 'Department';
        }

        return 'Entire company';
    }

    /**
     * @return array{name: string, lines: list<string>, logoUrl: string|null}
     */
    private function companyHeader(): array
    {
        $company = Company::query()->with('locality.district')->first();
        $lines = [];
        $street = trim((string) ($company?->street ?? ''));
        if ($street !== '') {
            $lines[] = $street;
        }
        $locality = trim((string) ($company?->locality?->name ?? ''));
        $district = trim((string) ($company?->locality?->district?->name ?? ''));
        $place = trim(implode(', ', array_filter([$locality, $district])));
        if ($place !== '') {
            $lines[] = $place;
        }
        $email = trim((string) ($company?->email ?? ''));
        if ($email !== '') {
            $lines[] = $email;
        }

        return [
            'name' => trim((string) ($company?->legalName ?? '')) !== '' ? (string) $company->legalName : 'Company',
            'lines' => $lines,
            'logoUrl' => $company?->logoUrl,
        ];
    }

    private function displayName(mixed $firstName, mixed $lastName): string
    {
        $name = trim(implode(' ', array_filter([
            trim((string) ($firstName ?? '')),
            trim((string) ($lastName ?? '')),
        ])));

        return $name !== '' ? $name : 'Unknown employee';
    }

    private static function isDoubleTime(mixed $multiplier): bool
    {
        return $multiplier !== null && abs((float) $multiplier - 2.0) < 0.001;
    }
}
