<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\Payroll;
use App\Services\SocialSecurity\SocialSecurityContributionService;
use Carbon\Carbon;
use InvalidArgumentException;

class SocialSecurityDeductionReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const SORT_KEYS = [
        'lastName',
        'firstName',
        'socialSecurityNumber',
        'department',
    ];

    public function __construct(
        private readonly SocialSecurityContributionService $socialSecurityContributionService,
    ) {
    }

    /**
     * @param list<int> $departmentIds
     * @param list<string> $sortBy
     * @return array<string, mixed>
     */
    public function build(
        Carbon $startDate,
        Carbon $endDate,
        string $scope,
        array $departmentIds = [],
        array $sortBy = [],
    ): array {
        if (! in_array($scope, [self::SCOPE_COMPANY, self::SCOPE_DEPARTMENT], true)) {
            throw new InvalidArgumentException('Scope must be company or department.');
        }
        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }

        $departmentIds = array_values(array_unique(array_filter(array_map('intval', $departmentIds))));
        $sortBy = $this->normalizeSort($sortBy);
        $company = $this->companyHeader();
        $weeks = $this->weeks($startDate, $endDate, $scope, $departmentIds);
        $departments = $this->pages($weeks, $sortBy);
        $employees = [];
        foreach ($departments as $department) {
            foreach ($department['employees'] as $employee) {
                $employees[$employee['employeeId']] = true;
            }
        }

        $flatWeeks = [];
        foreach ($departments as $department) {
            foreach ($department['employees'] as $employee) {
                foreach ($employee['weeks'] as $week) {
                    $flatWeeks[] = $week;
                }
            }
        }

        return [
            'title' => 'Social Security Deduction Report',
            'organization' => $company['name'],
            'company' => $company,
            'periodStart' => $startDate->format('m/d/Y'),
            'periodEnd' => $endDate->format('m/d/Y'),
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'scope' => $scope,
            'scopeLabel' => $this->scopeLabel($scope, $departmentIds),
            'departmentIds' => $scope === self::SCOPE_DEPARTMENT ? $departmentIds : [],
            'sortBy' => $sortBy,
            'departments' => $departments,
            'pageCount' => array_sum(array_map(fn (array $department) => count($department['employees']), $departments)),
            'employeeCount' => count($employees),
            'totals' => $this->moneyTotals($flatWeeks),
        ];
    }

    /**
     * @param list<int> $departmentIds
     * @return list<array<string, mixed>>
     */
    private function weeks(Carbon $startDate, Carbon $endDate, string $scope, array $departmentIds): array
    {
        $payrolls = Payroll::query()
            ->with([
                'employee.person',
                'department:id,name',
                'employmentDetail',
                'payrollRun.payPeriodSchedule',
            ])
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll.payroll_run_id')
            ->join('pay_period_schedule', 'pay_period_schedule.id', '=', 'payroll_runs.pay_period_schedule_id')
            ->whereRaw('LOWER(payroll_runs.status) = ?', ['posted'])
            ->whereDate('pay_period_schedule.start_date', '<=', $endDate->toDateString())
            ->whereDate('pay_period_schedule.end_date', '>=', $startDate->toDateString())
            ->when($scope === self::SCOPE_DEPARTMENT && $departmentIds !== [], function ($query) use ($departmentIds) {
                $query->whereIn('payroll.departmentId', $departmentIds);
            })
            ->select('payroll.*')
            ->get();

        $combined = [];
        foreach ($payrolls as $payroll) {
            $employee = $payroll->employee;
            if (! $employee) {
                continue;
            }

            $schedule = $payroll->payrollRun?->payPeriodSchedule;
            $periodStart = Carbon::parse($schedule?->start_date ?? $payroll->date)->startOfDay();
            $periodEnd = Carbon::parse($schedule?->end_date ?? $payroll->date)->startOfDay();
            if ($periodEnd->lt($periodStart)) {
                $periodEnd = $periodStart->copy();
            }

            $periodWeeks = $this->periodWeeks($periodStart, $periodEnd);
            if ($periodWeeks === []) {
                continue;
            }

            $taxableGross = (float) ($payroll->taxableGross ?? $payroll->grossSalary ?? 0);
            $weeklyEarnings = round($taxableGross / count($periodWeeks), 2);
            $payDate = Carbon::parse($schedule?->pay_date ?? $payroll->date ?? $periodEnd)->startOfDay();
            $contribution = $this->socialSecurityContributionService->calculate(
                $employee,
                $payDate,
                $weeklyEarnings,
                1.0,
            );
            $employeeContribution = round((float) ($contribution['employee_amount'] ?? 0), 2);
            $employerContribution = round((float) ($contribution['employer_amount'] ?? 0), 2);
            $person = $employee->person;
            $departmentName = trim((string) ($payroll->department?->name ?? '')) ?: 'Unassigned';
            $departmentId = $payroll->departmentId !== null ? (int) $payroll->departmentId : 0;

            foreach ($periodWeeks as $thursday) {
                if (! $this->weekIsInReport($thursday, $startDate, $endDate, $periodStart, $periodEnd)) {
                    continue;
                }
                if (! $this->employedOn($payroll, $thursday)) {
                    continue;
                }

                $key = $employee->id.'|'.$departmentId.'|'.$thursday->toDateString().'|'.$periodStart->toDateString();
                if (! isset($combined[$key])) {
                    $combined[$key] = [
                        'employeeId' => (string) $employee->id,
                        'firstName' => trim((string) ($person?->firstName ?? '')),
                        'lastName' => trim((string) ($person?->lastName ?? '')),
                        'socialSecurityNumber' => $this->formatSocialSecurity($person?->socialSecurityNumber ?? null),
                        'departmentId' => $departmentId,
                        'departmentName' => $departmentName,
                        'periodStart' => $periodStart->format('m/d/Y'),
                        'periodEnd' => $periodEnd->format('m/d/Y'),
                        'periodStartIso' => $periodStart->toDateString(),
                        'weekNumber' => (int) $thursday->isoWeek(),
                        'weekYear' => (int) $thursday->isoWeekYear(),
                        'employeeContribution' => 0.0,
                        'employerContribution' => 0.0,
                        'totalContribution' => 0.0,
                    ];
                }

                $combined[$key]['employeeContribution'] = round((float) $combined[$key]['employeeContribution'] + $employeeContribution, 2);
                $combined[$key]['employerContribution'] = round((float) $combined[$key]['employerContribution'] + $employerContribution, 2);
                $combined[$key]['totalContribution'] = round(
                    (float) $combined[$key]['employeeContribution'] + (float) $combined[$key]['employerContribution'],
                    2,
                );
            }
        }

        return array_values($combined);
    }

    /**
     * @param list<array<string, mixed>> $weeks
     * @param list<string> $sortBy
     * @return list<array<string, mixed>>
     */
    private function pages(array $weeks, array $sortBy): array
    {
        $grouped = [];
        foreach ($weeks as $week) {
            $departmentKey = (string) $week['departmentId'];
            $grouped[$departmentKey] ??= [
                'departmentId' => $week['departmentId'],
                'name' => $week['departmentName'],
                'employees' => [],
            ];
            $employeeKey = (string) $week['employeeId'];
            $grouped[$departmentKey]['employees'][$employeeKey] ??= [
                'employeeId' => $week['employeeId'],
                'lastName' => $week['lastName'],
                'firstName' => $week['firstName'],
                'socialSecurityNumber' => $week['socialSecurityNumber'],
                'weeks' => [],
            ];
            $grouped[$departmentKey]['employees'][$employeeKey]['weeks'][] = $week;
        }

        $departments = array_values($grouped);
        usort($departments, fn (array $left, array $right) => strcasecmp((string) $left['name'], (string) $right['name']));

        $page = 1;
        foreach ($departments as &$department) {
            $employees = array_values($department['employees']);
            usort($employees, function (array $left, array $right) use ($sortBy): int {
                foreach ($sortBy as $key) {
                    if ($key === 'department') {
                        continue;
                    }
                    $comparison = strcasecmp((string) ($left[$key] ?? ''), (string) ($right[$key] ?? ''));
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return strcasecmp((string) $left['lastName'], (string) $right['lastName']);
            });

            foreach ($employees as &$employee) {
                usort($employee['weeks'], function (array $left, array $right): int {
                    return [$left['weekYear'], $left['weekNumber'], $left['periodStartIso']]
                        <=> [$right['weekYear'], $right['weekNumber'], $right['periodStartIso']];
                });
                $employee['pageNumber'] = $page;
                $employee['weekCount'] = count($employee['weeks']);
                $employee['totals'] = $this->moneyTotals($employee['weeks']);
                $page++;
            }
            unset($employee);

            $departmentWeeks = [];
            foreach ($employees as $employee) {
                foreach ($employee['weeks'] as $week) {
                    $departmentWeeks[] = $week;
                }
            }
            $department['employees'] = $employees;
            $department['employeeCount'] = count($employees);
            $department['totals'] = $this->moneyTotals($departmentWeeks);
        }
        unset($department);

        return $departments;
    }

    /**
     * @param list<array<string, mixed>> $weeks
     * @return array{employeeContribution: float, employerContribution: float, totalContribution: float, weekCount: int}
     */
    private function moneyTotals(array $weeks): array
    {
        return [
            'employeeContribution' => round((float) array_sum(array_column($weeks, 'employeeContribution')), 2),
            'employerContribution' => round((float) array_sum(array_column($weeks, 'employerContribution')), 2),
            'totalContribution' => round((float) array_sum(array_column($weeks, 'totalContribution')), 2),
            'weekCount' => count($weeks),
        ];
    }

    /**
     * @return list<Carbon>
     */
    private function periodWeeks(Carbon $periodStart, Carbon $periodEnd): array
    {
        $thursdays = $this->thursdaysBetween($periodStart, $periodEnd);
        if ($thursdays !== []) {
            return $thursdays;
        }

        return [$periodStart->copy()->startOfWeek(Carbon::MONDAY)->addDays(3)];
    }

    private function weekIsInReport(
        Carbon $thursday,
        Carbon $reportStart,
        Carbon $reportEnd,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): bool {
        if ($thursday->betweenIncluded($reportStart, $reportEnd)) {
            return true;
        }

        return $this->thursdaysBetween($periodStart, $periodEnd) === []
            && $periodStart->lte($reportEnd)
            && $periodEnd->gte($reportStart);
    }

    private function employedOn(Payroll $payroll, Carbon $thursday): bool
    {
        $start = $payroll->employmentDetail?->startDate;
        $end = $payroll->employmentDetail?->endDate;
        if ($start && $thursday->lt(Carbon::parse($start)->startOfDay())) {
            return false;
        }
        if ($end && $thursday->gt(Carbon::parse($end)->startOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string> $sortBy
     * @return list<string>
     */
    private function normalizeSort(array $sortBy): array
    {
        $keys = [];
        foreach ($sortBy as $key) {
            $key = (string) $key;
            if (in_array($key, self::SORT_KEYS, true) && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys !== [] ? $keys : ['lastName', 'firstName'];
    }

    /**
     * @param list<int> $departmentIds
     */
    private function scopeLabel(string $scope, array $departmentIds): string
    {
        if ($scope !== self::SCOPE_DEPARTMENT) {
            return 'Entire company';
        }
        if ($departmentIds === []) {
            return 'All departments';
        }

        $names = Department::query()->whereIn('id', $departmentIds)->orderBy('name')->pluck('name')->all();

        return 'Departments — '.implode(', ', $names);
    }

    /**
     * @return array{name: string, lines: list<string>, logoUrl: string|null}
     */
    private function companyHeader(): array
    {
        $company = Company::query()->with('locality.district')->first();

        return [
            'name' => trim((string) ($company?->legalName ?? '')) !== '' ? (string) $company->legalName : 'Company',
            'lines' => [],
            'logoUrl' => $company?->logoUrl,
        ];
    }

    private function formatSocialSecurity(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
        if ($digits === '') {
            return '';
        }

        return str_pad(substr($digits, -9), 9, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<Carbon>
     */
    private function thursdaysBetween(Carbon $startDate, Carbon $endDate): array
    {
        $cursor = $startDate->copy()->startOfDay();
        if ($cursor->dayOfWeek !== Carbon::THURSDAY) {
            $cursor->next(Carbon::THURSDAY);
        }

        $thursdays = [];
        while ($cursor->lte($endDate)) {
            $thursdays[] = $cursor->copy();
            $cursor->addWeek();
        }

        return $thursdays;
    }
}
