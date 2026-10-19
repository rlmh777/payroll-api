<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Payroll;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Td4SummaryReportService
{
    private const CASUAL_WEEKLY_LIMIT = 200.0;

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
     * @return array<string, mixed>
     */
    public function build(int $year): array
    {
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('Year must be between 2000 and 2100.');
        }

        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->startOfDay();
        $payrolls = Payroll::query()
            ->with('payrollRun.payPeriodSchedule')
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->whereHas('payrollRun', function ($query) {
                $query->whereRaw("LOWER(COALESCE(status, '')) = 'posted'");
            })
            ->get();

        $emolumentsPaid = 0.0;
        $casualWagesPaid = 0.0;
        $incomeTaxWithheld = 0.0;

        foreach ($payrolls as $payroll) {
            $taxable = round((float) ($payroll->taxableGross ?? $payroll->grossSalary ?? 0), 2);
            $tax = round((float) ($payroll->incomeTaxAmount ?? 0), 2);
            $incomeTaxWithheld = round($incomeTaxWithheld + $tax, 2);

            if ($this->isCasualWage($payroll, $taxable, $tax)) {
                $casualWagesPaid = round($casualWagesPaid + $taxable, 2);
                continue;
            }

            $emolumentsPaid = round($emolumentsPaid + $taxable, 2);
        }

        $company = $this->employer();

        return [
            'title' => 'TD4 Summary',
            'subtitle' => 'Return of Emoluments Paid',
            'year' => $year,
            'yearEndingLabel' => 'For the year Ending December 31st, '.$year,
            'employerName' => $company['name'],
            'addressLines' => $company['addressLines'],
            'taxIdentificationNumber' => $company['taxIdentificationNumber'],
            'emolumentsPaid' => $emolumentsPaid,
            'casualWagesPaid' => $casualWagesPaid,
            'totalEmoluments' => round($emolumentsPaid + $casualWagesPaid, 2),
            'incomeTaxWithheld' => $incomeTaxWithheld,
            'generatedAt' => Carbon::now()->format('F j, Y'),
            'payrollCount' => $payrolls->count(),
        ];
    }

    private function isCasualWage(Payroll $payroll, float $taxable, float $tax): bool
    {
        if ($taxable <= 0 || abs($tax) >= 0.005) {
            return false;
        }

        $weeks = $this->weekCount($payroll);

        return round($taxable / $weeks, 2) < self::CASUAL_WEEKLY_LIMIT;
    }

    private function weekCount(Payroll $payroll): int
    {
        $schedule = $payroll->payrollRun?->payPeriodSchedule;
        $start = Carbon::parse($schedule?->start_date ?? $payroll->date)->startOfDay();
        $end = Carbon::parse($schedule?->end_date ?? $payroll->date)->startOfDay();
        if ($end->lt($start)) {
            $end = $start->copy();
        }

        $cursor = $start->copy();
        if ($cursor->dayOfWeek !== Carbon::MONDAY) {
            $cursor->next(Carbon::MONDAY);
        }

        $count = 0;
        while ($cursor->lte($end)) {
            $count++;
            $cursor->addWeek();
        }

        return max(1, $count);
    }

    /**
     * @return array{name: string, addressLines: list<string>, taxIdentificationNumber: string}
     */
    private function employer(): array
    {
        $company = Company::query()->with('locality.district.country')->first();
        $address = [];
        $street = trim((string) ($company?->street ?? ''));
        if ($street !== '') {
            $address[] = $street;
        }
        $locality = trim((string) ($company?->locality?->name ?? ''));
        if ($locality !== '') {
            $address[] = $locality;
        }
        $country = trim((string) ($company?->locality?->district?->country?->name ?? ''));
        if ($country !== '') {
            $address[] = $country;
        } elseif ($address !== []) {
            $address[] = 'Belize';
        }

        return [
            'name' => trim((string) ($company?->legalName ?? '')) !== '' ? (string) $company->legalName : 'Company',
            'addressLines' => $address,
            'taxIdentificationNumber' => $this->formatTin($company?->taxIdentificationNumber ?? null),
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
}
