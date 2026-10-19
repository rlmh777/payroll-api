<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\Payroll;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PayeReportService
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_EMPLOYEE = 'employee';

    /**
     * @return array<string, mixed>
     */
    public function build(
        Carbon $startDate,
        Carbon $endDate,
        string $scope,
        ?int $departmentId = null,
        ?string $employeeId = null,
    ): array {
        $this->assertScope($scope, $departmentId, $employeeId);
        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }

        $payrolls = Payroll::query()
            ->with([
                'employee.person',
                'department:id,name',
                'payrollRun.payPeriodSchedule',
                'employmentDetail',
            ])
            ->whereDate('date', '>=', $startDate->toDateString())
            ->whereDate('date', '<=', $endDate->toDateString())
            ->whereHas('payrollRun', function ($query) {
                $query->whereRaw("LOWER(COALESCE(status, '')) = 'posted'");
            })
            ->when($scope === self::SCOPE_DEPARTMENT, function ($query) use ($departmentId) {
                $query->where('departmentId', $departmentId);
            })
            ->when($scope === self::SCOPE_EMPLOYEE, function ($query) use ($employeeId) {
                $query->where('employeeId', $employeeId);
            })
            ->get();

        $employees = [];
        foreach ($payrolls as $payroll) {
            if (! $payroll->employee) {
                continue;
            }
            $key = (string) $payroll->employeeId;
            if (! isset($employees[$key])) {
                $person = $payroll->employee->person;
                $employees[$key] = [
                    'employeeId' => $key,
                    'employeeCode' => (string) ($payroll->employee->code ?? ''),
                    'firstName' => trim((string) ($person?->firstName ?? '')),
                    'lastName' => trim((string) ($person?->lastName ?? '')),
                    'displayName' => $this->displayName($person?->firstName, $person?->lastName),
                    'tin' => $this->formatTin($person?->taxIdentificationNumber ?? null),
                    'socialSecurityNumber' => $this->formatSocialSecurity($person?->socialSecurityNumber ?? null),
                    'departmentHours' => [],
                    'payrolls' => [],
                ];
            }
            $employees[$key]['payrolls'][] = $payroll;
            $departmentKey = $payroll->departmentId ?? 0;
            $taxable = (float) ($payroll->taxableGross ?? $payroll->grossSalary ?? 0);
            $employees[$key]['departmentHours'][$departmentKey] = [
                'name' => trim((string) ($payroll->department?->name ?? '')) ?: 'Unassigned',
                'amount' => ($employees[$key]['departmentHours'][$departmentKey]['amount'] ?? 0) + $taxable,
            ];
        }

        $rows = [];
        foreach ($employees as $employee) {
            /** @var Collection<int, Payroll> $employeePayrolls */
            $employeePayrolls = collect($employee['payrolls']);
            $taxableGross = round((float) $employeePayrolls->sum(fn (Payroll $payroll) => (float) ($payroll->taxableGross ?? $payroll->grossSalary ?? 0)), 2);
            $commissions = round((float) $employeePayrolls->sum(fn (Payroll $payroll) => (float) ($payroll->tipsAmount ?? 0) + (float) ($payroll->bonusAmount ?? 0)), 2);
            $department = $this->primaryDepartment($employee['departmentHours']);
            $rows[] = [
                'employeeId' => $employee['employeeId'],
                'employeeCode' => $employee['employeeCode'],
                'firstName' => $employee['firstName'],
                'lastName' => $employee['lastName'],
                'displayName' => $employee['displayName'],
                'departmentName' => $department,
                'tin' => $employee['tin'],
                'socialSecurityNumber' => $employee['socialSecurityNumber'],
                'weeksEmployed' => $this->countWeeksEmployed($startDate, $endDate, $employeePayrolls),
                'taxableGross' => $taxableGross,
                'totalEmoluments' => round(max(0, $taxableGross - $commissions), 2),
                'commissions' => $commissions,
                'taxWithheld' => round((float) $employeePayrolls->sum(fn (Payroll $payroll) => (float) ($payroll->incomeTaxAmount ?? 0)), 2),
            ];
        }

        usort($rows, function (array $left, array $right) {
            return [
                mb_strtolower($left['departmentName']),
                mb_strtolower($left['lastName']),
                mb_strtolower($left['firstName']),
                mb_strtolower($left['employeeCode']),
            ] <=> [
                mb_strtolower($right['departmentName']),
                mb_strtolower($right['lastName']),
                mb_strtolower($right['firstName']),
                mb_strtolower($right['employeeCode']),
            ];
        });

        $totals = [
            'weeksEmployed' => (int) array_sum(array_column($rows, 'weeksEmployed')),
            'taxableGross' => round((float) array_sum(array_column($rows, 'taxableGross')), 2),
            'totalEmoluments' => round((float) array_sum(array_column($rows, 'totalEmoluments')), 2),
            'commissions' => round((float) array_sum(array_column($rows, 'commissions')), 2),
            'taxWithheld' => round((float) array_sum(array_column($rows, 'taxWithheld')), 2),
        ];

        return [
            'title' => 'PAYE',
            'company' => $this->companyHeader(),
            'periodLabel' => 'From: '.$startDate->format('j-M-Y').' To: '.$endDate->format('j-M-Y'),
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'scope' => $scope,
            'scopeLabel' => $this->scopeLabel($scope, $departmentId, $employeeId, $rows),
            'departmentId' => $scope === self::SCOPE_DEPARTMENT ? $departmentId : null,
            'employeeId' => $scope === self::SCOPE_EMPLOYEE ? $employeeId : null,
            'rows' => $rows,
            'totals' => $totals,
            'employeeCount' => count($rows),
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
     * @param array<int, array{name: string, amount: float}> $amounts
     */
    private function primaryDepartment(array $amounts): string
    {
        if ($amounts === []) {
            return 'Unassigned';
        }
        uasort($amounts, fn (array $left, array $right) => ($right['amount'] ?? 0) <=> ($left['amount'] ?? 0));
        $department = reset($amounts);

        return $department['name'] !== '' ? $department['name'] : 'Unassigned';
    }

    /**
     * @param Collection<int, Payroll> $payrolls
     */
    private function countWeeksEmployed(Carbon $reportStart, Carbon $reportEnd, Collection $payrolls): int
    {
        $employmentStart = $payrolls
            ->map(fn (Payroll $payroll) => $payroll->employmentDetail?->startDate)
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->startOfDay())
            ->sort()
            ->first();
        $employmentEnd = $payrolls
            ->map(fn (Payroll $payroll) => $payroll->employmentDetail?->endDate)
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->startOfDay())
            ->sort()
            ->last();

        $rangeStart = $reportStart->copy();
        $rangeEnd = $reportEnd->copy();
        if ($employmentStart instanceof Carbon && $employmentStart->gt($rangeStart)) {
            $rangeStart = $employmentStart->copy();
        }
        if ($employmentEnd instanceof Carbon && $employmentEnd->lt($rangeEnd)) {
            $rangeEnd = $employmentEnd->copy();
        }

        $mondays = [];
        foreach ($payrolls as $payroll) {
            $schedule = $payroll->payrollRun?->payPeriodSchedule;
            $periodStart = Carbon::parse($schedule?->start_date ?? $payroll->date)->startOfDay();
            $periodEnd = Carbon::parse($schedule?->end_date ?? $payroll->date)->startOfDay();
            $overlapStart = $periodStart->greaterThan($rangeStart) ? $periodStart->copy() : $rangeStart->copy();
            $overlapEnd = $periodEnd->lessThan($rangeEnd) ? $periodEnd->copy() : $rangeEnd->copy();
            if ($overlapEnd->lt($overlapStart)) {
                continue;
            }
            $cursor = $overlapStart->copy();
            if ($cursor->dayOfWeek !== Carbon::MONDAY) {
                $cursor->next(Carbon::MONDAY);
            }
            while ($cursor->lte($overlapEnd)) {
                $mondays[$cursor->toDateString()] = true;
                $cursor->addWeek();
            }
        }

        return count($mondays);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function scopeLabel(string $scope, ?int $departmentId, ?string $employeeId, array $rows): string
    {
        if ($scope === self::SCOPE_DEPARTMENT) {
            $name = Department::query()->whereKey($departmentId)->value('name');

            return $name ? "Department — {$name}" : 'Department';
        }
        if ($scope === self::SCOPE_EMPLOYEE) {
            $name = $rows[0]['displayName'] ?? null;

            return $name ? "Employee — {$name}" : 'Employee';
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
