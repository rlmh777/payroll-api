<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\PersonalRelief;
use Carbon\Carbon;

class IncomeTaxCalculationService
{
    /**
     * Annual income tax = (period taxable gross × periods/year − personal relief) × rate − second relief.
     * Per-period withholding = annual income tax ÷ periods/year.
     *
     * @return array{
     *     income_tax_amount: float,
     *     applied_personal_relief_id: string|null,
     *     taxable_gross: float,
     *     detail: array<string, mixed>
     * }
     */
    public function calculate(
        Employee $employee,
        Carbon $payDate,
        float $currentPeriodTaxableGross,
        ?string $frequencyName,
        ?string $excludePayrollRunId = null,
    ): array {
        unset($employee, $payDate, $excludePayrollRunId);

        $settings = PayrollSetting::current();
        $taxRate = (float) $settings->incomeTaxRate;
        $secondRelief = round((float) $settings->secondReliefAmount, 2);
        $totalPeriods = max(1, PayPeriodHelper::periodsPerYear($frequencyName));
        $periodTaxableGross = round($currentPeriodTaxableGross, 2);

        $annualGross = round($periodTaxableGross * $totalPeriods, 2);
        $relief = $this->findPersonalRelief($annualGross);
        $personalReliefAmount = round((float) ($relief?->personalRelief ?? 0), 2);
        $annualTaxableAfterRelief = max(0, round($annualGross - $personalReliefAmount, 2));
        $annualTaxBeforeSecondRelief = round($annualTaxableAfterRelief * $taxRate, 2);
        $annualTaxDue = max(0, round($annualTaxBeforeSecondRelief - $secondRelief, 2));
        $periodTax = max(0, round($annualTaxDue / $totalPeriods, 2));

        return [
            'income_tax_amount' => $periodTax,
            'applied_personal_relief_id' => $relief?->id,
            'taxable_gross' => $periodTaxableGross,
            'detail' => [
                'method' => 'annual_gross_personal_relief',
                'tax_rate' => $taxRate,
                'second_relief_amount' => $secondRelief,
                'current_period_taxable_gross' => $periodTaxableGross,
                'total_periods_in_year' => $totalPeriods,
                'annual_gross' => $annualGross,
                'personal_relief_amount' => $personalReliefAmount,
                'annual_taxable_after_relief' => $annualTaxableAfterRelief,
                'annual_tax_before_second_relief' => $annualTaxBeforeSecondRelief,
                'annual_tax_due' => $annualTaxDue,
                'applied_personal_relief' => $relief ? [
                    'id' => $relief->id,
                    'startRange' => $relief->startRange,
                    'endRange' => $relief->endRange,
                    'personalRelief' => $relief->personalRelief,
                ] : null,
            ],
        ];
    }

    private function findPersonalRelief(float $annualGross): ?PersonalRelief
    {
        return PersonalRelief::query()
            ->where('startRange', '<=', $annualGross)
            ->where(function ($query) use ($annualGross) {
                $query->whereNull('endRange')
                    ->orWhere('endRange', '>=', $annualGross);
            })
            ->orderByDesc('startRange')
            ->first();
    }
}
