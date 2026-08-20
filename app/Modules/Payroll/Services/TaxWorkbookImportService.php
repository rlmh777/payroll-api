<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Support\XlsxWorkbookReader;
use InvalidArgumentException;

class TaxWorkbookImportService
{
    private readonly XlsxWorkbookReader $reader;

    public function __construct(?XlsxWorkbookReader $reader = null)
    {
        $this->reader = $reader ?? new XlsxWorkbookReader();
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
    public function parse(string $path): array
    {
        $sheets = $this->reader->read($path);
        $accountsSheet = $this->sheetByName($sheets, ['Taxes Calculator', 'Accounts']);
        $gstSheet = $this->sheetByName($sheets, ['GST']);

        if ($accountsSheet === null) {
            throw new InvalidArgumentException('The workbook must include a Taxes Calculator (accounts) sheet.');
        }
        if ($gstSheet === null) {
            throw new InvalidArgumentException('The workbook must include a GST sheet.');
        }

        $gst = $this->extractGstTotals($gstSheet);

        return [
            'accounts_sheet' => $this->compactSheet($accountsSheet),
            'gst_sheet' => $this->compactSheet($gstSheet),
            'lines' => $this->extractAccountLines($accountsSheet),
            'total_debits' => $gst['total_debits'],
            'partial_exemptions_total' => $gst['partial_exemptions_total'],
            'net_of_2251' => $gst['net_of_2251'],
        ];
    }

    /**
     * @param  array<string, array<int, array<string, array{v: mixed, f: ?string}>>>  $sheets
     * @param  list<string>  $names
     * @return array<int, array<string, array{v: mixed, f: ?string}>>|null
     */
    private function sheetByName(array $sheets, array $names): ?array
    {
        foreach ($names as $name) {
            foreach ($sheets as $actual => $rows) {
                if (strcasecmp(trim($actual), $name) === 0) {
                    return $rows;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     * @return list<array<string, mixed>>
     */
    public function extractAccountLines(array $rows): array
    {
        $parsed = [];
        foreach ($rows as $rowNumber => $cells) {
            $labelInfo = $this->firstLabel($cells);
            if ($labelInfo === null) {
                continue;
            }
            $label = $labelInfo['label'];
            $parsedCode = $this->parseAccountLabel($label);
            $amount = $this->numericValue($cells['E']['v'] ?? null);
            $formula = $cells['E']['f'] ?? null;
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
    private function firstLabel(array $cells): ?array
    {
        foreach (['A', 'B', 'C', 'D'] as $col) {
            $value = trim((string) ($cells[$col]['v'] ?? ''));
            if ($value !== '') {
                return ['label' => $value, 'column' => $col];
            }
        }

        return null;
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
