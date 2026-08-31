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

    #[DataProvider('clockInNearestProvider')]
    public function test_round_clock_in_rounds_to_nearest_interval(string $input, string $expected): void
    {
        $rounded = $this->rounder->roundClockIn(Carbon::parse($input), 30);

        $this->assertSame($expected, $rounded->format('Y-m-d H:i:s'));
    }

    #[DataProvider('clockOutNearestProvider')]
    public function test_round_clock_out_rounds_to_nearest_interval(string $input, string $expected): void
    {
        $rounded = $this->rounder->roundClockOut(Carbon::parse($input), 30);

        $this->assertSame($expected, $rounded->format('Y-m-d H:i:s'));
    }

    public static function clockInNearestProvider(): array
    {
        return [
            'closer to next hour rounds up' => ['2026-07-07 07:51:00', '2026-07-07 08:00:00'],
            'exact interval boundary stays unchanged' => ['2026-07-07 08:00:00', '2026-07-07 08:00:00'],
            'seven minutes past hour rounds down' => ['2026-07-07 08:07:00', '2026-07-07 08:00:00'],
            'one minute past half hour rounds down' => ['2026-07-07 07:31:00', '2026-07-07 07:30:00'],
            '3:37 rounds to closest half hour' => ['2026-08-26 15:37:00', '2026-08-26 15:30:00'],
            'exact midpoint rounds forward' => ['2026-07-07 08:15:00', '2026-07-07 08:30:00'],
        ];
    }

    public static function clockOutNearestProvider(): array
    {
        return [
            'closer to next hour rounds up' => ['2026-07-07 16:52:00', '2026-07-07 17:00:00'],
            'exact interval boundary stays unchanged' => ['2026-07-07 17:00:00', '2026-07-07 17:00:00'],
            'closer to next half hour rounds up' => ['2026-07-07 16:22:00', '2026-07-07 16:30:00'],
            '3:37 rounds to closest half hour' => ['2026-08-26 15:37:00', '2026-08-26 15:30:00'],
            'exact midpoint rounds forward' => ['2026-07-07 15:15:00', '2026-07-07 15:30:00'],
        ];
    }
}
