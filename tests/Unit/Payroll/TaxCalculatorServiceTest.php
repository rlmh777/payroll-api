<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\TaxCalculatorService;
use Tests\TestCase;

class TaxCalculatorServiceTest extends TestCase
{
    public function test_spreadsheet_july_2026_totals(): void
    {
        $service = new TaxCalculatorService();

        $lines = [
            ['amount' => 945258.2700000001, 'business_tax_code' => TaxCalculatorService::BUSINESS_INCOME],
            ['amount' => 11853.67, 'business_tax_code' => TaxCalculatorService::BUSINESS_PROFESSIONAL_SERVICES],
            ['amount' => 150895.55, 'business_tax_code' => TaxCalculatorService::BUSINESS_TOUR_OPERATOR],
            ['amount' => 0, 'business_tax_code' => TaxCalculatorService::BUSINESS_DIVIDEND],
            ['amount' => 735841.17, 'gst_code' => TaxCalculatorService::GST_INCOME],
            ['amount' => 438961.46, 'gst_code' => TaxCalculatorService::GST_EXEMPT_INCOME, 'include_btb' => true],
            ['amount' => 3897.7, 'gst_code' => TaxCalculatorService::GST_ZERO_INCOME],
        ];

        $result = $service->calculate(
            [
                TaxCalculatorService::BUSINESS_INCOME => 0.0175,
                TaxCalculatorService::BUSINESS_PROFESSIONAL_SERVICES => 0.06,
                TaxCalculatorService::BUSINESS_TOUR_OPERATOR => 0.06,
                TaxCalculatorService::BUSINESS_DIVIDEND => 0.15,
                TaxCalculatorService::GST_INCOME => 0.125,
                TaxCalculatorService::BTB_HOTEL_TAX => 0.09,
                TaxCalculatorService::GST_PARTIAL_EXEMPTION => 0.08,
                TaxCalculatorService::GST_INPUT_RECOVERY_MULTIPLIER => 8,
            ],
            $lines,
            37824.98000000001,
            6141.999999999999,
            30322.59,
            64946.229999999996,
        );

        $this->assertEqualsWithDelta(945258.2700000001, $result['business_tax']['income'], 0.01);
        $this->assertEqualsWithDelta(11853.67, $result['business_tax']['professional_services'], 0.01);
        $this->assertEqualsWithDelta(150895.55, $result['business_tax']['tour_operator'], 0.01);
        $this->assertEqualsWithDelta(16542.019725, $result['business_tax']['income_tax'], 0.001);
        $this->assertEqualsWithDelta(711.2202, $result['business_tax']['professional_services_tax'], 0.001);
        $this->assertEqualsWithDelta(9053.733, $result['business_tax']['tour_operator_tax'], 0.001);
        $this->assertEqualsWithDelta(26306.972925, $result['business_tax']['total_tax'], 0.001);

        $this->assertEqualsWithDelta(735841.17, $result['gst']['line_100'], 0.01);
        $this->assertEqualsWithDelta(3897.7, $result['gst']['line_110'], 0.01);
        $this->assertEqualsWithDelta(438961.46, $result['gst']['line_120'], 0.01);
        $this->assertEqualsWithDelta(1178700.33, $result['gst']['line_130'], 0.01);
        $this->assertEqualsWithDelta(91980.14625, $result['gst']['line_140'], 0.001);
        $this->assertEqualsWithDelta(33990.641151, $result['gst']['line_250'], 0.001);
        $this->assertEqualsWithDelta(271925.129211, $result['gst']['line_210'], 0.01);
        $this->assertEqualsWithDelta(47929.235607, $result['gst']['line_230'], 0.01);
        $this->assertEqualsWithDelta(57989.505099, $result['gst']['line_300'], 0.001);
        $this->assertEqualsWithDelta(30322.59, $result['gst']['line_220'], 0.01);

        $this->assertEqualsWithDelta(438961.46, $result['btb']['base'], 0.01);
        $this->assertEqualsWithDelta(39506.5314, $result['btb']['tax'], 0.001);
        $this->assertEqualsWithDelta(123803.009424, $result['combined_total'], 0.01);
        $this->assertEqualsWithDelta(0.6242818054, $result['partial_exemption']['gst_income_ratio'], 0.0000001);
        $this->assertEqualsWithDelta(37824.98, $result['partial_exemption']['total_debits'], 0.01);
        $this->assertEqualsWithDelta(3834.338849, $result['partial_exemption']['ratio_times_partial_exemptions'], 0.001);
        $this->assertEqualsWithDelta(-3834.338849, $result['partial_exemption']['less_partial_exemptions'], 0.001);
        $this->assertEqualsWithDelta(33990.641151, $result['partial_exemption']['line_250'], 0.001);
        $this->assertEqualsWithDelta(0.125, $result['partial_exemption']['gst_rate'], 0.0001);
        $this->assertEqualsWithDelta(-33990.641151, $result['partial_exemption']['invoices_less_exemptions'], 0.001);
        $this->assertEqualsWithDelta(57989.505099, $result['partial_exemption']['net_gst_due'], 0.001);
        $this->assertEqualsWithDelta(64946.23, $result['partial_exemption']['net_of_2251'], 0.01);
        $this->assertEqualsWithDelta(-6956.724901, $result['partial_exemption']['additional_liability'], 0.001);
    }
}
