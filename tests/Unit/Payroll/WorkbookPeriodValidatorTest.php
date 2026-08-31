<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Support\WorkbookPeriodValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WorkbookPeriodValidatorTest extends TestCase
{
    private WorkbookPeriodValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new WorkbookPeriodValidator();
    }

    public function test_accepts_excel_serials_in_the_selected_month(): void
    {
        $this->validator->assertRowsMatchPeriod(
            $this->rows([46204, 46216, 46234]),
            'B',
            2026,
            7,
            'purchase ledger',
        );

        $this->addToAssertionCount(1);
    }

    public function test_rejects_excel_serials_outside_the_selected_month(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not in July 2026');
        $this->expectExceptionMessage('found June 2026');
        $this->expectExceptionMessage('was not imported');

        $this->validator->assertRowsMatchPeriod(
            $this->rows([46174, 46204]),
            'B',
            2026,
            7,
            'purchase ledger',
        );
    }

    public function test_rejects_files_without_a_date_column(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no Date column');

        $this->validator->assertRowsMatchPeriod(
            $this->rows([46204]),
            null,
            2026,
            7,
            'GST workbook',
        );
    }

    public function test_rejects_files_without_any_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no dates to verify');

        $this->validator->assertRowsMatchPeriod(
            $this->rows(['', 'Date']),
            'B',
            2026,
            7,
            'GST workbook',
        );
    }

    public function test_finds_the_date_header_column(): void
    {
        $column = $this->validator->dateColumnFromRows([
            ['row' => 1, 'A' => 'Name', 'B' => 'Date', 'C' => 'Debit'],
            ['row' => 2, 'B' => 46204],
        ]);

        $this->assertSame('B', $column);
    }

    /**
     * @param  list<int|string>  $dates
     * @return list<array<string, mixed>>
     */
    private function rows(array $dates): array
    {
        $rows = [
            ['row' => 1, 'B' => 'Date'],
        ];
        foreach ($dates as $index => $date) {
            $rows[] = ['row' => $index + 2, 'B' => $date];
        }

        return $rows;
    }
}
