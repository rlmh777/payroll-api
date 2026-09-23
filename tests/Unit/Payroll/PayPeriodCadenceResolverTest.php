<?php

namespace Tests\Unit\Payroll;

use App\Enums\PayPeriodCadence;
use App\Modules\Payroll\Services\PayPeriodCadenceResolver;
use Tests\TestCase;

class PayPeriodCadenceResolverTest extends TestCase
{
    private PayPeriodCadenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PayPeriodCadenceResolver();
    }

    public function test_detects_semi_monthly_from_rules(): void
    {
        $this->assertSame(
            PayPeriodCadence::SemiMonthly,
            $this->resolver->fromText('Staff payroll', 'Pay on the 15th and 30th of each month.'),
        );
        $this->assertSame(
            PayPeriodCadence::SemiMonthly,
            $this->resolver->fromText('Semi-monthly', null),
        );
    }

    public function test_detects_biweekly_and_monthly_from_name_or_frequency_rule(): void
    {
        $this->assertSame(PayPeriodCadence::Biweekly, $this->resolver->fromText('Biweekly Payroll', null));
        $this->assertSame(PayPeriodCadence::Biweekly, $this->resolver->fromText('Hourly', 'frequency = BIWEEKLY'));
        $this->assertSame(PayPeriodCadence::Monthly, $this->resolver->fromText('Monthly Payroll', null));
        $this->assertSame(PayPeriodCadence::Monthly, $this->resolver->fromText('Salaried', 'frequency = MONTHLY'));
        $this->assertSame(
            PayPeriodCadence::Monthly,
            $this->resolver->fromText('Staff payroll', 'Pay on the 25th of each month for the full calendar month.'),
        );
    }

    public function test_epoch_uses_anchor_date_from_rules_when_present(): void
    {
        $this->assertSame(
            '2026-03-02',
            $this->resolver->epochDate(
                PayPeriodCadence::Biweekly,
                '2026-06-15',
                'frequency = BIWEEKLY; anchor_date = 2026-03-02',
            ),
        );
        $this->assertSame(
            '2026-01-01',
            $this->resolver->epochDate(PayPeriodCadence::SemiMonthly, '2026-06-15', 'Pay on the 15th and 30th.'),
        );
    }
}
