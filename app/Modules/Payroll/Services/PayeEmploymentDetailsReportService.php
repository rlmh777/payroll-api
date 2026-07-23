<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Payroll;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayeEmploymentDetailsReportService
{
    private const MONTH_NAMES = [
        1 => 'January',
        2 => 'February',
        3 => 'March',
        4 => 'April',
        5 => 'May',
        6 => 'June',
        7 => 'July',
        8 => 'August',
        9 => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December',
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(int $year, ?int $month = null): array
    {
        if ($year < 2017 || $year > 2100) {
            throw new InvalidArgumentException('Year is out of the supported range.');
        }

        if ($month !== null && ($month < 1 || $month > 12)) {
            throw new InvalidArgumentException('Month must be between 1 and 12.');
        }

        [$startDate, $endDate, $periodLabel] = $this->resolvePeriod($year, $month);

        $company = Company::query()
            ->with(['locality.district'])
            ->orderBy('id')
            ->first();

        $rows = $this->aggregateEmployeeRows($startDate, $endDate);

        $totals = [
            'totalEmoluments' => round((float) $rows->sum('totalEmoluments'), 2),
            'taxableBenefits' => round((float) $rows->sum('taxableBenefits'), 2),
            'commissions' => round((float) $rows->sum('commissions'), 2),
            'taxWithheld' => round((float) $rows->sum('taxWithheld'), 2),
        ];

        return [
            'year' => $year,
            'month' => $month,
            'period' => $periodLabel,
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'submitter' => [
                'tin' => $this->formatTin($company?->taxIdentificationNumber),
                'name' => trim((string) ($company?->legalName ?? $company?->alias ?? '')),
                'address' => $this->formatCompanyAddress($company),
            ],
            'rows' => $rows->values()->all(),
            'totals' => $totals,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolvePeriod(int $year, ?int $month): array
    {
        if ($month === null) {
            return [
                Carbon::create($year, 1, 1)->startOfDay(),
                Carbon::create($year, 12, 31)->startOfDay(),
                'January - December',
            ];
        }

        $start = Carbon::create($year, $month, 1)->startOfDay();

        return [
            $start,
            $start->copy()->endOfMonth()->startOfDay(),
            self::MONTH_NAMES[$month],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function aggregateEmployeeRows(Carbon $startDate, Carbon $endDate): Collection
    {
        $payrollRows = Payroll::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll.payroll_run_id')
            ->join('pay_period_schedule', 'pay_period_schedule.id', '=', 'payroll_runs.pay_period_schedule_id')
            ->join('employee', 'employee.id', '=', 'payroll.employeeId')
            ->leftJoin('person', 'person.id', '=', 'employee.person_id')
            ->leftJoin('employment_detail', 'employment_detail.id', '=', 'payroll.employmentDetailId')
            ->whereRaw('LOWER(payroll_runs.status) = ?', ['posted'])
            ->whereDate('pay_period_schedule.start_date', '<=', $endDate->toDateString())
            ->whereDate('pay_period_schedule.end_date', '>=', $startDate->toDateString())
            ->select([
                'payroll.employeeId as employee_id',
                DB::raw("NULLIF(TRIM(CONCAT_WS(' ', person.\"firstName\", person.\"middleName\", person.\"lastName\")), '') as employee_name"),
                'person.lastName as last_name',
                'person.firstName as first_name',
                'person.taxIdentificationNumber as tin',
                'person.socialSecurityNumber as social_security_number',
                'person.passportNumber as passport_number',
                'employment_detail.startDate as employment_start_date',
                'employment_detail.endDate as employment_end_date',
                'pay_period_schedule.start_date as period_start_date',
                'pay_period_schedule.end_date as period_end_date',
                DB::raw('COALESCE(payroll."taxableGross", payroll."grossSalary", 0) as taxable_gross'),
                DB::raw('COALESCE(payroll."tipsAmount", 0) as tips_amount'),
                DB::raw('COALESCE(payroll."bonusAmount", 0) as bonus_amount'),
                DB::raw('COALESCE(payroll."incomeTaxAmount", 0) as income_tax_amount'),
            ])
            ->get();

        if ($payrollRows->isEmpty()) {
            return collect();
        }

        return $payrollRows
            ->groupBy('employee_id')
            ->map(function (Collection $employeePayrolls) use ($startDate, $endDate) {
                $first = $employeePayrolls->first();
                $employeeName = trim((string) ($first->employee_name ?? ''));
                if ($employeeName === '') {
                    $employeeName = trim(
                        implode(' ', array_filter([
                            (string) ($first->first_name ?? ''),
                            (string) ($first->last_name ?? ''),
                        ]))
                    ) ?: 'Unknown employee';
                }

                $taxableGross = round((float) $employeePayrolls->sum('taxable_gross'), 2);
                $commissions = round(
                    (float) $employeePayrolls->sum('tips_amount') + (float) $employeePayrolls->sum('bonus_amount'),
                    2
                );
                // Keep wages and commissions as separate PAYE columns when tips/bonus are tracked.
                $totalEmoluments = round(max(0, $taxableGross - $commissions), 2);

                $weeks = $this->countWeeksEmployed(
                    $startDate,
                    $endDate,
                    $employeePayrolls,
                );

                return [
                    'employeeId' => (string) $first->employee_id,
                    'tin' => $this->formatTin($first->tin),
                    'taxpayerName' => $employeeName,
                    'socialSecurityNumber' => $this->formatSocialSecurity($first->social_security_number),
                    'passport' => $this->nullableTrim($first->passport_number),
                    'numberOfWeeksEmployed' => $weeks,
                    'totalEmoluments' => $totalEmoluments,
                    'taxableBenefits' => 0.0,
                    'commissions' => $commissions,
                    'taxWithheld' => round((float) $employeePayrolls->sum('income_tax_amount'), 2),
                ];
            })
            ->sortBy([
                fn (array $row) => $row['tin'] === '' ? 'zzzzzz' : $row['tin'],
                fn (array $row) => mb_strtolower($row['taxpayerName']),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, object>  $employeePayrolls
     */
    private function countWeeksEmployed(
        Carbon $reportStart,
        Carbon $reportEnd,
        Collection $employeePayrolls,
    ): int {
        $employmentStart = $employeePayrolls
            ->pluck('employment_start_date')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->startOfDay())
            ->sort()
            ->first();

        $employmentEnd = $employeePayrolls
            ->pluck('employment_end_date')
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

        // Prefer Mondays covered by posted pay periods the employee actually appeared in.
        $mondaySet = [];
        foreach ($employeePayrolls as $row) {
            $periodStart = Carbon::parse($row->period_start_date)->startOfDay();
            $periodEnd = Carbon::parse($row->period_end_date)->startOfDay();
            $overlapStart = $periodStart->greaterThan($rangeStart) ? $periodStart : $rangeStart;
            $overlapEnd = $periodEnd->lessThan($rangeEnd) ? $periodEnd : $rangeEnd;
            if ($overlapEnd->lt($overlapStart)) {
                continue;
            }

            $cursor = $overlapStart->copy();
            if ($cursor->dayOfWeek !== Carbon::MONDAY) {
                $cursor->next(Carbon::MONDAY);
            }
            while ($cursor->lte($overlapEnd)) {
                $mondaySet[$cursor->toDateString()] = true;
                $cursor->addWeek();
            }
        }

        if ($mondaySet !== []) {
            return count($mondaySet);
        }

        return PayPeriodHelper::countMondays($rangeStart, $rangeEnd);
    }

    private function formatCompanyAddress(?Company $company): string
    {
        if (! $company) {
            return '';
        }

        $parts = array_filter([
            $this->nullableTrim($company->street),
            $this->nullableTrim($company->locality?->name),
            $this->formatDistrictCode($company->locality?->district?->name),
        ]);

        return implode(', ', $parts);
    }

    private function formatDistrictCode(?string $districtName): ?string
    {
        $name = $this->nullableTrim($districtName);
        if ($name === null) {
            return null;
        }

        $map = [
            'cayo' => 'CYO',
            'belize' => 'BZ',
            'corozal' => 'CZL',
            'orange walk' => 'OW',
            'stann creek' => 'SC',
            'toledo' => 'TOL',
        ];

        $key = strtolower($name);

        return $map[$key] ?? strtoupper(substr(preg_replace('/\s+/', '', $name) ?: $name, 0, 3));
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

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
