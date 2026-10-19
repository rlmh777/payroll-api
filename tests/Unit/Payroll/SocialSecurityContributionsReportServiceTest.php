<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\SocialSecurityContributionsReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class SocialSecurityContributionsReportServiceTest extends TestCase
{
    public function test_september_2026_uses_calendar_weeks_36_through_39(): void
    {
        $weeks = SocialSecurityContributionsReportService::calendarWeeks(
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
        );

        $this->assertSame([36, 37, 38, 39], array_column($weeks, 'weekNumber'));
        $this->assertSame('2026-09-03', $weeks[0]['thursday']);
        $this->assertSame(2026, $weeks[0]['weekYear']);
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SocialSecurityContributionsReportService::class)->build(
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
            'employee',
        );
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SocialSecurityContributionsReportService::class)->build(
            Carbon::parse('2026-09-30'),
            Carbon::parse('2026-09-01'),
            SocialSecurityContributionsReportService::SCOPE_COMPANY,
        );
    }
}
