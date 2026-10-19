<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\LaborCostToDateReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class LaborCostToDateReportServiceTest extends TestCase
{
    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(LaborCostToDateReportService::class)->build(
            Carbon::parse('2026-10-19'),
            Carbon::parse('2026-10-23'),
            'site',
        );
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(LaborCostToDateReportService::class)->build(
            Carbon::parse('2026-10-23'),
            Carbon::parse('2026-10-19'),
            LaborCostToDateReportService::SCOPE_COMPANY,
        );
    }

    public function test_department_scope_requires_department(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(LaborCostToDateReportService::class)->build(
            Carbon::parse('2026-10-19'),
            Carbon::parse('2026-10-23'),
            LaborCostToDateReportService::SCOPE_DEPARTMENT,
        );
    }

    public function test_group_scope_requires_group(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(LaborCostToDateReportService::class)->build(
            Carbon::parse('2026-10-19'),
            Carbon::parse('2026-10-23'),
            LaborCostToDateReportService::SCOPE_GROUP,
        );
    }
}
