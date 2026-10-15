<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Support\TaxCalculatorLineBasis;

class TaxCalculatorService
{
    public const CATEGORY_BUSINESS_TAX = 'business_tax';

    public const CATEGORY_GST = 'gst';

    public const CATEGORY_BTB = 'btb';

    public const CATEGORY_ADJUSTMENT = 'adjustment';

    public const BUSINESS_INCOME = 'BUSINESS_INCOME';

    public const BUSINESS_PROFESSIONAL_SERVICES = 'BUSINESS_PROFESSIONAL_SERVICES';

    public const BUSINESS_TOUR_OPERATOR = 'BUSINESS_TOUR_OPERATOR';

    public const BUSINESS_DIVIDEND = 'BUSINESS_DIVIDEND';

    public const GST_INCOME = 'GST_INCOME';

    public const GST_EXEMPT_INCOME = 'GST_EXEMPT_INCOME';

    public const GST_ZERO_INCOME = 'GST_ZERO_INCOME';

    public const BTB_HOTEL_TAX = 'BTB_HOTEL_TAX';

    public const GST_PARTIAL_EXEMPTION = 'GST_PARTIAL_EXEMPTION';

    public const GST_INPUT_RECOVERY_MULTIPLIER = 'GST_INPUT_RECOVERY_MULTIPLIER';

    /**
     * @param  array<string, float>  $ratesByCode
     * @param  list<array{
     *     amount: float|int|string|null,
     *     business_tax_code?: string|null,
     *     gst_code?: string|null,
     *     business_tax_codes?: list<string>,
     *     gst_codes?: list<string>,
     *     tax_rate_assignments?: list<array{code: string, tax_basis: string, category: string}>,
     *     include_btb?: bool
     * }>  $lines
     * @return array<string, mixed>
     */
    public function calculate(
        array $ratesByCode,
        array $lines,
        float $totalDebits,
        float $partialExemptionsTotal,
        float $line220 = 0.0,
        float $netOf2251 = 0.0,
    ): array {
        $customBusiness = [];
        $businessIncome = 0.0;
        $professionalServices = 0.0;
        $tourOperator = 0.0;
        $dividend = 0.0;
        $gstIncome = 0.0;
        $gstExempt = 0.0;
        $gstZero = 0.0;
        $btbBase = 0.0;

        foreach ($lines as $line) {
            if (in_array($line['row_type'] ?? 'account', ['heading', 'grand_total'], true)) {
                continue;
            }

            if (! TaxCalculatorLineBasis::hasTaxAssignments($line)) {
                if (($line['include_in_tax'] ?? true) === false) {
                    continue;
                }
            }

            $amount = $this->toFloat($line['amount'] ?? 0);

            foreach ($this->lineBusinessTaxCodes($line) as $businessCode) {
                match ($businessCode) {
                    self::BUSINESS_INCOME => $businessIncome += $amount,
                    self::BUSINESS_PROFESSIONAL_SERVICES => $professionalServices += $amount,
                    self::BUSINESS_TOUR_OPERATOR => $tourOperator += $amount,
                    self::BUSINESS_DIVIDEND => $dividend += $amount,
                    default => $this->addCustomBusiness($customBusiness, $businessCode, $amount),
                };
            }

            foreach ($this->lineGstCodes($line) as $gstCode) {
                match ($gstCode) {
                    self::GST_INCOME => $gstIncome += $amount,
                    self::GST_EXEMPT_INCOME => $gstExempt += $amount,
                    self::GST_ZERO_INCOME => $gstZero += $amount,
                    default => null,
                };
            }

            if (! empty($line['include_btb'])) {
                $btbBase += $amount;
            }
        }

        $businessIncomeRate = $this->rate($ratesByCode, self::BUSINESS_INCOME, 0.0175);
        $professionalServicesRate = $this->rate($ratesByCode, self::BUSINESS_PROFESSIONAL_SERVICES, 0.06);
        $tourOperatorRate = $this->rate($ratesByCode, self::BUSINESS_TOUR_OPERATOR, 0.06);
        $dividendRate = $this->rate($ratesByCode, self::BUSINESS_DIVIDEND, 0.15);
        $gstIncomeRate = $this->rate($ratesByCode, self::GST_INCOME, 0.125);
        $btbRate = $this->rate($ratesByCode, self::BTB_HOTEL_TAX, 0.09);
        $partialExemptionRate = $this->rate($ratesByCode, self::GST_PARTIAL_EXEMPTION, 0.08);
        $inputRecoveryMultiplier = $this->rate($ratesByCode, self::GST_INPUT_RECOVERY_MULTIPLIER, 8.0);

        $customBusinessTax = 0.0;
        foreach ($customBusiness as $code => $amount) {
            $customBusinessTax += $amount * $this->rate($ratesByCode, $code, 0.0);
        }

        $businessIncomeTax = $businessIncome * $businessIncomeRate;
        $professionalServicesTax = $professionalServices * $professionalServicesRate;
        $tourOperatorTax = $tourOperator * $tourOperatorRate;
        $dividendTax = $dividend * $dividendRate;
        $businessTaxTotal = $businessIncomeTax + $professionalServicesTax + $tourOperatorTax + $dividendTax + $customBusinessTax;

        $line100 = $gstIncome;
        $line110 = $gstZero;
        $line120 = $gstExempt;
        $line130 = $line100 + $line110 + $line120;
        $line140 = $line100 * $gstIncomeRate;

        $taxableAndExempt = $gstIncome + $gstExempt;
        $taxableToTotalRatio = $taxableAndExempt != 0.0
            ? $gstIncome / $taxableAndExempt
            : 0.0;
        $zeroRatio = 0.0;
        $exemptRatio = $taxableAndExempt != 0.0
            ? $gstExempt / $taxableAndExempt
            : 0.0;

        $partialIncome = $partialExemptionsTotal * $taxableToTotalRatio;
        $partialIncomeCredit = -$partialIncome;
        $line250 = $totalDebits + $partialIncomeCredit;
        $line210 = $line250 * $inputRecoveryMultiplier;
        $line230 = $partialExemptionRate != 0.0
            ? $partialIncomeCredit / -$partialExemptionRate
            : 0.0;
        $line300 = $line140 - $line250;
        $line360 = $line300;
        $btbTax = $btbBase * $btbRate;
        $combinedTotal = $businessTaxTotal + $line360 + $btbTax;

        $customRows = [];
        foreach ($customBusiness as $code => $amount) {
            $customRows[] = [
                'code' => $code,
                'amount' => $this->money($amount),
                'tax' => $this->money($amount * $this->rate($ratesByCode, $code, 0.0)),
            ];
        }

        return [
            'rates' => [
                self::BUSINESS_INCOME => $businessIncomeRate,
                self::BUSINESS_PROFESSIONAL_SERVICES => $professionalServicesRate,
                self::BUSINESS_TOUR_OPERATOR => $tourOperatorRate,
                self::BUSINESS_DIVIDEND => $dividendRate,
                self::GST_INCOME => $gstIncomeRate,
                self::BTB_HOTEL_TAX => $btbRate,
                self::GST_PARTIAL_EXEMPTION => $partialExemptionRate,
                self::GST_INPUT_RECOVERY_MULTIPLIER => $inputRecoveryMultiplier,
            ],
            'business_tax' => [
                'income' => $this->money($businessIncome),
                'professional_services' => $this->money($professionalServices),
                'tour_operator' => $this->money($tourOperator),
                'dividend' => $this->money($dividend),
                'income_tax' => $this->money($businessIncomeTax),
                'professional_services_tax' => $this->money($professionalServicesTax),
                'tour_operator_tax' => $this->money($tourOperatorTax),
                'dividend_tax' => $this->money($dividendTax),
                'custom' => $customRows,
                'total_tax' => $this->money($businessTaxTotal),
                'iris' => [
                    'line_10' => $this->money($businessIncome),
                    'line_20' => $this->money($professionalServices),
                    'line_120' => $this->money($tourOperator),
                    'line_110' => $this->money($dividend),
                ],
            ],
            'gst' => [
                'line_100' => $this->money($line100),
                'line_110' => $this->money($line110),
                'line_120' => $this->money($line120),
                'line_130' => $this->money($line130),
                'line_140' => $this->money($line140),
                'line_210' => $this->money($line210),
                'line_220' => $this->money($line220),
                'line_230' => $this->money($line230),
                'line_250' => $this->money($line250),
                'line_300' => $this->money($line300),
                'line_360' => $this->money($line360),
            ],
            'btb' => [
                'base' => $this->money($btbBase),
                'tax' => $this->money($btbTax),
            ],
            'partial_exemption' => [
                'gst_income' => $this->money($gstIncome),
                'zero_rated_income' => $this->money($gstZero),
                'exempt_income' => $this->money($gstExempt),
                'total_value' => $this->money($taxableAndExempt),
                'gst_income_ratio' => $this->ratio($taxableToTotalRatio),
                'zero_rated_ratio' => $this->ratio($zeroRatio),
                'exempt_ratio' => $this->ratio($exemptRatio),
                'partial_exemptions_total' => $this->money($partialExemptionsTotal),
                'ratio_times_partial_exemptions' => $this->money($partialIncome),
                'total_debits' => $this->money($totalDebits),
                'gst_value_entered' => $this->money($totalDebits),
                'less_partial_exemptions' => $this->money($partialIncomeCredit),
                'line_250' => $this->money($line250),
                'gst_rate' => $gstIncomeRate,
                'gst_due_before_exemptions' => $this->money($line140),
                'invoices_less_exemptions' => $this->money(-$line250),
                'net_gst_due' => $this->money($line300),
                'net_of_2251' => $this->money($netOf2251),
                'additional_liability' => $this->money($line300 - $netOf2251),
            ],
            'combined_total' => $this->money($combinedTotal),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<string>
     */
    private function lineBusinessTaxCodes(array $line): array
    {
        $codes = [];
        foreach (TaxCalculatorLineBasis::assignments($line) as $assignment) {
            if (($assignment['category'] ?? '') === 'business_tax') {
                $codes[] = (string) $assignment['code'];
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<string, mixed>  $line
     * @return list<string>
     */
    private function lineGstCodes(array $line): array
    {
        $codes = [];
        foreach (TaxCalculatorLineBasis::assignments($line) as $assignment) {
            if (($assignment['category'] ?? '') === 'gst') {
                $codes[] = (string) $assignment['code'];
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<string, float>  $customBusiness
     */
    private function addCustomBusiness(array &$customBusiness, ?string $code, float $amount): void
    {
        if ($code === null || $code === '') {
            return;
        }

        $customBusiness[$code] = ($customBusiness[$code] ?? 0) + $amount;
    }

    /**
     * @param  array<string, float>  $ratesByCode
     */
    private function rate(array $ratesByCode, string $code, float $fallback): float
    {
        if (! array_key_exists($code, $ratesByCode) || $ratesByCode[$code] === null) {
            return $fallback;
        }

        return $this->toFloat($ratesByCode[$code]);
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }

    private function money(float $value): float
    {
        return round($value, 6);
    }

    private function ratio(float $value): float
    {
        return round($value, 10);
    }
}
