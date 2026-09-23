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

    public function test_keep_current_and_one_ahead_drops_later_future_periods(): void
    {
        $kept = PayPeriodHelper::keepCurrentAndOneAhead([
            ['id' => 'past', 'start_date' => '2026-07-16'],
            ['id' => 'current', 'start_date' => '2026-08-16'],
            ['id' => 'next', 'start_date' => '2026-09-01'],
            ['id' => 'later', 'start_date' => '2026-09-16'],
        ], '2026-08-26');

        $this->assertSame(['past', 'current', 'next'], array_column($kept, 'id'));
    }

    public function test_timesheet_end_stops_at_pay_date_when_pay_date_is_inside_the_period(): void
    {
        $through = PayPeriodHelper::timesheetEndDate([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'pay_date' => '2026-06-25',
        ]);

        $this->assertSame('2026-06-25', $through?->toDateString());
    }

    public function test_timesheet_end_uses_period_end_when_pay_date_is_on_or_after_end(): void
    {
        $onEnd = PayPeriodHelper::timesheetEndDate([
            'start_date' => '2026-05-16',
            'end_date' => '2026-05-31',
            'pay_date' => '2026-05-31',
        ]);
        $afterEnd = PayPeriodHelper::timesheetEndDate([
            'start_date' => '2026-05-16',
            'end_date' => '2026-05-31',
            'pay_date' => '2026-06-05',
        ]);

        $this->assertSame('2026-05-31', $onEnd?->toDateString());
        $this->assertSame('2026-05-31', $afterEnd?->toDateString());
    }
}
