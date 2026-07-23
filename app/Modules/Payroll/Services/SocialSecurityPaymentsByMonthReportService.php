<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Payroll;
use App\Models\SocialSecurityPaymentReport;
use App\Models\SocialSecurityPaymentReportLine;
use App\Services\SocialSecurity\SocialSecurityContributionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SocialSecurityPaymentsByMonthReportService
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

    public function __construct(
        private readonly SocialSecurityContributionService $socialSecurityContributionService,
    ) {
    }

    /**
     * Years/months that overlap at least one posted payroll run schedule.
     *
     * @return array{
     *   years: list<int>,
     *   monthsByYear: array<string, list<array{value: int, label: string}>>
     * }
     */
    public function availablePeriods(): array
    {
        $schedules = DB::table('payroll_runs')
            ->join('pay_period_schedule', 'pay_period_schedule.id', '=', 'payroll_runs.pay_period_schedule_id')
            ->whereRaw('LOWER(payroll_runs.status) = ?', ['posted'])
            ->select([
                'pay_period_schedule.start_date',
                'pay_period_schedule.end_date',
            ])
            ->get();

        $monthsByYear = [];

        foreach ($schedules as $schedule) {
            $cursor = Carbon::parse($schedule->start_date)->startOfMonth();
            $end = Carbon::parse($schedule->end_date)->startOfMonth();

            while ($cursor->lte($end)) {
                $year = (int) $cursor->year;
                $month = (int) $cursor->month;
                $monthsByYear[$year][$month] = self::MONTH_NAMES[$month];
                $cursor->addMonth();
            }
        }

        krsort($monthsByYear);

        $years = array_map('intval', array_keys($monthsByYear));
        $serializedMonths = [];

        foreach ($monthsByYear as $year => $months) {
            krsort($months);
            $serializedMonths[(string) $year] = collect($months)
                ->map(fn (string $label, int $value) => [
                    'value' => $value,
                    'label' => $label,
                ])
                ->values()
                ->all();
        }

        return [
            'years' => $years,
            'monthsByYear' => $serializedMonths,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $year, int $month): array
    {
        $this->assertPeriod($year, $month);

        $report = SocialSecurityPaymentReport::query()
            ->with('lines')
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        return $this->serializeReport($report, $year, $month);
    }

    /**
     * @return array<string, mixed>
     */
    public function recalculate(int $year, int $month, ?string $calculatedBy = null): array
    {
        $this->assertPeriod($year, $month);

        $lines = $this->buildLines($year, $month);

        return DB::transaction(function () use ($year, $month, $calculatedBy, $lines) {
            $report = SocialSecurityPaymentReport::query()->firstOrNew([
                'year' => $year,
                'month' => $month,
            ]);

            $report->calculated_at = now();
            $report->calculated_by = $calculatedBy;
            $report->save();

            SocialSecurityPaymentReportLine::query()
                ->where('report_id', $report->id)
                ->delete();

            foreach ($lines as $index => $line) {
                SocialSecurityPaymentReportLine::query()->create([
                    ...$line,
                    'report_id' => $report->id,
                    'sort_order' => $index + 1,
                ]);
            }

            $report->load('lines');

            return $this->serializeReport($report, $year, $month);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLines(int $year, int $month): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        $monthName = self::MONTH_NAMES[$month];
        $mondaysInMonth = $this->mondaysBetween($monthStart, $monthEnd);
        $mondaysInMonthCount = max(1, count($mondaysInMonth));

        $company = Company::query()->orderBy('id')->first();
        $companySs = $this->formatCompanySs($company?->socialSecurityNumber);
        $electronicEmployerNumber = trim((string) ($company?->socialSecurityElectronicEmployerNumber ?? ''));

        $payrollRows = Payroll::query()
            ->with([
                'employee.person',
                'employmentDetail',
                'payrollRun.payPeriodSchedule',
                'payrollRun.payrateFrequency',
            ])
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll.payroll_run_id')
            ->join('pay_period_schedule', 'pay_period_schedule.id', '=', 'payroll_runs.pay_period_schedule_id')
            ->whereRaw('LOWER(payroll_runs.status) = ?', ['posted'])
            ->whereDate('pay_period_schedule.start_date', '<=', $monthEnd->toDateString())
            ->whereDate('pay_period_schedule.end_date', '>=', $monthStart->toDateString())
            ->select('payroll.*')
            ->get();

        if ($payrollRows->isEmpty() || $mondaysInMonth === []) {
            return [];
        }

        $hireDatesByEmployee = $this->loadHireDates(
            $payrollRows->pluck('employeeId')->unique()->filter()->values()->all()
        );

        $lines = [];

        foreach ($mondaysInMonth as $monday) {
            $weekNumber = (int) $monday->isoWeek;

            foreach ($payrollRows as $payroll) {
                $schedule = $payroll->payrollRun?->payPeriodSchedule;
                if (! $schedule) {
                    continue;
                }

                $periodStart = Carbon::parse($schedule->start_date)->startOfDay();
                $periodEnd = Carbon::parse($schedule->end_date)->startOfDay();
                if ($monday->lt($periodStart) || $monday->gt($periodEnd)) {
                    continue;
                }

                $employee = $payroll->employee;
                if (! $employee) {
                    continue;
                }

                $frequencyName = strtolower(trim((string) ($payroll->payrollRun?->payrateFrequency?->name ?? '')));
                $periodGross = (float) ($payroll->taxableGross ?? $payroll->grossSalary ?? 0);
                $divisor = $this->weeklyGrossDivisor(
                    $frequencyName,
                    $periodStart,
                    $periodEnd,
                    $mondaysInMonthCount,
                );
                $weeklyGross = round($periodGross / $divisor, 2);

                $payDate = Carbon::parse($schedule->pay_date ?? $schedule->end_date)->startOfDay();
                $ss = $this->socialSecurityContributionService->calculate(
                    $employee,
                    $payDate,
                    $weeklyGross,
                    1.0,
                );
                $ssAmount = round(
                    (float) ($ss['employee_amount'] ?? 0) + (float) ($ss['employer_amount'] ?? 0),
                    2,
                );

                $hireDate = $hireDatesByEmployee->get((string) $employee->id)
                    ?? ($payroll->employmentDetail?->startDate
                        ? Carbon::parse($payroll->employmentDetail->startDate)->startOfDay()
                        : null);

                $lines[] = [
                    'employee_id' => (string) $employee->id,
                    'payroll_id' => (string) $payroll->id,
                    'payroll_run_id' => $payroll->payroll_run_id ? (string) $payroll->payroll_run_id : null,
                    'employee_social_security_number' => $this->formatEmployeeSs($employee->socialSecurityNumber),
                    'company_social_security_number' => $companySs,
                    'year' => $year,
                    'month_name' => $monthName,
                    'calendar_week' => $weekNumber,
                    'week_monday_date' => $monday->toDateString(),
                    'weekly_gross_pay' => $weeklyGross,
                    'social_security_amount' => $ssAmount,
                    'electronic_employer_number' => $electronicEmployerNumber,
                    'date_hired' => $hireDate?->toDateString(),
                    'first_name' => trim((string) ($employee->firstName ?? '')),
                    'last_name' => trim((string) ($employee->lastName ?? '')),
                    'record_code' => 'P',
                ];
            }
        }

        usort($lines, function (array $a, array $b): int {
            return [$a['employee_social_security_number'], $a['calendar_week'], $a['last_name'], $a['first_name']]
                <=> [$b['employee_social_security_number'], $b['calendar_week'], $b['last_name'], $b['first_name']];
        });

        return $lines;
    }

    private function weeklyGrossDivisor(
        string $frequencyName,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $mondaysInMonthCount,
    ): int {
        if (str_contains($frequencyName, 'month')) {
            return max(1, $mondaysInMonthCount);
        }

        return max(1, PayPeriodHelper::countMondays($periodStart, $periodEnd));
    }

    /**
     * @return list<Carbon>
     */
    private function mondaysBetween(Carbon $start, Carbon $end): array
    {
        $cursor = $start->copy()->startOfDay();
        if ($cursor->dayOfWeek !== Carbon::MONDAY) {
            $cursor->next(Carbon::MONDAY);
        }

        $mondays = [];
        while ($cursor->lte($end)) {
            $mondays[] = $cursor->copy();
            $cursor->addWeek();
        }

        return $mondays;
    }

    /**
     * @param  list<string>  $employeeIds
     * @return Collection<string, Carbon>
     */
    private function loadHireDates(array $employeeIds): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        return DB::table('employment_detail')
            ->whereIn('employeeId', $employeeIds)
            ->whereNotNull('startDate')
            ->select('employeeId', DB::raw('MIN("startDate") as hire_date'))
            ->groupBy('employeeId')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->employeeId => Carbon::parse($row->hire_date)->startOfDay(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReport(?SocialSecurityPaymentReport $report, int $year, int $month): array
    {
        $monthName = self::MONTH_NAMES[$month];

        if (! $report) {
            return [
                'id' => null,
                'year' => $year,
                'month' => $month,
                'monthName' => $monthName,
                'calculatedAt' => null,
                'rows' => [],
                'totals' => [
                    'weeklyGrossPay' => 0,
                    'socialSecurityAmount' => 0,
                    'rowCount' => 0,
                ],
            ];
        }

        $rows = $report->lines->map(function (SocialSecurityPaymentReportLine $line) {
            return [
                'id' => $line->id,
                'employeeId' => $line->employee_id,
                'employeeSocialSecurityNumber' => $line->employee_social_security_number,
                'companySocialSecurityNumber' => $line->company_social_security_number,
                'year' => $line->year,
                'monthName' => $line->month_name,
                'calendarWeek' => $line->calendar_week,
                'weekMondayDate' => optional($line->week_monday_date)?->toDateString(),
                'weeklyGrossPay' => (float) $line->weekly_gross_pay,
                'socialSecurityAmount' => (float) $line->social_security_amount,
                'electronicEmployerNumber' => $line->electronic_employer_number,
                'dateHired' => optional($line->date_hired)?->format('d-m-Y'),
                'blank' => '',
                'firstName' => $line->first_name,
                'lastName' => $line->last_name,
                'recordCode' => $line->record_code ?: 'P',
            ];
        })->values()->all();

        return [
            'id' => $report->id,
            'year' => $report->year,
            'month' => $report->month,
            'monthName' => $monthName,
            'calculatedAt' => optional($report->calculated_at)?->toIso8601String(),
            'rows' => $rows,
            'totals' => [
                'weeklyGrossPay' => round((float) collect($rows)->sum('weeklyGrossPay'), 2),
                'socialSecurityAmount' => round((float) collect($rows)->sum('socialSecurityAmount'), 2),
                'rowCount' => count($rows),
            ],
        ];
    }

    private function assertPeriod(int $year, int $month): void
    {
        if ($year < 2017 || $year > 2100) {
            throw new InvalidArgumentException('Year is out of the supported range.');
        }

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Month must be between 1 and 12.');
        }
    }

    private function formatEmployeeSs(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
        if ($digits === '') {
            return '';
        }

        return str_pad(substr($digits, -9), 9, '0', STR_PAD_LEFT);
    }

    private function formatCompanySs(mixed $value): string
    {
        return strtoupper(trim((string) ($value ?? '')));
    }
}
