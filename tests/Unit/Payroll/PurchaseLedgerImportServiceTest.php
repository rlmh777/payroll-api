<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\PurchaseLedgerImportService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PurchaseLedgerImportServiceTest extends TestCase
{
    private PurchaseLedgerImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurchaseLedgerImportService();
    }

    public function test_parses_july_purchase_ledger_workbook(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/july-gst-purchase-ledger.xlsx';
        $this->assertFileExists($path);

        $parsed = $this->service->parse($path);

        $this->assertSame('transaction', $parsed['format']);
        $this->assertSame('F', $parsed['name_column']);
        $this->assertSame('L', $parsed['class_column']);
        $this->assertSame('N', $parsed['debit_column']);
        $this->assertSame('J', $parsed['tin_column']);
        $this->assertSame('D', $parsed['invoice_column']);
        $this->assertContains('Class', array_map(
            fn (string $column) => (string) ($parsed['rows'][0][$column] ?? $column),
            $parsed['columns']
        ));
        $this->assertGreaterThan(100, count($parsed['rows']));
    }

    public function test_rejects_purchase_ledger_records_without_a_class(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/july-gst-purchase-ledger.xlsx';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('without a class assigned');
        $this->expectExceptionMessage('was not imported');

        $this->service->parse($path, 2026, 7);
    }

    public function test_rejects_purchase_ledger_dates_outside_the_selected_period(): void
    {
        $path = dirname(__DIR__, 2).'/Fixtures/july-gst-purchase-ledger.xlsx';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not in June 2026');
        $this->expectExceptionMessage('was not imported');

        $this->service->parse($path, 2026, 6);
    }

    public function test_normalizes_legacy_purchase_ledger_payload(): void
    {
        $legacyRows = [
            ['row' => 1, 'F' => 'Name', 'L' => 'Class', 'N' => 'Debit'],
            ['row' => 2, 'F' => 'Acme Corp', 'L' => 'Taxes:Taxable', 'N' => '10'],
        ];

        $normalized = $this->service->normalizeSheet($legacyRows);

        $this->assertSame('transaction', $normalized['format']);
        $this->assertSame($legacyRows, $normalized['rows']);
        $this->assertContains('F', $normalized['columns']);
    }
}
