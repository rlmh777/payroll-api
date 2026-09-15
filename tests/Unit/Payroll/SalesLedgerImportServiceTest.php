<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\SalesLedgerImportService;
use PHPUnit\Framework\TestCase;

class SalesLedgerImportServiceTest extends TestCase
{
    private SalesLedgerImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SalesLedgerImportService();
    }

    public function test_parses_raw_sales_ledger_workbook(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/raw-sales-ledger.xlsx';
        $this->assertFileExists($path);

        $parsed = $this->service->parse($path, 2026, 8);

        $this->assertSame('sales_transaction', $parsed['format']);
        $this->assertSame('B', $parsed['class_column']);
        $this->assertSame('C', $parsed['date_column']);
        $this->assertSame('E', $parsed['invoice_column']);
        $this->assertSame('G', $parsed['name_column']);
        $this->assertSame('I', $parsed['debit_column']);
        $this->assertSame('K', $parsed['credit_column']);
        $this->assertNotEmpty($parsed['rows']);

        $dataRows = array_values(array_filter(
            $parsed['rows'],
            fn (array $row) => (int) ($row['row'] ?? 0) > 1
                && empty($row['is_section'])
                && empty($row['is_total'])
                && ($row['C'] ?? null) !== null,
        ));
        $this->assertNotEmpty($dataRows);

        foreach ($parsed['rows'] as $row) {
            $this->assertNotSame(1, (int) ($row['row'] ?? 0), 'Header row must not be imported');
        }

        $first = $dataRows[0];
        $this->assertSame('Taxable Sales', $first['B']);
        $this->assertSame('0775', (string) $first['E']);
        $this->assertSame('GLC Daily Sales', $first['G']);
    }

    public function test_taxable_sales_net_is_credit_minus_debit(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/raw-sales-ledger.xlsx';
        $parsed = $this->service->parse($path, 2026, 8);

        $debit = 0.0;
        $credit = 0.0;
        foreach ($parsed['rows'] as $row) {
            if (! empty($row['is_section']) || ! empty($row['is_total'])) {
                continue;
            }
            if (($row['B'] ?? null) !== 'Taxable Sales') {
                continue;
            }
            $debit += (float) ($row['I'] ?? 0);
            $credit += (float) ($row['K'] ?? 0);
        }

        $this->assertEqualsWithDelta(5178.82, $debit, 0.01);
        $this->assertEqualsWithDelta(187893.60, $credit, 0.01);
        $this->assertEqualsWithDelta(182714.78, $credit - $debit, 0.01);
        $this->assertNotEqualsWithDelta($credit, $credit - $debit, 0.01);
        $this->assertNotEqualsWithDelta($debit, $credit - $debit, 0.01);
    }

    public function test_rejects_wrong_period(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/raw-sales-ledger.xlsx';
        $this->expectException(\InvalidArgumentException::class);
        $this->service->parse($path, 2026, 7);
    }
}
