<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\TaxWorkbookImportService;
use PHPUnit\Framework\TestCase;

class TaxWorkbookImportServiceTest extends TestCase
{
    private TaxWorkbookImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TaxWorkbookImportService();
    }

    public function test_parses_july_upload_workbook(): void
    {
        $parsed = $this->service->parse($this->fixturePath());

        $this->assertEqualsWithDelta(37824.98, $parsed['total_debits'], 0.01);
        $this->assertEqualsWithDelta(6142.0, $parsed['partial_exemptions_total'], 0.01);
        $this->assertEqualsWithDelta(64946.23, $parsed['net_of_2251'], 0.01);
        $this->assertNotEmpty($parsed['accounts_sheet']);
        $this->assertNotEmpty($parsed['gst_sheet']);

        $tikalNet = $this->firstByCode($parsed['lines'], '4501');
        $this->assertNotNull($tikalNet);
        $this->assertTrue($tikalNet['is_rollup']);
        $this->assertStringContainsString('Tikal Tours Income', $tikalNet['account_name']);
        $this->assertEqualsWithDelta(9565.0, $tikalNet['amount'], 0.01);
        $this->assertFalse($tikalNet['include_in_tax']);
        $this->assertTrue($this->hasName($parsed['lines'], 'Tikal Tour Guide'));
        $this->assertTrue($this->hasName($parsed['lines'], 'Tikal Tours Income - Other'));

        $tourHeading = null;
        $tourTotal = null;
        foreach ($parsed['lines'] as $line) {
            if (($line['row_type'] ?? null) === 'heading' && str_contains((string) $line['account_name'], '4500 · Tour Income')) {
                $tourHeading = $line;
            }
            if (($line['row_type'] ?? null) === 'total' && str_contains((string) $line['account_name'], 'Total 4500 · Tour Income')) {
                $tourTotal = $line;
            }
        }
        $this->assertNotNull($tourHeading);
        $this->assertNotNull($tourTotal);
        $this->assertEquals($tourHeading['group_index'], $tourTotal['group_index']);
        $this->assertTrue($tourTotal['include_in_tax']);
        $this->assertEqualsWithDelta(150895.55, $tourTotal['amount'], 0.01);

        $mayaFarm = $this->firstByCode($parsed['lines'], '4815');
        $this->assertNotNull($mayaFarm);
        $this->assertEqualsWithDelta(3897.7, $mayaFarm['amount'], 0.01);

        $this->assertNotNull($this->firstByCode($parsed['lines'], '4701'));

        $headings = array_values(array_filter(
            $parsed['lines'],
            fn (array $line) => ($line['row_type'] ?? null) === 'heading'
        ));
        $headingNames = array_map(fn (array $line) => $line['account_name'], $headings);
        $this->assertContains('Income:', $headingNames);
        $this->assertTrue($this->hasName($parsed['lines'], '4500 · Tour Income'));
        $this->assertTrue($this->hasName($parsed['lines'], '4800 · Other Income'));
        $this->assertTrue($this->hasName($parsed['lines'], 'Guava Limb Income'));

        $onSiteTotal = null;
        foreach ($parsed['lines'] as $line) {
            if (($line['row_type'] ?? null) === 'total' && str_contains((string) $line['account_name'], 'On-Site Activities')) {
                $onSiteTotal = $line;
                break;
            }
        }
        $this->assertNotNull($onSiteTotal);
        $this->assertFalse($onSiteTotal['include_in_tax']);
        $this->assertEqualsWithDelta(45684.5, $onSiteTotal['amount'], 0.01);

        $groupIndexes = array_unique(array_column($parsed['lines'], 'group_index'));
        $this->assertGreaterThan(3, count($groupIndexes));
        $this->assertGreaterThan(10, count($parsed['lines']));
    }

    public function test_parse_account_labels(): void
    {
        $this->assertSame('4501A', $this->service->parseAccountLabel('4501a · Tikal Tour Guide Prof Services')['code']);
        $this->assertSame('4001A', $this->service->parseAccountLabel('4001 A· Spa Villa Income')['code']);
        $this->assertTrue($this->service->parseAccountLabel('Total 4501 · Tikal Tours Income (NET)')['is_total']);
        $this->assertSame('4715', $this->service->parseAccountLabel('4715 . Tubing')['code']);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>|null
     */
    private function firstByCode(array $lines, string $code): ?array
    {
        foreach ($lines as $line) {
            if ($line['account_code'] === $code) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function hasName(array $lines, string $needle): bool
    {
        foreach ($lines as $line) {
            if (str_contains((string) $line['account_name'], $needle)) {
                return true;
            }
        }

        return false;
    }

    private function fixturePath(): string
    {
        $path = dirname(__DIR__, 2).'/Fixtures/gst-business-tax-upload.xlsx';
        $this->assertFileExists($path);

        return $path;
    }
}
