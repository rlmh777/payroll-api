<?php

namespace Tests\Unit\Payroll;

use Tests\TestCase;

class IncomeTaxFormulaTest extends TestCase
{
    public function test_biweekly_example_from_payroll_spec(): void
    {
        $periodTaxableGross = 2000.00;
        $periodsPerYear = 26;
        $personalRelief = 19600.00;
        $taxRate = 0.25;
        $secondRelief = 100.00;

        $annualGross = round($periodTaxableGross * $periodsPerYear, 2);
        $annualTaxableAfterRelief = max(0, round($annualGross - $personalRelief, 2));
        $annualTaxBeforeSecondRelief = round($annualTaxableAfterRelief * $taxRate, 2);
        $annualTaxDue = max(0, round($annualTaxBeforeSecondRelief - $secondRelief, 2));
        $periodTax = max(0, round($annualTaxDue / $periodsPerYear, 2));

        $this->assertSame(52000.0, $annualGross);
        $this->assertSame(32400.0, $annualTaxableAfterRelief);
        $this->assertSame(8100.0, $annualTaxBeforeSecondRelief);
        $this->assertSame(8000.0, $annualTaxDue);
        $this->assertSame(307.69, $periodTax);
    }
}
