<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\PurchaseLedgerImportService;
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
        $this->assertContains('Class', array_map(
            fn (string $column) => (string) ($parsed['rows'][0][$column] ?? $column),
            $parsed['columns']
        ));
        $this->assertGreaterThan(100, count($parsed['rows']));
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
