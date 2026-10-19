<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\RegistrySummaryByOfficeDepartmentReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class RegistrySummaryByOfficeDepartmentReportServiceTest extends TestCase
{
    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(RegistrySummaryByOfficeDepartmentReportService::class)->build(
            Carbon::parse('2026-10-04'),
            Carbon::parse('2026-09-07'),
        );
    }
}
