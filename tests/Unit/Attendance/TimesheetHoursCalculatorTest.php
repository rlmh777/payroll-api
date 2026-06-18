<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\TimesheetHoursCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TimesheetHoursCalculatorTest extends TestCase
{
    private TimesheetHoursCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new TimesheetHoursCalculator();
    }

    #[Test]
    public function it_calculates_clock_times_regular_hours_and_overtime_from_a_typed_pair(): void
    {
        $result = $this->calculator->calculate(collect([
            $this->punch('2026-06-08 08:00:00', 'IN', 'FRONT-DOOR'),
            $this->punch('2026-06-08 17:30:00', 'OUT', 'FRONT-DOOR'),
        ]), 8);

        $this->assertSame('2026-06-08 08:00:00', $result['clockInTime']);
        $this->assertSame('FRONT-DOOR', $result['clockInDeviceId']);
        $this->assertSame('2026-06-08 17:30:00', $result['clockOutTime']);
        $this->assertSame('FRONT-DOOR', $result['clockOutDeviceId']);
        $this->assertSame(9.5, $result['hoursWorked']);
        $this->assertSame(8.0, $result['regularHours']);
        $this->assertSame(1.5, $result['overtimeHours']);
        $this->assertSame([], $result['issues']);
    }

    #[Test]
    public function it_sums_split_shifts_without_counting_the_break(): void
    {
        $result = $this->calculator->calculate(collect([
            $this->punch('2026-06-08 08:00:00', 'IN'),
            $this->punch('2026-06-08 12:00:00', 'OUT'),
            $this->punch('2026-06-08 13:00:00', 'IN'),
            $this->punch('2026-06-08 17:30:00', 'OUT'),
        ]), 8);

        $this->assertSame('2026-06-08 08:00:00', $result['clockInTime']);
        $this->assertSame('2026-06-08 17:30:00', $result['clockOutTime']);
        $this->assertSame(8.5, $result['hoursWorked']);
        $this->assertSame(8.0, $result['regularHours']);
        $this->assertSame(0.5, $result['overtimeHours']);
    }

    #[Test]
    public function it_pairs_untyped_punches_in_chronological_order(): void
    {
        $result = $this->calculator->calculate(collect([
            $this->punch('2026-06-08 16:15:00'),
            $this->punch('2026-06-08 08:15:00'),
        ]), 8);

        $this->assertSame('2026-06-08 08:15:00', $result['clockInTime']);
        $this->assertSame('2026-06-08 16:15:00', $result['clockOutTime']);
        $this->assertSame(8.0, $result['hoursWorked']);
        $this->assertSame(8.0, $result['regularHours']);
        $this->assertSame(0.0, $result['overtimeHours']);
    }

    #[Test]
    public function it_infers_untyped_punches_when_the_file_contains_directional_events(): void
    {
        $result = $this->calculator->calculate(collect([
            $this->punch('2026-06-08 08:00:00', 'IN'),
            $this->punch('2026-06-08 17:00:00'),
        ]), 8);

        $this->assertSame(9.0, $result['hoursWorked']);
        $this->assertSame(8.0, $result['regularHours']);
        $this->assertSame(1.0, $result['overtimeHours']);
        $this->assertCount(1, $result['issues']);
        $this->assertStringContainsString('inferred as OUT', $result['issues'][0]);
    }

    #[Test]
    public function it_excludes_unmatched_punches_from_clock_times_and_worked_hours(): void
    {
        $result = $this->calculator->calculate(collect([
            $this->punch('2026-06-08 17:00:00', 'OUT'),
        ]), 8);

        $this->assertNull($result['clockInTime']);
        $this->assertNull($result['clockOutTime']);
        $this->assertSame(0.0, $result['hoursWorked']);
        $this->assertSame(0.0, $result['regularHours']);
        $this->assertSame(0.0, $result['overtimeHours']);
        $this->assertContains('Punch-out record has no matching punch-in.', $result['issues']);
    }

    /**
     * @return array{punchDateTime:Carbon, deviceId:?string, punchType:?string}
     */
    private function punch(string $dateTime, ?string $type = null, ?string $deviceId = null): array
    {
        return [
            'punchDateTime' => Carbon::parse($dateTime),
            'deviceId' => $deviceId,
            'punchType' => $type,
        ];
    }
}
