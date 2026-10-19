<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\Payroll;
use App\Models\PayrollEarningCode;
use App\Modules\Core\Models\Person;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Td4SupplementaryReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_EMPLOYEE = 'employee';

    public const SORT_LAST_NAME = 'lastName';

    public const SORT_FIRST_NAME = 'firstName';

    public const SORT_DEPARTMENT = 'department';

    public const SORT_SOCIAL_SECURITY = 'socialSecurity';

    public const SORT_EMPLOYEE_TIN = 'employeeTin';

    public const SORT_WEEKS = 'weeks';

    public const SORT_TOTAL_INCOME = 'totalIncome';

    public const SORT_TAXABLE_INCOME = 'taxableIncome';

    public const SORT_NON_TAXABLE_INCOME = 'nonTaxableIncome';

    public const SORT_COMMISSIONS = 'commissions';

    public const SORT_TAX_DEDUCTED = 'taxDeducted';

    public const SORT_KEYS = [
        self::SORT_LAST_NAME,
        self::SORT_FIRST_NAME,
        self::SORT_DEPARTMENT,
        self::SORT_SOCIAL_SECURITY,
        self::SORT_EMPLOYEE_TIN,
        self::SORT_WEEKS,
        self::SORT_TOTAL_INCOME,
        self::SORT_TAXABLE_INCOME,
        self::SORT_NON_TAXABLE_INCOME,
        self::SORT_COMMISSIONS,
        self::SORT_TAX_DEDUCTED,
    ];

    /**
     * One TD4 Supplementary slip per employee for posted payroll in the date range.
     *
     * @param  list<string>  $sortBy
     * @return array<string, mixed>
     */
    public function build(
        Carbon $start,
        Carbon $end,
        string $scope,
        ?int $departmentId = null,
        ?string $employeeId = null,
        array $sortBy = [],
    ): array {
        $this->assertScope($scope, $departmentId, $employeeId);
        if ($end->lt($start)) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }

        $sortBy = $this->normalizeSortBy($sortBy);
        $payrolls = Payroll::query()
            ->with([
                'employee.person.locality.district',
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
            ->when($scope === self::SCOPE_EMPLOYEE, function ($query) use ($employeeId) {
                $query->where('employeeId', $employeeId);
            })
            ->get()
            ->groupBy(fn (Payroll $payroll) => (string) $payroll->employeeId);

        $slips = $payrolls
            ->map(fn (Collection $rows) => $this->slip($rows, $start, $end))
            ->values()
            ->all();

        usort($slips, fn (array $left, array $right) => $this->compareSlips($left, $right, $sortBy));

        return [
            'year' => (int) $end->year,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'periodLabel' => $start->format('j-M-Y').' – '.$end->format('j-M-Y'),
            'scope' => $scope,
            'scopeLabel' => $this->scopeLabel($scope, $departmentId, $slips),
            'departmentId' => $scope === self::SCOPE_DEPARTMENT ? $departmentId : null,
            'employeeId' => $scope === self::SCOPE_EMPLOYEE ? $employeeId : null,
            'sortBy' => $sortBy,
            'employer' => $this->employer(),
            'slips' => $slips,
            'employeeCount' => count($slips),
        ];
    }

    private function assertScope(string $scope, ?int $departmentId, ?string $employeeId): void
    {
        if (! in_array($scope, [self::SCOPE_COMPANY, self::SCOPE_DEPARTMENT, self::SCOPE_EMPLOYEE], true)) {
            throw new InvalidArgumentException('Scope must be company, department, or employee.');
        }
        if ($scope === self::SCOPE_DEPARTMENT && ($departmentId === null || $departmentId <= 0)) {
            throw new InvalidArgumentException('Department is required when generating by department.');
        }
        if ($scope === self::SCOPE_EMPLOYEE && ($employeeId === null || $employeeId === '')) {
            throw new InvalidArgumentException('Employee is required when generating by employee.');
        }
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
     * @param  list<array<string, mixed>>  $slips
     */
    private function scopeLabel(string $scope, ?int $departmentId, array $slips): string
    {
        if ($scope === self::SCOPE_DEPARTMENT) {
            $name = Department::query()->whereKey($departmentId)->value('name');

            return $name ? "Department — {$name}" : 'Department';
        }
        if ($scope === self::SCOPE_EMPLOYEE) {
            $name = $slips[0]['employeeName'] ?? null;

            return $name ? "Employee — {$name}" : 'Employee';
        }

        return 'Entire company';
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  list<string>  $sortBy
     */
    private function compareSlips(array $left, array $right, array $sortBy): int
    {
        foreach ($sortBy as $key) {
            $result = $this->sortValue($left, $key) <=> $this->sortValue($right, $key);
            if ($result !== 0) {
                return $result;
            }
        }

        return mb_strtolower((string) $left['lastName'].$left['firstName'])
            <=> mb_strtolower((string) $right['lastName'].$right['firstName']);
    }

    /**
     * @param  array<string, mixed>  $slip
     */
    private function sortValue(array $slip, string $key): float|string
    {
        return match ($key) {
            self::SORT_WEEKS,
            self::SORT_TOTAL_INCOME,
            self::SORT_TAXABLE_INCOME,
            self::SORT_NON_TAXABLE_INCOME,
            self::SORT_COMMISSIONS,
            self::SORT_TAX_DEDUCTED => (float) ($slip[$key] ?? 0),
            self::SORT_DEPARTMENT => mb_strtolower((string) ($slip['departmentName'] ?? '')),
            default => mb_strtolower((string) ($slip[$key] ?? '')),
        };
    }

    /**
     * @param  Collection<int, Payroll>  $payrolls
     * @return array<string, mixed>
     */
    private function slip(Collection $payrolls, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $payroll = $payrolls->first();
        $person = $payroll?->employee?->person;
        $weeks = [];
        $departments = [];
        $taxableGross = 0.0;
        $tax = 0.0;
        $commissions = 0.0;
        $commissionsExcludedFromTaxable = 0.0;
        $nonTaxable = 0.0;

        foreach ($payrolls as $row) {
            foreach ($this->mondays($row, $rangeStart, $rangeEnd) as $monday) {
                $weeks[$monday] = true;
            }

            $taxableForDepartment = round((float) ($row->taxableGross ?? $row->grossSalary ?? 0), 2);
            $departmentKey = (int) ($row->departmentId ?? 0);
            $departments[$departmentKey] = [
                'name' => trim((string) ($row->department?->name ?? '')) ?: 'Unassigned',
                'amount' => ($departments[$departmentKey]['amount'] ?? 0) + $taxableForDepartment,
            ];

            $taxable = round((float) ($row->taxableGross ?? $row->grossSalary ?? 0), 2);
            $taxableGross = round($taxableGross + $taxable, 2);
            $tax = round($tax + (float) ($row->incomeTaxAmount ?? 0), 2);

            $lines = $row->earningLines;
            if ($lines->isEmpty()) {
                $gross = round((float) ($row->grossSalary ?? $taxable), 2);
                $nonTaxable = round($nonTaxable + max(0, round($gross - $taxable, 2)), 2);
                continue;
            }

            foreach ($lines as $line) {
                $amount = round((float) ($line->amount ?? 0), 2);
                if ($this->isCommission($line->earningCode)) {
                    $commissions = round($commissions + $amount, 2);
                    if ($line->is_taxable === false) {
                        $commissionsExcludedFromTaxable = round($commissionsExcludedFromTaxable + $amount, 2);
                    }
                    continue;
                }
                if ($line->is_taxable === false) {
                    $nonTaxable = round($nonTaxable + $amount, 2);
                }
            }
        }

        $taxableIncome = round($taxableGross + $commissionsExcludedFromTaxable, 2);
        $name = $this->employeeName($person);
        uasort($departments, fn (array $left, array $right) => ($right['amount'] ?? 0) <=> ($left['amount'] ?? 0));
        $department = reset($departments);

        return [
            'employeeName' => $name,
            'firstName' => trim((string) ($person?->firstName ?? '')),
            'lastName' => trim((string) ($person?->lastName ?? '')),
            'departmentName' => is_array($department) ? (string) $department['name'] : 'Unassigned',
            'addressLines' => $person ? $this->employeeAddress($person) : [],
            'socialSecurity' => $this->formatSocialSecurity($person?->socialSecurityNumber),
            'weeks' => max(1, count($weeks)),
            'totalIncome' => round($taxableIncome + $nonTaxable, 2),
            'taxableIncome' => $taxableIncome,
            'nonTaxableIncome' => $nonTaxable,
            'commissions' => $commissions,
            'taxDeducted' => $tax,
            'employeeTin' => $this->formatTin($person?->taxIdentificationNumber),
        ];
    }

    /**
     * @return list<string>
     */
    private function mondays(Payroll $payroll, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $schedule = $payroll->payrollRun?->payPeriodSchedule;
        $start = Carbon::parse($schedule?->start_date ?? $payroll->date)->startOfDay();
        $end = Carbon::parse($schedule?->end_date ?? $payroll->date)->startOfDay();
        if ($start->lt($rangeStart)) {
            $start = $rangeStart->copy();
        }
        if ($end->gt($rangeEnd)) {
            $end = $rangeEnd->copy();
        }
        if ($end->lt($start)) {
            return [];
        }

        $cursor = $start->copy();
        if ($cursor->dayOfWeek !== Carbon::MONDAY) {
            $cursor->next(Carbon::MONDAY);
        }

        $dates = [];
        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addWeek();
        }

        return $dates;
    }

    private function isCommission(?PayrollEarningCode $code): bool
    {
        if ($code === null) {
            return false;
        }

        $haystack = strtolower(trim((string) $code->code.' '.(string) $code->name));

        return str_contains($haystack, 'commission');
    }

    private function employeeName(?Person $person): string
    {
        if ($person === null) {
            return 'Employee';
        }

        $first = trim((string) ($person->firstName ?? ''));
        $middle = trim((string) ($person->middleName ?? ''));
        $last = trim((string) ($person->lastName ?? ''));
        $initial = $middle !== '' ? mb_strtoupper(mb_substr($middle, 0, 1)) : '';
        $name = trim($first.($initial !== '' ? ' '.$initial : '').($last !== '' ? ' '.$last : ''));

        return $name !== '' ? $name : 'Employee';
    }

    /**
     * @return list<string>
     */
    private function employeeAddress(Person $person): array
    {
        $lines = [];
        foreach (['address1', 'address2'] as $field) {
            $value = trim((string) ($person->{$field} ?? ''));
            if ($value !== '') {
                $lines[] = $value;
            }
        }

        $locality = trim((string) ($person->locality?->name ?? ''));
        $district = trim((string) ($person->locality?->district?->name ?? ''));
        if ($locality !== '' && ! $this->alreadyListed($locality, $lines)) {
            $lines[] = $locality;
        }
        if ($district !== '' && ! $this->alreadyListed($district, $lines)) {
            $lines[] = $district;
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private function alreadyListed(string $value, array $lines): bool
    {
        $needle = mb_strtolower($value);
        foreach ($lines as $line) {
            if (mb_strtolower($line) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, addressLines: list<string>, tin: string}
     */
    private function employer(): array
    {
        $company = Company::query()->with('locality.district.country')->first();
        $alias = trim((string) ($company?->alias ?? ''));
        $legal = trim((string) ($company?->legalName ?? ''));
        $name = $alias !== '' ? $alias : ($legal !== '' ? $legal : 'Company');

        $lines = [];
        $street = trim((string) ($company?->street ?? ''));
        if ($street !== '') {
            $lines[] = $street;
        }
        $locality = trim((string) ($company?->locality?->name ?? ''));
        if ($locality !== '') {
            $lines[] = rtrim($locality, ',').',';
        }
        $country = trim((string) ($company?->locality?->district?->country?->name ?? ''));
        $lines[] = rtrim($country !== '' ? $country : 'Belize', '.').'.';

        return [
            'name' => $name,
            'addressLines' => $lines,
            'tin' => $this->formatTin($company?->taxIdentificationNumber),
        ];
    }

    private function formatTin(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
        if ($digits === '') {
            return '';
        }

        return str_pad(substr($digits, -6), 6, '0', STR_PAD_LEFT);
    }

    private function formatSocialSecurity(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
        if ($digits === '') {
            return '';
        }

        return str_pad(substr($digits, -9), 9, '0', STR_PAD_LEFT);
    }
}
