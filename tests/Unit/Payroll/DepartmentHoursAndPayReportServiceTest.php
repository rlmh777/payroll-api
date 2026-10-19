<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\DepartmentHoursAndPayReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class DepartmentHoursAndPayReportServiceTest extends TestCase
{
    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(DepartmentHoursAndPayReportService::class)->build(
            Carbon::parse('2026-10-19'),
            Carbon::parse('2026-10-23'),
            'site',
        );
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(DepartmentHoursAndPayReportService::class)->build(
            Carbon::parse('2026-10-23'),
            Carbon::parse('2026-10-19'),
            DepartmentHoursAndPayReportService::SCOPE_COMPANY,
        );
    }

    public function test_department_scope_requires_department(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(DepartmentHoursAndPayReportService::class)->build(
            Carbon::parse('2026-10-19'),
            Carbon::parse('2026-10-23'),
            DepartmentHoursAndPayReportService::SCOPE_DEPARTMENT,
        );
    }

    public function test_group_scope_requires_group(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(DepartmentHoursAndPayReportService::class)->build(
            Carbon::parse('2026-10-19'),
            Carbon::parse('2026-10-23'),
            DepartmentHoursAndPayReportService::SCOPE_GROUP,
        );
    }
}
