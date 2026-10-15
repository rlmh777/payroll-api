<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Support\WorkbookPeriodValidator;
use App\Modules\Payroll\Support\XlsxWorkbookReader;
use InvalidArgumentException;

class SalesLedgerImportService
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
     * Raw Numbers Sales Ledger layout (Sheet1):
     * B = class (section header, carried forward), C = date, E = invoice,
     * G = name, I = debit, K = credit.
     *
     * @return array{
     *     format: string,
     *     columns: list<string>,
     *     rows: list<array<string, mixed>>,
     *     class_column: string,
     *     date_column: string,
     *     invoice_column: string,
     *     name_column: string,
     *     debit_column: string,
     *     credit_column: string
     * }
     */
    public function parse(string $path, ?int $year = null, ?int $month = null): array
    {
        $sheets = $this->reader->read($path);
        if ($sheets === []) {
            throw new InvalidArgumentException('The sales ledger workbook is empty.');
        }

        $rows = reset($sheets);
        if (! is_array($rows) || $rows === []) {
            throw new InvalidArgumentException('The sales ledger sheet has no data.');
        }

        $classColumn = 'B';
        $dateColumn = 'C';
        $invoiceColumn = 'E';
        $nameColumn = 'G';
        $debitColumn = 'I';
        $creditColumn = 'K';

        $compactRows = $this->compactWithClassCarryForward(
            $rows,
            $classColumn,
            $dateColumn,
            $invoiceColumn,
            $nameColumn,
            $debitColumn,
            $creditColumn,
        );

        $dataRows = array_values(array_filter(
            $compactRows,
            fn (array $row) => (int) ($row['row'] ?? 0) > 1 && ! $this->isSectionOrTotalRow($row, $classColumn, $dateColumn, $invoiceColumn),
        ));

        if ($dataRows === []) {
            throw new InvalidArgumentException('The sales ledger has no invoice rows. The file was not imported.');
        }

        if ($year !== null && $month !== null) {
            $this->periodValidator->assertRowsMatchPeriod(
                $dataRows,
                $dateColumn,
                $year,
                $month,
                'sales ledger',
            );
        }

        return [
            'format' => 'sales_transaction',
            'columns' => ['B', 'C', 'E', 'G', 'I', 'K'],
            'rows' => $compactRows,
            'class_column' => $classColumn,
            'date_column' => $dateColumn,
            'invoice_column' => $invoiceColumn,
            'name_column' => $nameColumn,
            'debit_column' => $debitColumn,
            'credit_column' => $creditColumn,
        ];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>|null  $sheet
     * @return array{
     *     format: string,
     *     columns: list<string>,
     *     rows: list<array<string, mixed>>,
     *     class_column: string,
     *     date_column: string,
     *     invoice_column: string,
     *     name_column: string,
     *     debit_column: string,
     *     credit_column: string
     * }
     */
    public function normalizeSheet(mixed $sheet): array
    {
        $defaults = [
            'format' => 'sales_transaction',
            'columns' => ['B', 'C', 'E', 'G', 'I', 'K'],
            'rows' => [],
            'class_column' => 'B',
            'date_column' => 'C',
            'invoice_column' => 'E',
            'name_column' => 'G',
            'debit_column' => 'I',
            'credit_column' => 'K',
        ];

        if (! is_array($sheet) || $sheet === []) {
            return $defaults;
        }

        if (array_is_list($sheet)) {
            return [
                ...$defaults,
                'rows' => $sheet,
            ];
        }

        return [
            'format' => (string) ($sheet['format'] ?? 'sales_transaction'),
            'columns' => array_values($sheet['columns'] ?? $defaults['columns']),
            'rows' => array_values($sheet['rows'] ?? []),
            'class_column' => (string) ($sheet['class_column'] ?? 'B'),
            'date_column' => (string) ($sheet['date_column'] ?? 'C'),
            'invoice_column' => (string) ($sheet['invoice_column'] ?? 'E'),
            'name_column' => (string) ($sheet['name_column'] ?? 'G'),
            'debit_column' => (string) ($sheet['debit_column'] ?? 'I'),
            'credit_column' => (string) ($sheet['credit_column'] ?? 'K'),
        ];
    }

    /**
     * @param  array<int, array<string, array{v: mixed, f: ?string}>>  $rows
     * @return list<array<string, mixed>>
     */
    private function compactWithClassCarryForward(
        array $rows,
        string $classColumn,
        string $dateColumn,
        string $invoiceColumn,
        string $nameColumn,
        string $debitColumn,
        string $creditColumn,
    ): array {
        $compact = [];
        $currentClass = null;

        ksort($rows);

        foreach ($rows as $rowNumber => $cells) {
            // Excel header row (Date / Num / Name / Debit / Credit) is not imported.
            if ((int) $rowNumber === 1) {
                continue;
            }

            $classValue = trim((string) ($cells[$classColumn]['v'] ?? ''));
            if ($classValue !== '') {
                $currentClass = $classValue;
            }

            $dateValue = $cells[$dateColumn]['v'] ?? null;
            $invoiceValue = $cells[$invoiceColumn]['v'] ?? null;
            $nameValue = $cells[$nameColumn]['v'] ?? null;
            $debitValue = $cells[$debitColumn]['v'] ?? null;
            $creditValue = $cells[$creditColumn]['v'] ?? null;

            $hasData = $dateValue !== null && $dateValue !== ''
                || $invoiceValue !== null && $invoiceValue !== ''
                || $nameValue !== null && $nameValue !== ''
                || $this->isNonEmptyNumber($debitValue)
                || $this->isNonEmptyNumber($creditValue);

            if ($classValue !== '' && ! $hasData) {
                // Section title row (e.g. "Taxable Sales") — keep for structure.
                $compact[] = [
                    'row' => (int) $rowNumber,
                    $classColumn => $classValue,
                    $dateColumn => null,
                    $invoiceColumn => null,
                    $nameColumn => null,
                    $debitColumn => null,
                    $creditColumn => null,
                    'is_section' => true,
                ];
                continue;
            }

            if (! $hasData) {
                continue;
            }

            $row = [
                'row' => (int) $rowNumber,
                $classColumn => $currentClass,
                $dateColumn => $dateValue,
                $invoiceColumn => $invoiceValue,
                $nameColumn => $nameValue,
                $debitColumn => $this->toNumberOrNull($debitValue),
                $creditColumn => $this->toNumberOrNull($creditValue),
            ];

            if ($currentClass !== null && str_starts_with(strtolower($currentClass), 'total ')) {
                $row['is_total'] = true;
            }

            $compact[] = $row;
        }

        return $compact;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isSectionOrTotalRow(
        array $row,
        string $classColumn,
        string $dateColumn,
        string $invoiceColumn,
    ): bool {
        if (! empty($row['is_section']) || ! empty($row['is_total'])) {
            return true;
        }

        $class = strtolower(trim((string) ($row[$classColumn] ?? '')));
        if ($class !== '' && str_starts_with($class, 'total ')) {
            return true;
        }

        $date = $row[$dateColumn] ?? null;
        $invoice = $row[$invoiceColumn] ?? null;

        return ($date === null || $date === '') && ($invoice === null || $invoice === '');
    }

    private function isNonEmptyNumber(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return is_numeric($value);
    }

    private function toNumberOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
