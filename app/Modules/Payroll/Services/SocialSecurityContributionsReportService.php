<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\Payroll;
use App\Services\SocialSecurity\SocialSecurityContributionService;
use Carbon\Carbon;
use InvalidArgumentException;

class SocialSecurityContributionsReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const SORT_KEYS = [
        'lastName',
        'firstName',
        'weekNumber',
        'socialSecurityNumber',
        'department',
        'weeklyEarnings',
    ];

    public function __construct(
        private readonly SocialSecurityContributionService $socialSecurityContributionService,
    ) {
    }

    /**
     * Calendar weeks whose Thursday falls in the range. Week number is the ISO week of that Thursday,
     * which is the calendar-year week used on the September sample (weeks 36–39).
     *
     * @return list<array{thursday: string, weekNumber: int, weekYear: int}>
     */
    public static function calendarWeeks(Carbon $startDate, Carbon $endDate): array
    {
        $weeks = [];
        foreach (self::thursdaysBetween($startDate, $endDate) as $thursday) {
            $weeks[] = [
                'thursday' => $thursday->toDateString(),
                'weekNumber' => (int) $thursday->isoWeek(),
                'weekYear' => (int) $thursday->isoWeekYear(),
            ];
        }

        return $weeks;
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
        $rows = $this->rows($startDate, $endDate, $scope, $departmentIds);
        $this->sortRows($rows, $sortBy);

        $groups = $scope === self::SCOPE_DEPARTMENT
            ? $this->departmentGroups($rows)
            : [[
                'name' => $company['name'],
                'rows' => $rows,
            ]];

        return [
            'title' => 'Social Security Contributions Report',
            'generatedAt' => now()->format('d/m/Y H:i:s'),
            'organization' => $company['name'],
            'company' => $company,
            'yearLabel' => $this->yearLabel($startDate, $endDate),
            'monthLabel' => $this->monthLabel($startDate, $endDate),
            'periodLabel' => $startDate->format('j-M-Y').' to '.$endDate->format('j-M-Y'),
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'scope' => $scope,
            'scopeLabel' => $this->scopeLabel($scope, $departmentIds),
            'departmentIds' => $scope === self::SCOPE_DEPARTMENT ? $departmentIds : [],
            'sortBy' => $sortBy,
            'groups' => $groups,
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'employeeCount' => count(array_unique(array_column($rows, 'employeeId'))),
        ];
    }

    /**
     * @param list<int> $departmentIds
     * @return list<array<string, mixed>>
     */
    private function rows(Carbon $startDate, Carbon $endDate, string $scope, array $departmentIds): array
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

                $key = $scope === self::SCOPE_DEPARTMENT
                    ? $employee->id.'|'.$thursday->toDateString().'|'.$departmentId
                    : $employee->id.'|'.$thursday->toDateString();

                if (! isset($combined[$key])) {
                    $combined[$key] = [
                        'employeeId' => (string) $employee->id,
                        'firstName' => trim((string) ($person?->firstName ?? '')),
                        'lastName' => trim((string) ($person?->lastName ?? '')),
                        'socialSecurityNumber' => $this->formatSocialSecurity($person?->socialSecurityNumber ?? null),
                        'weekNumber' => (int) $thursday->isoWeek(),
                        'weekYear' => (int) $thursday->isoWeekYear(),
                        'weekThursday' => $thursday->toDateString(),
                        'departmentId' => $departmentId,
                        'departmentName' => $departmentName,
                        'weeklyEarnings' => 0.0,
                        'employeeContribution' => 0.0,
                        'employerContribution' => 0.0,
                        'totalContribution' => 0.0,
                    ];
                } elseif ($weeklyEarnings > (float) $combined[$key]['weeklyEarnings']) {
                    $combined[$key]['departmentId'] = $departmentId;
                    $combined[$key]['departmentName'] = $departmentName;
                }

                $combined[$key]['weeklyEarnings'] = round((float) $combined[$key]['weeklyEarnings'] + $weeklyEarnings, 2);
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
     * @return list<Carbon>
     */
    private function periodWeeks(Carbon $periodStart, Carbon $periodEnd): array
    {
        $thursdays = self::thursdaysBetween($periodStart, $periodEnd);
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

        $periodHasThursday = self::thursdaysBetween($periodStart, $periodEnd) !== [];

        return ! $periodHasThursday
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

        return $keys !== [] ? $keys : ['lastName', 'firstName', 'weekNumber'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $sortBy
     */
    private function sortRows(array &$rows, array $sortBy): void
    {
        usort($rows, function (array $left, array $right) use ($sortBy): int {
            foreach ($sortBy as $key) {
                $comparison = match ($key) {
                    'weekNumber' => [$left['weekYear'], $left['weekNumber']] <=> [$right['weekYear'], $right['weekNumber']],
                    'weeklyEarnings' => $left['weeklyEarnings'] <=> $right['weeklyEarnings'],
                    'department' => strcasecmp((string) $left['departmentName'], (string) $right['departmentName']),
                    default => strcasecmp((string) ($left[$key] ?? ''), (string) ($right[$key] ?? '')),
                };
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return [$left['weekYear'], $left['weekNumber'], $left['lastName']]
                <=> [$right['weekYear'], $right['weekNumber'], $right['lastName']];
        });
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{name: string, rows: list<array<string, mixed>>}>
     */
    private function departmentGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $name = (string) $row['departmentName'];
            $groups[$name] ??= ['name' => $name, 'rows' => []];
            $groups[$name]['rows'][] = $row;
        }

        $grouped = array_values($groups);
        usort($grouped, fn (array $left, array $right) => strcasecmp($left['name'], $right['name']));

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, float|int>
     */
    private function totals(array $rows): array
    {
        return [
            'weeklyEarnings' => round((float) array_sum(array_column($rows, 'weeklyEarnings')), 2),
            'employeeContribution' => round((float) array_sum(array_column($rows, 'employeeContribution')), 2),
            'employerContribution' => round((float) array_sum(array_column($rows, 'employerContribution')), 2),
            'totalContribution' => round((float) array_sum(array_column($rows, 'totalContribution')), 2),
            'rowCount' => count($rows),
        ];
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

    private function yearLabel(Carbon $startDate, Carbon $endDate): string
    {
        if ($startDate->year === $endDate->year) {
            return (string) $startDate->year;
        }

        return $startDate->year.' – '.$endDate->year;
    }

    private function monthLabel(Carbon $startDate, Carbon $endDate): string
    {
        if ($startDate->format('Y-m') === $endDate->format('Y-m')) {
            return $startDate->format('F');
        }

        return $startDate->format('F').' – '.$endDate->format('F');
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

        return [
            'name' => trim((string) ($company?->legalName ?? '')) !== '' ? (string) $company->legalName : 'Company',
            'lines' => $lines,
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
    private static function thursdaysBetween(Carbon $startDate, Carbon $endDate): array
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
