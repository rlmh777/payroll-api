<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Support\XlsxWorkbookReader;
use InvalidArgumentException;
use RuntimeException;

class PurchaseLedgerImportService
{
    private readonly XlsxWorkbookReader $reader;

    public function __construct(?XlsxWorkbookReader $reader = null)
    {
        $this->reader = $reader ?? new XlsxWorkbookReader();
    }

    /**
     * @return array{
     *     format: string,
     *     columns: list<string>,
     *     rows: list<array<string, mixed>>,
     *     name_column: string,
     *     class_column: ?string,
     *     debit_column: string,
     *     date_column: ?string
     * }
     */
    public function parse(string $path): array
    {
        $sheets = $this->reader->read($path);
        if ($sheets === []) {
            throw new InvalidArgumentException('The purchase ledger workbook is empty.');
        }

        $rows = reset($sheets);
        if (! is_array($rows) || $rows === []) {
            throw new InvalidArgumentException('The purchase ledger sheet has no data.');
        }

        $columns = $this->sheetColumns($rows);
        $headerRow = $rows[1] ?? [];
        $nameColumn = $this->findHeaderColumn($headerRow, 'Name') ?? 'F';
        $classColumn = $this->findHeaderColumn($headerRow, 'Class');
        $debitColumn = $this->findHeaderColumn($headerRow, 'Debit') ?? 'N';
        $dateColumn = $this->findHeaderColumn($headerRow, 'Date');

        return [
            'format' => 'transaction',
            'columns' => $columns,
            'rows' => $this->compactSheet($rows),
            'name_column' => $nameColumn,
            'class_column' => $classColumn,
            'debit_column' => $debitColumn,
            'date_column' => $dateColumn,
        ];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>|null  $sheet
     * @return array{
     *     format: string,
     *     columns: list<string>,
     *     rows: list<array<string, mixed>>,
     *     name_column: string,
     *     class_column: ?string,
     *     debit_column: string,
     *     date_column: ?string
     * }
     */
    public function normalizeSheet(mixed $sheet): array
    {
        $defaults = [
            'format' => 'transaction',
            'columns' => [],
            'rows' => [],
            'name_column' => 'F',
            'class_column' => 'L',
            'debit_column' => 'N',
            'date_column' => 'B',
        ];

        if (! is_array($sheet) || $sheet === []) {
            return $defaults;
        }

        if (array_is_list($sheet)) {
            return [
                ...$defaults,
                'columns' => $this->columnsFromCompactRows($sheet),
                'rows' => $sheet,
            ];
        }

        return [
            'format' => (string) ($sheet['format'] ?? 'transaction'),
            'columns' => array_values($sheet['columns'] ?? []),
            'rows' => array_values($sheet['rows'] ?? []),
            'name_column' => (string) ($sheet['name_column'] ?? 'F'),
            'class_column' => isset($sheet['class_column']) ? (string) $sheet['class_column'] : 'L',
            'debit_column' => (string) ($sheet['debit_column'] ?? 'N'),
            'date_column' => isset($sheet['date_column']) ? (string) $sheet['date_column'] : 'B',
        ];
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     * @return list<string>
     */
    private function sheetColumns(array $rows): array
    {
        $headerRow = $rows[1] ?? [];
        $columns = [];
        foreach ($headerRow as $col => $cell) {
            if (trim((string) ($cell['v'] ?? '')) !== '') {
                $columns[] = $col;
            }
        }

        $used = [];
        foreach ($rows as $cells) {
            foreach ($cells as $col => $cell) {
                if (preg_match('/^[A-Z]+$/', $col) === 1) {
                    $used[$col] = true;
                }
            }
        }

        $ordered = $this->sortColumnLetters(array_values(array_unique([
            ...$columns,
            ...array_keys($used),
        ])));

        if (isset($used['A']) && ! in_array('A', $ordered, true)) {
            array_unshift($ordered, 'A');
        }

        return $ordered;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function columnsFromCompactRows(array $rows): array
    {
        $used = [];
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                if ($key === 'row' || str_ends_with((string) $key, '_f')) {
                    continue;
                }
                if (preg_match('/^[A-Z]+$/', (string) $key) === 1) {
                    $used[(string) $key] = true;
                }
            }
        }

        return $this->sortColumnLetters(array_keys($used));
    }

    /**
     * @param  array<string, array{v: mixed, f: ?string}>  $headerRow
     */
    private function findHeaderColumn(array $headerRow, string $header): ?string
    {
        $wanted = strtolower($header);
        foreach ($headerRow as $col => $cell) {
            if (strtolower(trim((string) ($cell['v'] ?? ''))) === $wanted) {
                return $col;
            }
        }

        return null;
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

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function sortColumnLetters(array $columns): array
    {
        usort($columns, fn (string $a, string $b) => $this->columnIndex($a) <=> $this->columnIndex($b));

        return array_values(array_unique($columns));
    }

    private function columnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split($column) as $char) {
            $index = ($index * 26) + (ord($char) - ord('A') + 1);
        }

        return $index;
    }
}
