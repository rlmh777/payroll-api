<?php

namespace Tests\Unit\Attendance;

use App\Enums\OvernightShiftMode;
use App\Services\Attendance\TimesheetShiftDaySplitter;
use Carbon\Carbon;
use Tests\TestCase;

class TimesheetShiftDaySplitterTest extends TestCase
{
    private TimesheetShiftDaySplitter $splitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->splitter = app(TimesheetShiftDaySplitter::class);
    }

    public function test_it_splits_overnight_shift_at_midnight(): void
    {
        $segments = $this->splitter->split(
            Carbon::parse('2026-06-23 22:00:00'),
            Carbon::parse('2026-06-24 06:00:00'),
        );

        $this->assertCount(2, $segments);
        $this->assertSame('2026-06-23', $segments[0]['date']);
        $this->assertSame('2026-06-24', $segments[1]['date']);
        $this->assertSame(2.0, $segments[0]['hours']);
        $this->assertSame(6.0, $segments[1]['hours']);
    }

    public function test_it_distributes_payable_hours_proportionally_after_lunch(): void
    {
        $segments = $this->splitter->split(
            Carbon::parse('2026-06-23 22:00:00'),
            Carbon::parse('2026-06-24 06:00:00'),
        );

        $payable = $this->splitter->distributePayableSeconds(7 * 3600, $segments);

        $this->assertSame(7.0, round(array_sum($payable) / 3600, 2));
        $this->assertSame(1.75, round($payable[0] / 3600, 2));
        $this->assertSame(5.25, round($payable[1] / 3600, 2));
    }

    public function test_it_keeps_overnight_shift_on_clock_in_day_when_configured(): void
    {
        $segments = $this->splitter->buildSegments(
            Carbon::parse('2026-06-23 22:00:00'),
            Carbon::parse('2026-06-24 06:00:00'),
            OvernightShiftMode::AttributeToClockInDay,
        );

        $this->assertCount(1, $segments);
        $this->assertSame('2026-06-23', $segments[0]['date']);
        $this->assertSame(8.0, $segments[0]['hours']);
    }

    public function test_it_keeps_overnight_shift_on_clock_out_day_when_configured(): void
    {
        $segments = $this->splitter->buildSegments(
            Carbon::parse('2026-06-23 22:00:00'),
            Carbon::parse('2026-06-24 06:00:00'),
            OvernightShiftMode::AttributeToClockOutDay,
        );

        $this->assertCount(1, $segments);
        $this->assertSame('2026-06-24', $segments[0]['date']);
        $this->assertSame(8.0, $segments[0]['hours']);
    }
}
