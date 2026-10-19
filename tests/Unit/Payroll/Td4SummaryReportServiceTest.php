<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\Td4SummaryReportService;
use InvalidArgumentException;
use Tests\TestCase;

class Td4SummaryReportServiceTest extends TestCase
{
    public function test_year_outside_the_supported_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Td4SummaryReportService::class)->build(1999);
    }
}
