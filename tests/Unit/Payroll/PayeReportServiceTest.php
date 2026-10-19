<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\PayeReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class PayeReportServiceTest extends TestCase
{
    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayeReportService::class)->build(
            Carbon::parse('2026-10-01'),
            Carbon::parse('2026-10-31'),
            'group',
        );
    }

    public function test_department_scope_requires_department(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayeReportService::class)->build(
            Carbon::parse('2026-10-01'),
            Carbon::parse('2026-10-31'),
            PayeReportService::SCOPE_DEPARTMENT,
        );
    }

    public function test_employee_scope_requires_employee(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayeReportService::class)->build(
            Carbon::parse('2026-10-01'),
            Carbon::parse('2026-10-31'),
            PayeReportService::SCOPE_EMPLOYEE,
        );
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayeReportService::class)->build(
            Carbon::parse('2026-10-31'),
            Carbon::parse('2026-10-01'),
            PayeReportService::SCOPE_COMPANY,
        );
    }
}
