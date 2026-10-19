<?php

namespace Tests\Unit\Payroll;

use App\Models\PayrollEarningCode;
use App\Modules\Payroll\Services\PayrollRegistryReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class PayrollRegistryReportServiceTest extends TestCase
{
    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayrollRegistryReportService::class)->build(
            Carbon::parse('2026-10-01'),
            Carbon::parse('2026-10-31'),
            'group',
        );
    }

    public function test_department_scope_requires_department(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayrollRegistryReportService::class)->build(
            Carbon::parse('2026-10-01'),
            Carbon::parse('2026-10-31'),
            PayrollRegistryReportService::SCOPE_DEPARTMENT,
        );
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PayrollRegistryReportService::class)->build(
            Carbon::parse('2026-10-31'),
            Carbon::parse('2026-10-01'),
            PayrollRegistryReportService::SCOPE_COMPANY,
        );
    }

    public function test_earning_buckets_match_the_registry_columns(): void
    {
        $this->assertSame(
            'regular',
            PayrollRegistryReportService::earningBucket(PayrollEarningCode::SOURCE_TIMESHEET_REGULAR, null),
        );
        $this->assertSame(
            'overtime',
            PayrollRegistryReportService::earningBucket(PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME, 1.5),
        );
        $this->assertSame(
            'doubletime',
            PayrollRegistryReportService::earningBucket(PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY, 2),
        );
        $this->assertSame(
            'regular',
            PayrollRegistryReportService::earningBucket(PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY, 1.5),
        );
        $this->assertSame(
            'misc',
            PayrollRegistryReportService::earningBucket(PayrollEarningCode::SOURCE_ALLOWANCE_FALLBACK, null),
        );
        $this->assertSame(
            'misc',
            PayrollRegistryReportService::earningBucket(PayrollEarningCode::SOURCE_VACATION, null),
        );
    }
}
