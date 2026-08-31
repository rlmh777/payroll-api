<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Support\WorkbookPeriodValidator;
use App\Modules\Payroll\Support\XlsxWorkbookReader;
use InvalidArgumentException;
use RuntimeException;

class PurchaseLedgerImportService
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
     *     format: string,
     *     columns: list<string>,
     *     rows: list<array<string, mixed>>,
     *     name_column: string,
     *     class_column: ?string,
     *     debit_column: string,
     *     date_column: ?string,
     *     tin_column: ?string,
     *     invoice_column: ?string
     * }
     */
    public function parse(string $path, ?int $year = null, ?int $month = null): array
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
        $tinColumn = $this->findTinColumn($headerRow);
        $invoiceColumn = $this->findInvoiceColumn($headerRow);
        $compactRows = $this->compactSheet($rows);

        if ($year !== null && $month !== null) {
            $this->periodValidator->assertRowsMatchPeriod(
                $compactRows,
                $dateColumn,
                $year,
                $month,
                'purchase ledger',
            );
            $this->assertRecordsHaveClass(
                $compactRows,
                $classColumn,
                $dateColumn,
                $debitColumn,
                $nameColumn,
            );
        }

        return [
            'format' => 'transaction',
            'columns' => $columns,
            'rows' => $compactRows,
            'name_column' => $nameColumn,
            'class_column' => $classColumn,
            'debit_column' => $debitColumn,
            'date_column' => $dateColumn,
            'tin_column' => $tinColumn,
            'invoice_column' => $invoiceColumn,
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
     *     date_column: ?string,
     *     tin_column: ?string,
     *     invoice_column: ?string
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
            'tin_column' => 'J',
            'invoice_column' => 'D',
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
            'tin_column' => isset($sheet['tin_column']) ? (string) $sheet['tin_column'] : 'J',
            'invoice_column' => isset($sheet['invoice_column']) ? (string) $sheet['invoice_column'] : 'D',
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
     * @param  array<string, array{v: mixed, f: ?string}>  $headerRow
     */
    private function findTinColumn(array $headerRow): ?string
    {
        foreach ($headerRow as $col => $cell) {
            $label = strtolower(trim((string) ($cell['v'] ?? '')));
            if ($label === '') {
                continue;
            }
            if (
                $label === 'tin'
                || str_contains($label, 'tax id')
                || str_contains($label, 'ssn')
                || str_contains($label, 'tin')
            ) {
                return $col;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{v: mixed, f: ?string}>  $headerRow
     */
    private function findInvoiceColumn(array $headerRow): ?string
    {
        foreach (['Num', 'Invoice No', 'Invoice #', 'Invoice Number', 'Invoice'] as $header) {
            $column = $this->findHeaderColumn($headerRow, $header);
            if ($column !== null) {
                return $column;
            }
        }

        foreach ($headerRow as $col => $cell) {
            $label = strtolower(trim((string) ($cell['v'] ?? '')));
            if ($label === '') {
                continue;
            }
            if ($label === 'num' || str_contains($label, 'invoice')) {
                return $col;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertRecordsHaveClass(
        array $rows,
        ?string $classColumn,
        ?string $dateColumn,
        string $debitColumn,
        string $nameColumn,
    ): void {
        if ($classColumn === null || $classColumn === '') {
            throw new InvalidArgumentException(
                'The purchase ledger has no Class column. The file was not imported.'
            );
        }

        $missing = [];
        $carryClass = '';

        foreach ($rows as $row) {
            $rowNumber = (int) ($row['row'] ?? 0);
            if ($rowNumber <= 1) {
                continue;
            }
            if ($this->isPeriodMarkerRow($row, $dateColumn)) {
                $carryClass = '';
                continue;
            }

            $class = trim((string) ($row[$classColumn] ?? ''));
            if ($class !== '') {
                $carryClass = $class;
            }

            $debit = $this->numericValue($row[$debitColumn] ?? null);
            if ($debit === null || $debit === 0.0) {
                continue;
            }

            if ($class === '' && $carryClass === '') {
                $name = trim((string) ($row[$nameColumn] ?? ''));
                $missing[] = $name !== ''
                    ? 'row '.$rowNumber.' ('.$name.')'
                    : 'row '.$rowNumber;
            }
        }

        if ($missing === []) {
            return;
        }

        $count = count($missing);
        $examples = implode(', ', array_slice($missing, 0, 5));
        $message = $count === 1
            ? "The purchase ledger has a record without a class assigned ({$examples}). The file was not imported."
            : "The purchase ledger has {$count} records without a class assigned (for example {$examples}). The file was not imported.";

        throw new InvalidArgumentException($message);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isPeriodMarkerRow(array $row, ?string $dateColumn): bool
    {
        $marker = trim((string) ($row['A'] ?? ''));
        if ($marker === '' || $dateColumn === null) {
            return false;
        }

        return trim((string) ($row[$dateColumn] ?? '')) === '';
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
