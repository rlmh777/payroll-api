<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\EmolumentsAndWithheldTaxReportService;
use InvalidArgumentException;
use Tests\TestCase;

class EmolumentsAndWithheldTaxReportServiceTest extends TestCase
{
    public function test_invalid_year_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(EmolumentsAndWithheldTaxReportService::class)->build(1999, EmolumentsAndWithheldTaxReportService::SCOPE_COMPANY);
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(EmolumentsAndWithheldTaxReportService::class)->build(2025, 'group');
    }

    public function test_department_scope_requires_department(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(EmolumentsAndWithheldTaxReportService::class)->build(
            2025,
            EmolumentsAndWithheldTaxReportService::SCOPE_DEPARTMENT,
        );
    }
}
