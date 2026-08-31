<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\TaxCalculatorAccount;
use App\Models\TaxCalculatorRate;
use App\Models\TaxCalculatorRun;
use App\Models\TaxCalculatorRunLine;
use App\Modules\Payroll\Support\TaxCalculatorLineBasis;
use App\Modules\Payroll\Services\TaxCalculatorService;
use App\Modules\Payroll\Services\TaxCalculatorAccountSyncService;
use App\Modules\Payroll\Services\TaxWorkbookImportService;
use App\Modules\Payroll\Services\PurchaseLedgerImportService;
use App\Models\TaxCalculatorPurchaseLedgerExcludedName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class TaxCalculatorRunController extends Controller
{
    public function __construct(
        private readonly TaxCalculatorService $taxCalculatorService,
        private readonly TaxWorkbookImportService $workbookImportService,
        private readonly PurchaseLedgerImportService $purchaseLedgerImportService,
        private readonly TaxCalculatorAccountSyncService $accountSyncService,
    ) {
    }

    public function index(): JsonResponse
    {
        $runs = TaxCalculatorRun::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(fn (TaxCalculatorRun $run) => $this->listItem($run));

        return response()->json($runs);
    }

    public function workspace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $run = TaxCalculatorRun::query()
            ->with('lines')
            ->where('year', $validated['year'])
            ->where('month', $validated['month'])
            ->first();

        return response()->json($this->workspacePayload(
            (int) $validated['year'],
            (int) $validated['month'],
            $run,
        ));
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $this->validatedRun($request, requirePeriod: false);
        $rates = $this->ratesByCode();
        $results = $this->taxCalculatorService->calculate(
            $rates,
            $validated['lines'],
            $this->totalDebits($validated),
            (float) ($validated['partial_exemptions_total'] ?? 0),
            (float) $validated['line_220'],
            (float) ($validated['net_of_2251'] ?? 0),
        );

        return response()->json([
            'results' => $results,
            'rates' => TaxCalculatorRate::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedRun($request, requirePeriod: true);
        $rates = $this->ratesByCode();
        $results = $this->taxCalculatorService->calculate(
            $rates,
            $validated['lines'],
            $this->totalDebits($validated),
            (float) ($validated['partial_exemptions_total'] ?? 0),
            (float) $validated['line_220'],
            (float) ($validated['net_of_2251'] ?? 0),
        );

        $run = DB::transaction(function () use ($validated, $rates, $results) {
            $run = TaxCalculatorRun::query()->updateOrCreate(
                [
                    'year' => $validated['year'],
                    'month' => $validated['month'],
                ],
                [
                    'gst_value_entered' => $this->totalDebits($validated),
                    'partial_exemptions_total' => $validated['partial_exemptions_total'] ?? 0,
                    'line_220' => $validated['line_220'],
                    'net_of_2251' => $validated['net_of_2251'] ?? 0,
                    'rates_snapshot' => $this->rateSnapshot($rates),
                    'results' => $results,
                    'created_by' => Auth::id(),
                ],
            );

            $run->lines()->delete();

            foreach (array_values($validated['lines']) as $index => $line) {
                TaxCalculatorRunLine::create([
                    'tax_calculator_run_id' => $run->id,
                    'account_id' => $line['account_id'] ?? null,
                    'account_code' => $line['account_code'] ?? null,
                    'account_name' => $line['account_name'],
                    'amount' => $line['amount'] ?? 0,
                    'business_tax_code' => $line['business_tax_code'] ?? null,
                    'gst_code' => $line['gst_code'] ?? null,
                    'include_btb' => $line['include_btb'] ?? false,
                    'is_rollup' => $line['is_rollup'] ?? false,
                    'row_type' => $line['row_type'] ?? 'account',
                    'group_index' => $line['group_index'] ?? 0,
                    'include_in_tax' => $line['include_in_tax'] ?? true,
                    'sort_order' => $line['sort_order'] ?? $index,
                ]);
            }

            return $run->fresh('lines');
        });

        return response()->json([
            'message' => 'GST calculator filing saved',
            'data' => $this->workspacePayload($run->year, $run->month, $run),
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $this->assertNotFuturePeriod((int) $validated['year'], (int) $validated['month']);

        $file = $request->file('file');
        if ($file === null) {
            throw ValidationException::withMessages([
                'file' => 'Upload the monthly QuickBooks workbook as an .xlsx file.',
            ]);
        }
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = $file->getRealPath();
        if ($extension !== 'xlsx' || $path === false) {
            throw ValidationException::withMessages([
                'file' => 'Upload the monthly QuickBooks workbook as an .xlsx file.',
            ]);
        }

        try {
            $parsed = $this->workbookImportService->parse(
                $path,
                (int) $validated['year'],
                (int) $validated['month'],
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        $existing = TaxCalculatorRun::query()
            ->where('year', $validated['year'])
            ->where('month', $validated['month'])
            ->first();

        $totalDebits = $parsed['total_debits'];
        $partialExemptions = $parsed['partial_exemptions_total'];
        $netOf2251 = $parsed['net_of_2251'];
        $line220 = (float) ($existing?->line_220 ?? 0);
        $rates = $this->ratesByCode();
        $accountSync = ['created' => 0, 'updated' => 0];

        $run = DB::transaction(function () use ($validated, $file, $parsed, $totalDebits, $partialExemptions, $netOf2251, $line220, $rates, &$accountSync) {
            $accountSync = $this->accountSyncService->syncFromImportLines($parsed['lines']);
            $mappedLines = $this->applyMappings($parsed['lines']);
            $results = $this->taxCalculatorService->calculate(
                $rates,
                $mappedLines,
                $totalDebits,
                $partialExemptions,
                $line220,
                $netOf2251,
            );

            $run = TaxCalculatorRun::query()->updateOrCreate(
                [
                    'year' => $validated['year'],
                    'month' => $validated['month'],
                ],
                [
                    'gst_value_entered' => $totalDebits,
                    'partial_exemptions_total' => $partialExemptions,
                    'line_220' => $line220,
                    'net_of_2251' => $netOf2251,
                    'import_filename' => $file->getClientOriginalName(),
                    'imported_at' => now(),
                    'import_accounts_sheet' => $parsed['accounts_sheet'],
                    'import_gst_sheet' => $parsed['gst_sheet'],
                    'rates_snapshot' => $this->rateSnapshot($rates),
                    'results' => $results,
                    'created_by' => Auth::id(),
                ],
            );

            $run->lines()->delete();

            foreach (array_values($mappedLines) as $index => $line) {
                TaxCalculatorRunLine::create([
                    'tax_calculator_run_id' => $run->id,
                    'account_id' => $line['account_id'] ?? null,
                    'account_code' => $line['account_code'] ?? null,
                    'account_name' => $line['account_name'],
                    'amount' => $line['amount'] ?? 0,
                    'business_tax_code' => $line['business_tax_code'] ?? null,
                    'gst_code' => $line['gst_code'] ?? null,
                    'include_btb' => $line['include_btb'] ?? false,
                    'is_rollup' => $line['is_rollup'] ?? false,
                    'row_type' => $line['row_type'] ?? 'account',
                    'group_index' => $line['group_index'] ?? 0,
                    'include_in_tax' => $line['include_in_tax'] ?? true,
                    'sort_order' => $line['sort_order'] ?? $index,
                ]);
            }

            return $run->fresh('lines');
        });

        return response()->json([
            'message' => 'Workbook imported',
            'accounts_sync' => $accountSync,
            'data' => $this->workspacePayload($run->year, $run->month, $run),
        ]);
    }

    public function importPurchaseLedger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $this->assertNotFuturePeriod((int) $validated['year'], (int) $validated['month']);

        $file = $request->file('file');
        if ($file === null) {
            throw ValidationException::withMessages([
                'file' => 'Upload the purchase ledger as an .xlsx file.',
            ]);
        }
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = $file->getRealPath();
        if ($extension !== 'xlsx' || $path === false) {
            throw ValidationException::withMessages([
                'file' => 'Upload the purchase ledger as an .xlsx file.',
            ]);
        }

        try {
            $parsed = $this->purchaseLedgerImportService->parse(
                $path,
                (int) $validated['year'],
                (int) $validated['month'],
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        $run = TaxCalculatorRun::query()->updateOrCreate(
            [
                'year' => $validated['year'],
                'month' => $validated['month'],
            ],
            [
                'import_purchase_ledger_sheet' => $parsed,
                'import_purchase_ledger_filename' => $file->getClientOriginalName(),
                'import_purchase_ledger_at' => now(),
                'created_by' => Auth::id(),
            ],
        );

        return response()->json([
            'message' => 'Purchase ledger imported',
            'data' => $this->workspacePayload($run->year, $run->month, $run->fresh('lines')),
        ]);
    }

    public function destroy(TaxCalculatorRun $taxCalculatorRun): JsonResponse
    {
        $taxCalculatorRun->delete();

        return response()->json(['message' => 'GST calculator filing deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function workspacePayload(int $year, int $month, ?TaxCalculatorRun $run): array
    {
        $rates = TaxCalculatorRate::query()->orderBy('sort_order')->get();

        $lines = [];
        if ($run && $run->lines->isNotEmpty()) {
            foreach ($run->lines as $line) {
                $lines[] = [
                    'account_id' => $line->account_id,
                    'account_code' => $line->account_code,
                    'account_name' => $line->account_name,
                    'amount' => (float) $line->amount,
                    'business_tax_code' => $line->business_tax_code,
                    'gst_code' => $line->gst_code,
                    'include_btb' => (bool) $line->include_btb,
                    'is_rollup' => (bool) $line->is_rollup,
                    'row_type' => $line->row_type ?? 'account',
                    'group_index' => (int) ($line->group_index ?? 0),
                    'include_in_tax' => $line->include_in_tax ?? true,
                    'sort_order' => $line->sort_order,
                ];
            }
            $lines = $this->applyMappings($lines);
        }

        $previous = TaxCalculatorRun::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(fn (TaxCalculatorRun $item) => $this->listItem($item))
            ->values();

        return [
            'year' => $year,
            'month' => $month,
            'run' => $run,
            'lines' => $lines,
            'total_debits' => $run?->gst_value_entered ?? 0,
            'gst_value_entered' => $run?->gst_value_entered ?? 0,
            'partial_exemptions_total' => $run?->partial_exemptions_total ?? 0,
            'line_220' => $run?->line_220 ?? 0,
            'net_of_2251' => $run?->net_of_2251 ?? 0,
            'import_filename' => $run?->import_filename,
            'imported_at' => $run?->imported_at?->toIso8601String(),
            'import_gst_sheet' => $run?->import_gst_sheet ?? [],
            'import_purchase_ledger' => $this->purchaseLedgerImportService->normalizeSheet($run?->import_purchase_ledger_sheet),
            'import_purchase_ledger_filename' => $run?->import_purchase_ledger_filename,
            'import_purchase_ledger_at' => $run?->import_purchase_ledger_at?->toIso8601String(),
            'purchase_ledger_excluded_names' => TaxCalculatorPurchaseLedgerExcludedName::query()
                ->orderBy('name')
                ->get()
                ->map(fn (TaxCalculatorPurchaseLedgerExcludedName $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                ])
                ->values()
                ->all(),
            'results' => $run?->results,
            'rates' => $rates,
            'previous_runs' => $previous,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRun(Request $request, bool $requirePeriod): array
    {
        $periodRules = $requirePeriod
            ? [
                'year' => ['required', 'integer', 'min:2000', 'max:2100'],
                'month' => ['required', 'integer', 'min:1', 'max:12'],
            ]
            : [
                'year' => ['nullable', 'integer'],
                'month' => ['nullable', 'integer'],
            ];

        return $request->validate([
            ...$periodRules,
            'total_debits' => ['nullable', 'numeric'],
            'gst_value_entered' => ['nullable', 'numeric'],
            'partial_exemptions_total' => ['nullable', 'numeric'],
            'line_220' => ['required', 'numeric'],
            'net_of_2251' => ['nullable', 'numeric'],
            'lines' => ['required', 'array'],
            'lines.*.account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'lines.*.account_code' => ['nullable', 'string', 'max:64'],
            'lines.*.account_name' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['nullable', 'numeric'],
            'lines.*.business_tax_code' => ['nullable', 'string', 'max:64'],
            'lines.*.gst_code' => ['nullable', 'string', 'max:64'],
            'lines.*.include_btb' => ['boolean'],
            'lines.*.is_rollup' => ['boolean'],
            'lines.*.row_type' => ['nullable', 'string', 'max:32'],
            'lines.*.group_index' => ['integer', 'min:0'],
            'lines.*.include_in_tax' => ['boolean'],
            'lines.*.sort_order' => ['integer', 'min:0'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function applyMappings(array $lines): array
    {
        $grouped = TaxCalculatorAccount::query()
            ->where('is_active', true)
            ->whereNotNull('qb_code')
            ->with('rates.rate')
            ->get()
            ->groupBy(fn (TaxCalculatorAccount $mapping) => strtoupper((string) $mapping->qb_code));

        foreach ($lines as &$line) {
            $rowType = $line['row_type'] ?? 'account';
            if (TaxCalculatorLineBasis::isSpreadsheetTotalLine($line) && $rowType !== 'grand_total') {
                $line['row_type'] = 'total';
                $line['is_rollup'] = true;
                $rowType = 'total';
            }
            if (in_array($rowType, ['heading', 'grand_total'], true)) {
                continue;
            }

            $code = strtoupper((string) ($line['account_code'] ?? ''));
            $candidates = $grouped->get($code, collect());
            $mapping = $this->matchMapping($candidates, (string) ($line['account_name'] ?? ''), $rowType);
            if (! $mapping) {
                continue;
            }

            $applied = [];
            foreach ($mapping->rateAssignments() as $assignment) {
                $basis = (string) ($assignment['tax_basis'] ?? TaxCalculatorAccount::TAX_BASIS_GROSS);
                if (! TaxCalculatorLineBasis::qualifies($line, $basis)) {
                    continue;
                }
                $applied[] = $assignment;
            }

            if ($applied === [] && ! $mapping->include_btb) {
                continue;
            }

            if ($applied !== []) {
                $line['include_in_tax'] = true;
                $this->mergeTaxAssignments($line, $applied);
            }

            if ($mapping->include_btb && TaxCalculatorLineBasis::qualifiesForBtb($line, $mapping)) {
                $line['include_btb'] = true;
                $line['include_in_tax'] = true;
            }
        }
        unset($line);

        return $this->resolveTaxableTotals($lines);
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  list<array{code: string, tax_basis: string, category: string}>  $assignments
     */
    private function mergeTaxAssignments(array &$line, array $assignments): void
    {
        $existing = $line['tax_rate_assignments'] ?? [];
        if ($assignments !== []) {
            $line['tax_rate_assignments'] = array_values(array_merge($existing, $assignments));
        }

        $this->rebuildLegacyTaxCodes($line);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function rebuildLegacyTaxCodes(array &$line): void
    {
        $businessCodes = [];
        $gstCodes = [];
        foreach ($line['tax_rate_assignments'] ?? [] as $assignment) {
            if (($assignment['category'] ?? '') === 'business_tax') {
                $businessCodes[] = $assignment['code'];
            }
            if (($assignment['category'] ?? '') === 'gst') {
                $gstCodes[] = $assignment['code'];
            }
        }

        $line['business_tax_codes'] = array_values(array_unique($businessCodes));
        $line['gst_codes'] = array_values(array_unique($gstCodes));
        $line['business_tax_code'] = $line['business_tax_codes'][0] ?? null;
        $line['gst_code'] = $line['gst_codes'][0] ?? null;
    }

    /**
     * Avoid counting both a NET subtotal and its section total toward tax/footer.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function resolveTaxableTotals(array $lines): array
    {
        $byGroup = [];
        foreach ($lines as $index => $line) {
            $group = (int) ($line['group_index'] ?? 0);
            $byGroup[$group][] = $index;
        }

        foreach ($byGroup as $indexes) {
            $netBusinessIndexes = array_values(array_filter(
                $indexes,
                fn (int $index) => TaxCalculatorLineBasis::hasNetBusinessAssignment($lines[$index])
            ));

            if ($netBusinessIndexes === []) {
                continue;
            }

            $taxedLookup = array_fill_keys($netBusinessIndexes, true);
            foreach ($indexes as $index) {
                if (($lines[$index]['row_type'] ?? '') !== 'total') {
                    continue;
                }
                if (isset($taxedLookup[$index])) {
                    continue;
                }

                $assignments = TaxCalculatorLineBasis::assignments($lines[$index]);
                $remaining = array_values(array_filter(
                    $assignments,
                    fn (array $assignment) => ! (
                        ($assignment['category'] ?? '') === 'business_tax'
                        && ($assignment['tax_basis'] ?? '') === TaxCalculatorAccount::TAX_BASIS_NET
                    )
                ));

                if (count($remaining) === count($assignments)) {
                    continue;
                }

                if ($remaining === []) {
                    $lines[$index]['include_in_tax'] = false;
                    unset(
                        $lines[$index]['tax_rate_assignments'],
                        $lines[$index]['business_tax_codes'],
                        $lines[$index]['gst_codes'],
                        $lines[$index]['business_tax_code'],
                        $lines[$index]['gst_code'],
                    );
                    $lines[$index]['include_btb'] = false;
                } else {
                    $lines[$index]['tax_rate_assignments'] = $remaining;
                    $this->mergeTaxAssignments($lines[$index], []);
                }
            }
        }

        return $lines;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TaxCalculatorAccount>  $candidates
     */
    private function matchMapping($candidates, string $accountName, string $rowType = 'account'): ?TaxCalculatorAccount
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $name = strtolower(trim($accountName));
        $isTotalRow = in_array($rowType, ['total', 'grand_total'], true);

        $filtered = $candidates->filter(function (TaxCalculatorAccount $mapping) use ($name, $rowType) {
            $mappedName = strtolower(trim((string) $mapping->qb_name));
            $display = strtolower(trim($mapping->displayName()));

            if ($mappedName === '' && $display === '') {
                return false;
            }

            $nameMatches = $mappedName !== '' && (
                $name === $mappedName
                || str_contains($name, $mappedName)
                || str_contains($mappedName, $name)
                || $name === $display
            );

            if (! $nameMatches) {
                return false;
            }

            return $this->mappingMatchesLineKind($mapping, $rowType);
        });

        if ($filtered->isEmpty()) {
            $filtered = $candidates->filter(function (TaxCalculatorAccount $mapping) use ($name) {
                $mappedName = strtolower(trim((string) $mapping->qb_name));
                $display = strtolower(trim($mapping->displayName()));

                if ($mappedName === '' && $display === '') {
                    return false;
                }

                return $mappedName !== '' && (
                    $name === $mappedName
                    || str_contains($name, $mappedName)
                    || str_contains($mappedName, $name)
                    || $name === $display
                );
            });
        }

        if ($filtered->count() === 1) {
            return $filtered->first();
        }
        if ($filtered->count() > 1) {
            return $filtered->sortByDesc(fn (TaxCalculatorAccount $mapping) => count($mapping->businessTaxCodes()) + count($mapping->gstTaxCodes()))->first();
        }
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $matched = $candidates->first(function (TaxCalculatorAccount $mapping) use ($name) {
            $mappedName = strtolower(trim((string) $mapping->qb_name));
            $display = strtolower(trim($mapping->displayName()));

            return $mappedName !== '' && ($name === $mappedName || str_contains($name, $mappedName) || str_contains($mappedName, $name) || $name === $display);
        });

        return $matched ?? $candidates->first();
    }

    private function mappingMatchesLineKind(TaxCalculatorAccount $mapping, string $rowType): bool
    {
        $kind = $mapping->lineKind();
        if ($kind === 'section') {
            return false;
        }
        if ($kind === 'net_total') {
            return in_array($rowType, ['total', 'grand_total'], true);
        }

        return $rowType === 'account';
    }

    private function assertNotFuturePeriod(int $year, int $month): void
    {
        $nowYear = (int) now()->format('Y');
        $nowMonth = (int) now()->format('n');
        if ($year > $nowYear || ($year === $nowYear && $month > $nowMonth)) {
            throw ValidationException::withMessages([
                'month' => 'Future periods cannot be imported.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function totalDebits(array $validated): float
    {
        return (float) ($validated['total_debits'] ?? $validated['gst_value_entered'] ?? 0);
    }

    /**
     * @return array<string, float>
     */
    private function ratesByCode(): array
    {
        return TaxCalculatorRate::query()
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (TaxCalculatorRate $rate) => [$rate->code => (float) $rate->rate])
            ->all();
    }

    /**
     * @param  array<string, float>  $rates
     * @return list<array{code: string, rate: float}>
     */
    private function rateSnapshot(array $rates): array
    {
        return collect($rates)
            ->map(fn (float $rate, string $code) => ['code' => $code, 'rate' => $rate])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function listItem(TaxCalculatorRun $run): array
    {
        return [
            'id' => $run->id,
            'year' => $run->year,
            'month' => $run->month,
            'combined_total' => $run->results['combined_total'] ?? null,
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }
}
