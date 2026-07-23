<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\PayeEmploymentDetailsReportService;
use InvalidArgumentException;
use Tests\TestCase;

class PayeEmploymentDetailsReportServiceTest extends TestCase
{
    public function test_invalid_month_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayeEmploymentDetailsReportService::class)->build(2025, 13);
    }

    public function test_invalid_year_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayeEmploymentDetailsReportService::class)->build(1999, 11);
    }
}
