<?php

namespace Tests\Unit\Attendance;

use App\Modules\Hr\Services\Attendance\ClockTimeRounder;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClockTimeRounderTest extends TestCase
{
    private ClockTimeRounder $rounder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rounder = app(ClockTimeRounder::class);
    }

    #[DataProvider('clockInRoundUpProvider')]
    public function test_round_clock_in_rounds_up_to_interval(string $input, string $expected): void
    {
        $rounded = $this->rounder->roundClockIn(Carbon::parse($input), 30);

        $this->assertSame($expected, $rounded->format('Y-m-d H:i:s'));
    }

    #[DataProvider('clockOutRoundUpProvider')]
    public function test_round_clock_out_rounds_up_to_interval(string $input, string $expected): void
    {
        $rounded = $this->rounder->roundClockOut(Carbon::parse($input), 30);

        $this->assertSame($expected, $rounded->format('Y-m-d H:i:s'));
    }

    public static function clockInRoundUpProvider(): array
    {
        return [
            'early arrival before shift rounds up to shift start' => ['2026-07-07 07:51:00', '2026-07-07 08:00:00'],
            'exact interval boundary stays unchanged' => ['2026-07-07 08:00:00', '2026-07-07 08:00:00'],
            'minutes after boundary round up to next interval' => ['2026-07-07 08:07:00', '2026-07-07 08:30:00'],
            'late in the hour rounds up to next hour' => ['2026-07-07 07:31:00', '2026-07-07 08:00:00'],
        ];
    }

    public static function clockOutRoundUpProvider(): array
    {
        return [
            'minutes before hour end round up' => ['2026-07-07 16:52:00', '2026-07-07 17:00:00'],
            'exact interval boundary stays unchanged' => ['2026-07-07 17:00:00', '2026-07-07 17:00:00'],
            'minutes after boundary round up to next interval' => ['2026-07-07 16:22:00', '2026-07-07 16:30:00'],
        ];
    }
}
