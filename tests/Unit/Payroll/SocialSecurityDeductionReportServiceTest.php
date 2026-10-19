<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\SocialSecurityDeductionReportService;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class SocialSecurityDeductionReportServiceTest extends TestCase
{
    public function test_invalid_scope_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SocialSecurityDeductionReportService::class)->build(
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
            'employee',
        );
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SocialSecurityDeductionReportService::class)->build(
            Carbon::parse('2026-09-30'),
            Carbon::parse('2026-09-01'),
            SocialSecurityDeductionReportService::SCOPE_COMPANY,
        );
    }
}
