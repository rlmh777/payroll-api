<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Support\WorkbookPeriodValidator;
use App\Modules\Payroll\Support\XlsxWorkbookReader;
use InvalidArgumentException;

class TaxWorkbookImportService
{
    private readonly XlsxWorkbookReader $reader;
    private readonly WorkbookPeriodValidator $periodValidator;

    public function __construct(
        ?XlsxWorkbookReader $reader = null,
        ?WorkbookPeriodValidator $periodValidator = null,
    ) {
        $this->reader = $reader ?? new XlsxWorkbookReader();
        $this->periodValidator = $periodValidator ?? new WorkbookPeriodValidator();
    }

    /**
     * @return array{
     *     accounts_sheet: list<array<string, mixed>>,
     *     gst_sheet: list<array<string, mixed>>,
     *     lines: list<array<string, mixed>>,
     *     total_debits: float,
     *     partial_exemptions_total: float,
     *     net_of_2251: float
     * }
     */
    public function parse(string $path, ?int $year = null, ?int $month = null): array
    {
        $sheets = $this->reader->read($path);
        [$accountsSheet, $gstSheet] = $this->detectSheets($sheets);

        $gstCompact = $this->compactSheet($gstSheet);
        if ($year !== null && $month !== null) {
            $this->periodValidator->assertRowsMatchPeriod(
                $gstCompact,
                $this->periodValidator->dateColumnFromRows($gstCompact),
                $year,
                $month,
                'GST workbook',
            );
        }

        $gst = $this->extractGstTotals($gstSheet);

        return [
            'accounts_sheet' => $this->compactSheet($accountsSheet),
            'gst_sheet' => $gstCompact,
            'lines' => $this->extractAccountLines($accountsSheet),
            'total_debits' => $gst['total_debits'],
            'partial_exemptions_total' => $gst['partial_exemptions_total'],
            'net_of_2251' => $gst['net_of_2251'],
        ];
    }

    /**
     * Identify accounts vs GST from sheet contents. Names are only a weak hint.
     *
     * @param  array<string, array<int, array<string, array{v: mixed, f: ?string}>>>  $sheets
     * @return array{0: array<int, array<string, array{v: mixed, f: ?string}>>, 1: array<int, array<string, array{v: mixed, f: ?string}>>}
     */
    private function detectSheets(array $sheets): array
    {
        if (count($sheets) < 2) {
            throw new InvalidArgumentException('The workbook must include both an accounts (P&L / Taxes Calculator) sheet and a GST sheet.');
        }

        $scored = [];
        foreach ($sheets as $name => $rows) {
            $scored[] = [
                'name' => (string) $name,
                'rows' => $rows,
                'gst' => $this->gstSheetScore($rows, (string) $name),
                'accounts' => $this->accountsSheetScore($rows, (string) $name),
            ];
        }

        $gstWinner = null;
        $gstScore = -1;
        foreach ($scored as $item) {
            if ($item['gst'] > $gstScore) {
                $gstWinner = $item;
                $gstScore = $item['gst'];
            }
        }

        $accountsWinner = null;
        $accountsScore = -1;
        foreach ($scored as $item) {
            if ($gstWinner !== null && $item['name'] === $gstWinner['name']) {
                continue;
            }
            if ($item['accounts'] > $accountsScore) {
                $accountsWinner = $item;
                $accountsScore = $item['accounts'];
            }
        }

        if ($gstWinner === null || $gstScore <= 0) {
            throw new InvalidArgumentException('Could not detect a GST sheet. Include a GST payable register (Debit / Credit / Balance) in the workbook.');
        }
        if ($accountsWinner === null || $accountsScore <= 0) {
            throw new InvalidArgumentException('Could not detect an accounts / P&L sheet in the workbook.');
        }

        return [$accountsWinner['rows'], $gstWinner['rows']];
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     */
    private function gstSheetScore(array $rows, string $name): int
    {
        $text = $this->sheetText($rows);
        $score = 0;

        if ($this->findHeaderColumn($rows, 'Debit') && $this->findHeaderColumn($rows, 'Balance')) {
            $score += 6;
        }
        if ($this->findHeaderColumn($rows, 'Credit')) {
            $score += 2;
        }
        if (preg_match('/\bgst payable\b/', $text)) {
            $score += 4;
        }
        if (preg_match('/\b2251\b/', $text)) {
            $score += 3;
        }
        if (preg_match('/partial exemption/', $text)) {
            $score += 2;
        }
        if (preg_match('/\b(bill|general journal|check|cheque)\b/', $text)) {
            $score += 2;
        }
        if (preg_match('/gst/i', $name)) {
            $score += 1;
        }

        return $score;
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     */
    private function accountsSheetScore(array $rows, string $name): int
    {
        $text = $this->sheetText($rows);
        $score = 0;

        if (preg_match('/ordinary income|net income|gross profit/', $text)) {
            $score += 5;
        }
        if (preg_match('/\btotal income\b/', $text)) {
            $score += 3;
        }
        if (preg_match('/taxes calculator|profit and loss|p&l/', $text.' '.strtolower($name))) {
            $score += 3;
        }
        if (preg_match_all('/\b\d{4}[a-z]?\s*[·.]\s+/i', $text, $matches)) {
            $score += min(6, count($matches[0]));
        }
        if (preg_match('/\b(accounts|p&l|profit)\b/i', $name)) {
            $score += 1;
        }
        if ($this->findHeaderColumn($rows, 'Debit') && $this->findHeaderColumn($rows, 'Balance')) {
            $score -= 6;
        }

        return $score;
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     */
    private function sheetText(array $rows): string
    {
        $parts = [];
        $count = 0;
        foreach ($rows as $cells) {
            foreach ($cells as $cell) {
                $value = trim((string) ($cell['v'] ?? ''));
                if ($value !== '' && ! is_numeric($value)) {
                    $parts[] = $value;
                    $count++;
                    if ($count >= 200) {
                        break 2;
                    }
                }
            }
        }

        return $this->normalizeLabel(implode(' ', $parts));
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     * @return list<array<string, mixed>>
     */
    public function extractAccountLines(array $rows): array
    {
        $amountCol = $this->findAmountColumn($rows);
        $parsed = [];
        foreach ($rows as $rowNumber => $cells) {
            $labelInfo = $this->firstLabel($cells, $amountCol);
            if ($labelInfo === null) {
                continue;
            }
            $label = $labelInfo['label'];
            $parsedCode = $this->parseAccountLabel($label);
            $amount = $this->numericValue($cells[$amountCol]['v'] ?? null);
            $formula = $cells[$amountCol]['f'] ?? null;
            $parsed[$rowNumber] = [
                'row' => $rowNumber,
                'label' => $label,
                'label_column' => $labelInfo['column'],
                'code' => $parsedCode['code'] ?? null,
                'name' => $parsedCode['name'] ?? $label,
                'is_total' => $parsedCode['is_total'] ?? str_starts_with(strtolower($label), 'total '),
                'amount' => $amount,
                'formula' => $formula,
            ];
        }

        $netRollupChildren = [];
        $simpleSumTotals = [];
        $referencedByHigherTotal = [];
        foreach ($parsed as $rowNumber => $row) {
            if (! ($row['is_total'] ?? false) || ! $row['formula']) {
                continue;
            }

            $sumChildren = $this->sumRangeRows($row['formula']);
            if ($sumChildren !== []) {
                if ($this->isNetRollup($row, $parsed, $sumChildren)) {
                    foreach ($sumChildren as $childRow) {
                        $netRollupChildren[$childRow] = true;
                    }
                } else {
                    $simpleSumTotals[$rowNumber] = true;
                }
                continue;
            }

            foreach ($this->plusReferencedRows($row['formula']) as $childRow) {
                if (! $this->isGrandTotalLabel((string) ($row['label'] ?? ''))) {
                    $referencedByHigherTotal[$childRow] = $rowNumber;
                }
            }
        }

        $lines = [];
        $sort = 0;
        foreach ($parsed as $rowNumber => $row) {
            if ($this->isSkippableLabel($row['label'])) {
                continue;
            }

            $rowType = $this->rowType($row);
            if ($rowType === null) {
                continue;
            }

            $isTotal = in_array($rowType, ['total', 'grand_total'], true);
            $includeInTax = ! in_array($rowType, ['heading', 'grand_total'], true)
                && ! isset($netRollupChildren[$rowNumber])
                && ! isset($referencedByHigherTotal[$rowNumber])
                && ! isset($simpleSumTotals[$rowNumber]);

            $lines[] = [
                'account_code' => $row['code'],
                'account_name' => $this->displayName($row, $rowType),
                'amount' => $row['amount'] ?? 0,
                'is_rollup' => $isTotal,
                'row_type' => $rowType,
                'include_in_tax' => $includeInTax,
                'label_column' => $row['label_column'],
                'group_index' => 0,
                'sort_order' => $sort++,
            ];
        }

        return $this->assignGroups($lines);
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     * @return array{total_debits: float, partial_exemptions_total: float, net_of_2251: float}
     */
    public function extractGstTotals(array $rows): array
    {
        $debitCol = $this->findHeaderColumn($rows, 'Debit') ?? 'M';
        $balanceCol = $this->findHeaderColumn($rows, 'Balance') ?? 'Q';

        $partial = 0.0;
        $totalDebits = 0.0;
        $net = 0.0;

        foreach ($rows as $cells) {
            $labelInfo = $this->firstLabel($cells);
            if ($labelInfo === null) {
                continue;
            }
            $label = $labelInfo['label'];
            $normalized = $this->normalizeLabel($label);

            if (str_starts_with($normalized, 'total 2251-a') && str_contains($normalized, 'partial exemption')) {
                $partial = $this->numericValue($cells[$debitCol]['v'] ?? null) ?? 0.0;
                continue;
            }

            if (
                str_starts_with($normalized, 'total 2251')
                && str_contains($normalized, 'gst payable')
                && ! str_contains($normalized, 'other')
                && ! str_contains($normalized, '2251-a')
            ) {
                $totalDebits = $this->numericValue($cells[$debitCol]['v'] ?? null) ?? 0.0;
                $net = $this->numericValue($cells[$balanceCol]['v'] ?? null) ?? 0.0;
            }
        }

        return [
            'total_debits' => $totalDebits,
            'partial_exemptions_total' => $partial,
            'net_of_2251' => $net,
        ];
    }

    /**
     * @param  array<string, array{v: mixed, f: ?string}>  $cells
     * @return array{label: string, column: string}|null
     */
    private function firstLabel(array $cells, ?string $skipColumn = null): ?array
    {
        foreach ($this->labelColumns() as $col) {
            if ($skipColumn !== null && strcasecmp($col, $skipColumn) === 0) {
                continue;
            }
            $value = trim((string) ($cells[$col]['v'] ?? ''));
            if ($value !== '') {
                return ['label' => $value, 'column' => $col];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function labelColumns(): array
    {
        return ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];
    }

    /**
     * Amounts live in E for Taxes Calculator exports and further right (often I) on P&L sheets.
     *
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     */
    private function findAmountColumn(array $rows): string
    {
        $counts = [];
        foreach ($rows as $cells) {
            foreach ($cells as $col => $cell) {
                if ($this->numericValue($cell['v'] ?? null) === null) {
                    continue;
                }
                if (isset($cell['f']) && is_string($cell['f']) && $cell['f'] !== '') {
                    $counts[$col] = ($counts[$col] ?? 0) + 3;
                    continue;
                }
                $counts[$col] = ($counts[$col] ?? 0) + 1;
            }
        }

        foreach ($this->labelColumns() as $col) {
            if (($counts[$col] ?? 0) > 0 && $this->columnLooksLikeLabels($rows, $col)) {
                unset($counts[$col]);
            }
        }

        if ($counts === []) {
            return 'E';
        }

        arsort($counts);
        $best = array_key_first($counts);

        return is_string($best) && $best !== '' ? $best : 'E';
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     */
    private function columnLooksLikeLabels(array $rows, string $col): bool
    {
        $text = 0;
        $numeric = 0;
        foreach ($rows as $cells) {
            $value = $cells[$col]['v'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($this->numericValue($value) !== null && ! preg_match('/[A-Za-z]/', (string) $value)) {
                $numeric++;
            } else {
                $text++;
            }
        }

        return $text > $numeric;
    }

    /**
     * @return array{code: ?string, name: string, is_total: bool}
     */
    public function parseAccountLabel(string $label): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        $isTotal = (bool) preg_match('/^total\s+/i', $clean);

        if (preg_match('/^(?:Total\s+)?(\d+)\s+([A-Za-z])\s*[·.]\s*(.+)$/u', $clean, $match)) {
            return [
                'code' => $this->normalizeCode($match[1].$match[2]),
                'name' => trim($match[3]),
                'is_total' => $isTotal,
            ];
        }

        if (preg_match('/^(?:Total\s+)?(\d+[A-Za-z]*)\s*[·.]\s*(.+)$/u', $clean, $match)) {
            return [
                'code' => $this->normalizeCode($match[1]),
                'name' => trim($match[2]),
                'is_total' => $isTotal,
            ];
        }

        return ['code' => null, 'name' => $clean, 'is_total' => $isTotal];
    }

    public function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', $code) ?? $code);
    }

    private function isSkippableLabel(string $label): bool
    {
        $normalized = strtolower(trim($label));

        return $normalized === '' || str_starts_with($normalized, '(revised');
    }

    /**
     * @param  array{label: string, label_column?: string, code: ?string, is_total: bool, amount: ?float}  $row
     */
    private function rowType(array $row): ?string
    {
        $label = trim((string) $row['label']);
        $hasAmount = $row['amount'] !== null;
        $isTotal = (bool) ($row['is_total'] ?? false) || $this->isGrandTotalLabel($label);

        if ($this->isGrandTotalLabel($label)) {
            return $hasAmount ? 'grand_total' : 'heading';
        }

        if ($isTotal) {
            return 'total';
        }

        if (! $hasAmount) {
            return 'heading';
        }

        if ($row['code'] === null) {
            return 'heading';
        }

        return 'account';
    }

    /**
     * @param  array{label: string, code: ?string, name: string, is_total: bool}  $row
     */
    private function displayName(array $row, string $rowType): string
    {
        if ($rowType !== 'account') {
            return trim((string) $row['label']);
        }

        $code = $row['code'] ?? null;
        $name = trim((string) ($row['name'] ?? $row['label']));
        if ($code && ! str_starts_with($name, $code)) {
            return $code.' · '.$name;
        }

        return $name;
    }

    private function isGrandTotalLabel(string $label): bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $label) ?? $label));

        return in_array($normalized, ['total income', 'total resort income'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function assignGroups(array $lines): array
    {
        $group = 0;
        foreach ($lines as &$line) {
            if ($this->isMajorSectionHeading($line)) {
                $group++;
            } elseif ($group === 0) {
                $group = 1;
            }

            $line['group_index'] = $group;
        }
        unset($line);

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isMajorSectionHeading(array $line): bool
    {
        if (($line['row_type'] ?? '') !== 'heading') {
            return false;
        }

        if (($line['label_column'] ?? 'B') !== 'A') {
            return false;
        }

        $label = trim((string) ($line['account_name'] ?? ''));
        if ($label === '' || str_starts_with(strtolower($label), 'total ')) {
            return false;
        }

        if (str_ends_with($label, ':')) {
            return true;
        }

        if (str_contains(strtolower($label), 'guava limb')) {
            return true;
        }

        $code = strtoupper(preg_replace('/\s+/', '', (string) ($line['account_code'] ?? '')) ?? '');

        return strlen($code) === 4 && str_ends_with($code, '00');
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @param  list<int>  $childRows
     */
    private function isNetRollup(array $totalRow, array $parsed, array $childRows): bool
    {
        $label = strtolower((string) ($totalRow['label'] ?? ''));
        if (str_contains($label, '(net)')) {
            return true;
        }

        foreach ($childRows as $childRow) {
            $childLabel = strtolower((string) ($parsed[$childRow]['label'] ?? ''));
            if (str_contains($childLabel, '(gross)')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @param  list<int>  $rows
     */
    private function referencesAnotherTotal(array $parsed, array $rows): bool
    {
        foreach ($rows as $rowNumber) {
            if (! empty($parsed[$rowNumber]['is_total'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function sumRangeRows(string $formula): array
    {
        if (! preg_match('/SUM\(([A-Z]+)(\d+):([A-Z]+)(\d+)\)/i', $formula, $match)) {
            return [];
        }

        $start = (int) $match[2];
        $end = (int) $match[4];
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        return range($start, $end);
    }

    /**
     * @return list<int>
     */
    private function plusReferencedRows(string $formula): array
    {
        if (preg_match('/SUM\(/i', $formula)) {
            return [];
        }
        if (! preg_match_all('/[A-Z]+(\d+)/i', $formula, $match)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $match[1])));
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     */
    private function findHeaderColumn(array $rows, string $header): ?string
    {
        $wanted = strtolower($header);
        foreach (array_slice($rows, 0, 5, true) as $cells) {
            foreach ($cells as $col => $cell) {
                if (strtolower(trim((string) ($cell['v'] ?? ''))) === $wanted) {
                    return $col;
                }
            }
        }

        return null;
    }

    private function numericValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $clean === '' || $clean === '-' ? null : (float) $clean;
    }

    private function normalizeLabel(string $label): string
    {
        $label = strtolower(trim(preg_replace('/\s+/', ' ', $label) ?? $label));

        return str_replace(['·', '•'], ' ', $label);
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     * @return list<array<string, mixed>>
     */
    private function compactSheet(array $rows): array
    {
        $out = [];
        foreach ($rows as $rowNumber => $cells) {
            $compact = ['row' => $rowNumber];
            foreach ($cells as $col => $cell) {
                $compact[$col] = $cell['v'];
                if (! empty($cell['f'])) {
                    $compact[$col.'_f'] = $cell['f'];
                }
            }
            $out[] = $compact;
        }

        return $out;
    }
}
