<?php

namespace Tests\Unit\Payroll;

use App\Models\TaxCalculatorAccount;
use App\Modules\Payroll\Services\TaxCalculatorAccountSyncService;
use App\Modules\Payroll\Services\TaxWorkbookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxCalculatorAccountSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaxWorkbookImportService $importService;

    private TaxCalculatorAccountSyncService $syncService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importService = new TaxWorkbookImportService();
        $this->syncService = new TaxCalculatorAccountSyncService();
    }

    public function test_sync_creates_accounts_and_assigns_tour_income_parents(): void
    {
        $parsed = $this->importService->parse($this->fixturePath());
        $result = $this->syncService->syncFromImportLines($parsed['lines']);

        $this->assertGreaterThan(0, $result['created']);

        $tourHeading = $this->findAccount('4500', '4500 · Tour Income');
        $tikalGuide = $this->findAccount('4501A', 'Tikal Tour Guide');
        $tikalGross = $this->findAccount('4501', 'Tikal Tours Income - Other');
        $tikalNet = $this->findAccount('4501', 'Total 4501');
        $subHeading = $this->findAccount('4511', '4511 · All Other Tour Income');
        $tourTotal = $this->findAccount('4500', 'Total 4500 · Tour Income');

        $this->assertNotNull($tourHeading);
        $this->assertNotNull($tikalGuide);
        $this->assertNotNull($tikalGross);
        $this->assertNotNull($tikalNet);
        $this->assertNotNull($subHeading);
        $this->assertNotNull($tourTotal);

        $this->assertNull($tourHeading->parent_id);
        $this->assertEquals($tourHeading->id, $subHeading->parent_id);
        $this->assertEquals($tikalNet->id, $tikalGuide->parent_id);
        $this->assertEquals($tikalNet->id, $tikalGross->parent_id);
        $this->assertEquals($tourHeading->id, $tikalNet->parent_id);
        $this->assertEquals($tourHeading->id, $tourTotal->parent_id);
        $this->assertSame(TaxCalculatorAccount::TAX_BASIS_NONE, $tikalGuide->resolvesTaxBasis());
        $this->assertSame(TaxCalculatorAccount::TAX_BASIS_NET, $tourTotal->resolvesTaxBasis());
    }

    public function test_sync_does_not_overwrite_existing_tax_mappings(): void
    {
        TaxCalculatorAccount::create([
            'qb_code' => '4500',
            'qb_name' => 'Total 4500 · Tour Income',
            'is_rollup' => true,
            'tax_basis' => TaxCalculatorAccount::TAX_BASIS_NET,
            'include_btb' => false,
            'sort_order' => 0,
            'is_active' => true,
        ])->syncRateCodes(['BUSINESS_TOUR_OPERATOR', 'GST_INCOME']);

        $parsed = $this->importService->parse($this->fixturePath());
        $this->syncService->syncFromImportLines($parsed['lines']);

        $tourTotal = $this->findAccount('4500', 'Total 4500 · Tour Income');
        $this->assertNotNull($tourTotal);
        $this->assertContains('BUSINESS_TOUR_OPERATOR', $tourTotal->businessTaxCodes());
        $this->assertContains('GST_INCOME', $tourTotal->gstTaxCodes());
    }

    public function test_sync_promotes_spa_services_gst_other_from_section_to_qb_account(): void
    {
        $section = TaxCalculatorAccount::create([
            'qb_code' => '4827A',
            'qb_name' => '4827A · Spa Services GST-Other',
            'is_rollup' => false,
            'line_kind' => 'section',
            'tax_basis' => TaxCalculatorAccount::TAX_BASIS_NONE,
            'include_btb' => false,
            'sort_order' => 44,
            'is_active' => true,
        ]);

        $this->syncService->syncFromImportLines([
            [
                'account_code' => '4827A',
                'account_name' => '4827A · Spa Services GST-Other',
                'amount' => 20548.70,
                'is_rollup' => false,
                'row_type' => 'account',
                'include_in_tax' => true,
                'label_column' => 'B',
                'group_index' => 1,
                'sort_order' => 44,
            ],
        ]);

        $section->refresh();
        $this->assertSame('qb_account', $section->line_kind);
        $this->assertSame(1, TaxCalculatorAccount::query()->where('qb_code', '4827A')->count());
    }

    private function findAccount(string $code, string $nameNeedle): ?TaxCalculatorAccount
    {
        return TaxCalculatorAccount::query()
            ->where('qb_code', strtoupper($code))
            ->get()
            ->first(fn (TaxCalculatorAccount $account) => str_contains((string) $account->qb_name, $nameNeedle));
    }

    private function fixturePath(): string
    {
        $path = dirname(__DIR__, 2).'/Fixtures/gst-business-tax-upload.xlsx';
        $this->assertFileExists($path);

        return $path;
    }
}
