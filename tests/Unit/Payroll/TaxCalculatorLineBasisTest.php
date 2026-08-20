<?php

namespace Tests\Unit\Payroll;

use App\Models\TaxCalculatorAccount;
use App\Modules\Payroll\Support\TaxCalculatorLineBasis;
use Tests\TestCase;

class TaxCalculatorLineBasisTest extends TestCase
{
    public function test_btb_applies_to_gross_account_lines_when_mapping_is_qb_account(): void
    {
        $mapping = new TaxCalculatorAccount([
            'qb_code' => '4100',
            'qb_name' => 'Hotel Room Revenue',
            'line_kind' => 'qb_account',
            'include_btb' => true,
            'is_rollup' => false,
        ]);

        $line = [
            'account_code' => '4100',
            'account_name' => 'Hotel Room Revenue',
            'row_type' => 'account',
            'amount' => 1000,
        ];

        $this->assertTrue(TaxCalculatorLineBasis::qualifiesForBtb($line, $mapping));
    }

    public function test_btb_applies_to_net_total_lines_when_mapping_is_net_total(): void
    {
        $mapping = new TaxCalculatorAccount([
            'qb_code' => '4100',
            'qb_name' => 'Total Exempt Income',
            'line_kind' => 'net_total',
            'include_btb' => true,
            'is_rollup' => true,
        ]);

        $line = [
            'account_code' => '4100',
            'account_name' => 'Total Exempt Income',
            'row_type' => 'total',
            'is_rollup' => true,
            'amount' => 438961.46,
        ];

        $this->assertTrue(TaxCalculatorLineBasis::qualifiesForBtb($line, $mapping));
    }

    public function test_btb_does_not_apply_to_gross_line_for_net_total_mapping(): void
    {
        $mapping = new TaxCalculatorAccount([
            'qb_code' => '4100',
            'qb_name' => 'Total Exempt Income',
            'line_kind' => 'net_total',
            'include_btb' => true,
            'is_rollup' => true,
        ]);

        $line = [
            'account_code' => '4100',
            'account_name' => 'Hotel Room Revenue',
            'row_type' => 'account',
            'amount' => 1000,
        ];

        $this->assertFalse(TaxCalculatorLineBasis::qualifiesForBtb($line, $mapping));
    }
}
