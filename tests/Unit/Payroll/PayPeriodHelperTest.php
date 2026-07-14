<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\PayPeriodHelper;
use Carbon\Carbon;
use Tests\TestCase;

class PayPeriodHelperTest extends TestCase
{
    public function test_count_mondays_inclusive_range(): void
    {
        $start = Carbon::parse('2026-07-01');
        $end = Carbon::parse('2026-07-14');

        $this->assertSame(2, PayPeriodHelper::countMondays($start, $end));
    }

    public function test_count_mondays_when_range_starts_on_monday(): void
    {
        $start = Carbon::parse('2026-07-06');
        $end = Carbon::parse('2026-07-19');

        $this->assertSame(2, PayPeriodHelper::countMondays($start, $end));
    }

    public function test_count_mondays_returns_zero_for_invalid_range(): void
    {
        $start = Carbon::parse('2026-07-10');
        $end = Carbon::parse('2026-07-01');

        $this->assertSame(0, PayPeriodHelper::countMondays($start, $end));
    }

    public function test_periods_per_year_from_frequency_name(): void
    {
        config([
            'payroll.periods_per_year' => [
                'monthly' => 12,
                'biweekly' => 26,
            ],
            'payroll.default_periods_per_year' => 26,
        ]);

        $this->assertSame(12, PayPeriodHelper::periodsPerYear('Monthly'));
        $this->assertSame(26, PayPeriodHelper::periodsPerYear('Biweekly'));
        $this->assertSame(26, PayPeriodHelper::periodsPerYear(null));
    }
}
