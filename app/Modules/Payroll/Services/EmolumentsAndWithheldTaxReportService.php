<?php

namespace App\Modules\Payroll\Services;

use App\Models\Department;
use App\Models\Payroll;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmolumentsAndWithheldTaxReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const SORT_LAST_NAME = 'lastName';

    public const SORT_FIRST_NAME = 'firstName';

    public const SORT_TIN = 'tin';

    public const SORT_DEPARTMENT = 'department';

    public const SORT_KEYS = [
        self::SORT_LAST_NAME,
        self::SORT_FIRST_NAME,
        self::SORT_TIN,
        self::SORT_DEPARTMENT,
    ];

    private const MONTH_NAMES = [
        1 => 'JANUARY',
        2 => 'FEBRUARY',
        3 => 'MARCH',
        4 => 'APRIL',
        5 => 'MAY',
        6 => 'JUNE',
        7 => 'JULY',
        8 => 'AUGUST',
        9 => 'SEPTEMBER',
        10 => 'OCTOBER',
        11 => 'NOVEMBER',
        12 => 'DECEMBER',
    ];

    /**
     * Calendar years that contain at least one posted payroll.
     *
     * @return array{years: list<int>}
     */
    public function availableYears(): array
    {
        $years = DB::table('payroll')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll.payroll_run_id')
            ->whereNotNull('payroll.date')
            ->whereRaw("LOWER(COALESCE(payroll_runs.status, '')) = 'posted'")
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM payroll."date")::int AS year')
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->values()
            ->all();

        return ['years' => $years];
    }

    /**
     * @param  list<string>  $sortBy
     * @return array<string, mixed>
     */
    public function build(int $year, string $scope, ?int $departmentId = null, array $sortBy = []): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('Year must be between 2000 and 2100.');
        }

        if (! in_array($scope, [self::SCOPE_COMPANY, self::SCOPE_DEPARTMENT], true)) {
            throw new InvalidArgumentException('Scope must be company or department.');
        }

        if ($scope === self::SCOPE_DEPARTMENT && ($departmentId === null || $departmentId <= 0)) {
            throw new InvalidArgumentException('Department is required when generating by department.');
        }

        $sortBy = $this->normalizeSortBy($sortBy);
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->startOfDay();

        $payrolls = Payroll::query()
            ->with([
                'employee.person',
                'department:id,name',
                'payrollRun.payPeriodSchedule',
                'earningLines.earningCode',
            ])
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->whereHas('payrollRun', function ($query) {
                $query->whereRaw("LOWER(COALESCE(status, '')) = 'posted'");
            })
            ->when($scope === self::SCOPE_DEPARTMENT, function ($query) use ($departmentId) {
                $query->where('departmentId', $departmentId);
            })
            ->get();

        $employees = [];
        $activeMonths = [];

        foreach ($payrolls as $payroll) {
            if (! $payroll->employee || $payroll->date === null) {
                continue;
            }

            $month = (int) $payroll->date->month;
            if ($month < 1 || $month > 12) {
                continue;
            }

            $activeMonths[$month] = self::MONTH_NAMES[$month];
            $employeeId = (string) $payroll->employeeId;
            $employees[$employeeId] ??= $this->blankEmployee($payroll);
            $this->addPayroll($employees[$employeeId], $payroll, $month);
        }

        ksort($activeMonths);
        $monthNumbers = array_map('intval', array_keys($activeMonths));

        $rows = [];
        $totals = $this->blankTotals($monthNumbers);

        foreach ($employees as $employee) {
            $row = $this->presentEmployee($employee, $monthNumbers);
            $rows[] = $row;
            $this->accumulateTotals($totals, $row, $monthNumbers);
        }

        usort($rows, fn (array $left, array $right) => $this->compareRows($left, $right, $sortBy));

        $departmentName = null;
        if ($scope === self::SCOPE_DEPARTMENT) {
            $departmentName = trim((string) (Department::query()->whereKey($departmentId)->value('name') ?? ''));
        }

        return [
            'title' => 'Emoluments and Withheld Tax',
            'year' => $year,
            'scope' => $scope,
            'scopeLabel' => $scope === self::SCOPE_DEPARTMENT
                ? ($departmentName !== '' ? $departmentName : 'Department')
                : 'Entire company',
            'departmentId' => $scope === self::SCOPE_DEPARTMENT ? $departmentId : null,
            'sortBy' => $sortBy,
            'months' => array_map(
                fn (int $month) => ['value' => $month, 'label' => self::MONTH_NAMES[$month]],
                $monthNumbers,
            ),
            'fields' => [
                ['key' => 'weeks', 'label' => '# of Weeks'],
                ['key' => 'emoluments', 'label' => 'Total Emmoluments'],
                ['key' => 'taxableBenefit', 'label' => 'Taxble Benefit'],
                ['key' => 'commission', 'label' => 'Commission'],
                ['key' => 'taxWithheld', 'label' => 'Tax Withheld'],
            ],
            'rows' => $rows,
            'totals' => $totals,
            'employeeCount' => count($rows),
        ];
    }

    /**
     * @param  list<string>  $sortBy
     * @return list<string>
     */
    private function normalizeSortBy(array $sortBy): array
    {
        $normalized = [];
        foreach ($sortBy as $key) {
            if (! in_array($key, self::SORT_KEYS, true) || in_array($key, $normalized, true)) {
                continue;
            }
            $normalized[] = $key;
        }

        return $normalized !== [] ? $normalized : [self::SORT_LAST_NAME, self::SORT_FIRST_NAME];
    }

    /**
     * @return array<string, mixed>
     */
    private function blankEmployee(Payroll $payroll): array
    {
        $person = $payroll->employee?->person;

        return [
            'employeeId' => (string) $payroll->employeeId,
            'tin' => $this->formatTin($person?->taxIdentificationNumber ?? null),
            'name' => $this->displayName($person?->firstName ?? null, $person?->lastName ?? null),
            'firstName' => trim((string) ($person?->firstName ?? '')),
            'lastName' => trim((string) ($person?->lastName ?? '')),
            'departmentName' => '',
            'departmentTotals' => [],
            'departmentNames' => [],
            'months' => [],
            'seenRuns' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function addPayroll(array &$employee, Payroll $payroll, int $month): void
    {
        $taxable = round((float) ($payroll->taxableGross ?? $payroll->grossSalary ?? 0), 2);
        $commission = 0.0;
        $benefit = 0.0;

        foreach ($payroll->earningLines as $line) {
            $kind = $this->earningKind($line->earningCode?->name, $line->earningCode?->code);
            if ($kind === 'commission') {
                $commission = round($commission + (float) $line->amount, 2);
            } elseif ($kind === 'taxableBenefit') {
                $benefit = round($benefit + (float) $line->amount, 2);
            }
        }

        $emoluments = round(max(0, $taxable - $commission - $benefit), 2);
        $tax = round((float) ($payroll->incomeTaxAmount ?? 0), 2);
        $departmentKey = (int) ($payroll->departmentId ?? 0);
        $departmentName = trim((string) ($payroll->department?->name ?? ''));
        $employee['departmentNames'][$departmentKey] = $departmentName !== '' ? $departmentName : 'Unassigned';
        $employee['departmentTotals'][$departmentKey] = round(
            (float) ($employee['departmentTotals'][$departmentKey] ?? 0) + $emoluments,
            2,
        );

        $bucket = $employee['months'][$month] ?? $this->blankMonth();
        $runId = (string) ($payroll->payroll_run_id ?? $payroll->id);
        if (! isset($employee['seenRuns'][$month][$runId])) {
            $employee['seenRuns'][$month][$runId] = true;
            $bucket['weeks'] += $this->weeksForPayroll($payroll);
        }
        $bucket['emoluments'] = round($bucket['emoluments'] + $emoluments, 2);
        $bucket['taxableBenefit'] = round($bucket['taxableBenefit'] + $benefit, 2);
        $bucket['commission'] = round($bucket['commission'] + $commission, 2);
        $bucket['taxWithheld'] = round($bucket['taxWithheld'] + $tax, 2);
        $employee['months'][$month] = $bucket;
    }

    /**
     * @return array{weeks: int, emoluments: float, taxableBenefit: float, commission: float, taxWithheld: float}
     */
    private function blankMonth(): array
    {
        return [
            'weeks' => 0,
            'emoluments' => 0.0,
            'taxableBenefit' => 0.0,
            'commission' => 0.0,
            'taxWithheld' => 0.0,
        ];
    }

    private function weeksForPayroll(Payroll $payroll): int
    {
        $schedule = $payroll->payrollRun?->payPeriodSchedule;
        if ($schedule?->start_date === null || $schedule->end_date === null) {
            return 1;
        }

        return max(1, PayPeriodHelper::countMondays(
            Carbon::parse($schedule->start_date)->startOfDay(),
            Carbon::parse($schedule->end_date)->startOfDay(),
        ));
    }

    private function earningKind(mixed $name, mixed $code): ?string
    {
        $nameText = strtolower(trim((string) ($name ?? '')));
        $codeText = strtolower(trim((string) ($code ?? '')));
        $blob = $nameText.' '.$codeText;

        if (str_contains($blob, 'commission')) {
            return 'commission';
        }

        if (
            str_contains($blob, 'taxable benefit')
            || str_contains($blob, 'taxablebenefit')
            || in_array($codeText, ['benefit', 'taxable_benefit', 'taxable-benefit'], true)
            || in_array($nameText, ['benefit', 'taxable benefits'], true)
        ) {
            return 'taxableBenefit';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $employee
     * @param  list<int>  $monthNumbers
     * @return array<string, mixed>
     */
    private function presentEmployee(array $employee, array $monthNumbers): array
    {
        $months = [];
        $total = $this->blankMonth();

        foreach ($monthNumbers as $month) {
            $bucket = $employee['months'][$month] ?? $this->blankMonth();
            $months[(string) $month] = $bucket;
            $total['weeks'] += (int) $bucket['weeks'];
            $total['emoluments'] = round($total['emoluments'] + (float) $bucket['emoluments'], 2);
            $total['taxableBenefit'] = round($total['taxableBenefit'] + (float) $bucket['taxableBenefit'], 2);
            $total['commission'] = round($total['commission'] + (float) $bucket['commission'], 2);
            $total['taxWithheld'] = round($total['taxWithheld'] + (float) $bucket['taxWithheld'], 2);
        }

        $departmentName = '';
        $departmentAmount = -1.0;
        foreach ($employee['departmentTotals'] as $departmentKey => $amount) {
            if ((float) $amount > $departmentAmount) {
                $departmentAmount = (float) $amount;
                $departmentName = (string) ($employee['departmentNames'][$departmentKey] ?? '');
            }
        }

        return [
            'employeeId' => $employee['employeeId'],
            'tin' => $employee['tin'],
            'name' => $employee['name'],
            'firstName' => $employee['firstName'],
            'lastName' => $employee['lastName'],
            'departmentName' => $departmentName,
            'months' => $months,
            'total' => $total,
        ];
    }

    /**
     * @param  list<int>  $monthNumbers
     * @return array{months: array<string, array<string, float|int>|null>, total: array<string, float|int>}
     */
    private function blankTotals(array $monthNumbers): array
    {
        $months = [];
        foreach ($monthNumbers as $month) {
            $months[(string) $month] = $this->blankMonth();
            $months[(string) $month]['weeks'] = null;
        }

        $total = $this->blankMonth();
        $total['weeks'] = null;

        return [
            'months' => $months,
            'total' => $total,
        ];
    }

    /**
     * @param  array{months: array<string, array<string, float|int>|null>, total: array<string, float|int>}  $totals
     * @param  array<string, mixed>  $row
     * @param  list<int>  $monthNumbers
     */
    private function accumulateTotals(array &$totals, array $row, array $monthNumbers): void
    {
        foreach ($monthNumbers as $month) {
            $bucket = $row['months'][(string) $month] ?? null;
            if ($bucket === null) {
                continue;
            }
            $key = (string) $month;
            $totals['months'][$key]['emoluments'] = round($totals['months'][$key]['emoluments'] + (float) $bucket['emoluments'], 2);
            $totals['months'][$key]['taxableBenefit'] = round($totals['months'][$key]['taxableBenefit'] + (float) $bucket['taxableBenefit'], 2);
            $totals['months'][$key]['commission'] = round($totals['months'][$key]['commission'] + (float) $bucket['commission'], 2);
            $totals['months'][$key]['taxWithheld'] = round($totals['months'][$key]['taxWithheld'] + (float) $bucket['taxWithheld'], 2);
        }

        $totals['total']['emoluments'] = round($totals['total']['emoluments'] + (float) $row['total']['emoluments'], 2);
        $totals['total']['taxableBenefit'] = round($totals['total']['taxableBenefit'] + (float) $row['total']['taxableBenefit'], 2);
        $totals['total']['commission'] = round($totals['total']['commission'] + (float) $row['total']['commission'], 2);
        $totals['total']['taxWithheld'] = round($totals['total']['taxWithheld'] + (float) $row['total']['taxWithheld'], 2);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  list<string>  $sortBy
     */
    private function compareRows(array $left, array $right, array $sortBy): int
    {
        foreach ($sortBy as $key) {
            $leftValue = $this->sortValue($left, $key);
            $rightValue = $this->sortValue($right, $key);
            $compared = $leftValue <=> $rightValue;
            if ($compared !== 0) {
                return $compared;
            }
        }

        return strcasecmp((string) $left['name'], (string) $right['name']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sortValue(array $row, string $key): string
    {
        $value = match ($key) {
            self::SORT_DEPARTMENT => (string) ($row['departmentName'] ?? ''),
            self::SORT_TIN => (string) ($row['tin'] ?? ''),
            self::SORT_FIRST_NAME => (string) ($row['firstName'] ?? ''),
            default => (string) ($row['lastName'] ?? ''),
        };

        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return 'zzzzzz';
        }

        return $value;
    }

    private function displayName(mixed $firstName, mixed $lastName): string
    {
        $name = trim(implode(' ', array_filter([
            trim((string) ($firstName ?? '')),
            trim((string) ($lastName ?? '')),
        ])));

        return $name !== '' ? $name : 'Unknown employee';
    }

    private function formatTin(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
        if ($digits === '') {
            return '';
        }

        return str_pad(substr($digits, -6), 6, '0', STR_PAD_LEFT);
    }
}
