<?php

namespace App\Modules\Payroll\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class WorkbookPeriodValidator
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function dateColumnFromRows(array $rows): ?string
    {
        foreach ($rows as $row) {
            if ((int) ($row['row'] ?? 0) !== 1) {
                continue;
            }

            foreach ($row as $column => $value) {
                if ($column === 'row' || str_ends_with((string) $column, '_f')) {
                    continue;
                }
                if (strtolower(trim((string) $value)) === 'date') {
                    return (string) $column;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function assertRowsMatchPeriod(
        array $rows,
        ?string $dateColumn,
        int $year,
        int $month,
        string $documentName,
    ): void {
        $periodLabel = $this->periodLabel($year, $month);

        if ($dateColumn === null || $dateColumn === '') {
            throw new InvalidArgumentException(
                "The {$documentName} has no Date column, so it cannot be verified against {$periodLabel}. The file was not imported."
            );
        }

        $found = [];
        $mismatched = [];

        foreach ($rows as $row) {
            if ((int) ($row['row'] ?? 0) <= 1) {
                continue;
            }

            $parsed = $this->parseYearMonth($row[$dateColumn] ?? null);
            if ($parsed === null) {
                continue;
            }

            $key = $parsed['year'].'-'.$parsed['month'];
            $found[$key] = true;
            if ($parsed['year'] !== $year || $parsed['month'] !== $month) {
                $mismatched[$key] = $this->periodLabel($parsed['year'], $parsed['month']);
            }
        }

        if ($found === []) {
            throw new InvalidArgumentException(
                "The {$documentName} has no dates to verify against {$periodLabel}. The file was not imported."
            );
        }

        if ($mismatched !== []) {
            $foundLabel = implode(', ', array_values($mismatched));
            throw new InvalidArgumentException(
                "The {$documentName} has dates that are not in {$periodLabel} (found {$foundLabel}). The file was not imported."
            );
        }
    }

    /**
     * @return array{year: int, month: int}|null
     */
    public function parseYearMonth(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return [
                'year' => (int) $value->format('Y'),
                'month' => (int) $value->format('n'),
            ];
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial < 20000 || $serial >= 80000) {
                return null;
            }

            $date = (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))
                ->add(new DateInterval('P'.(int) round($serial).'D'));

            return [
                'year' => (int) $date->format('Y'),
                'month' => (int) $date->format('n'),
            ];
        }

        $text = trim((string) $value);
        if ($text === '' || strcasecmp($text, 'Date') === 0) {
            return null;
        }

        foreach (['n/j/Y', 'n/j/y', 'Y-m-d', 'j/n/Y', 'j/n/y', 'M j, Y', 'F Y', 'M Y', 'M y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $text, new DateTimeZone('UTC'));
            if (! $date instanceof DateTimeImmutable) {
                continue;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            return [
                'year' => (int) $date->format('Y'),
                'month' => (int) $date->format('n'),
            ];
        }

        return null;
    }

    public function periodLabel(int $year, int $month): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-n', $year.'-'.$month, new DateTimeZone('UTC'));

        return $date instanceof DateTimeImmutable
            ? $date->format('F Y')
            : $year.'-'.$month;
    }
}
