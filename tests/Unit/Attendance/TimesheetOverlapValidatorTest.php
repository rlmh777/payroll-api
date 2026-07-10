<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\TimesheetOverlapValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TimesheetOverlapValidatorTest extends TestCase
{
    private TimesheetOverlapValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new TimesheetOverlapValidator();
    }

    #[Test]
    public function it_detects_overlapping_timesheet_slots(): void
    {
        $issues = $this->validator->overlappingSlotIssues([
            [
                'roundOffClockInTime' => '2026-06-24 08:00:00',
                'roundOffClockOutTime' => '2026-06-24 12:00:00',
            ],
            [
                'roundOffClockInTime' => '2026-06-24 11:00:00',
                'roundOffClockOutTime' => '2026-06-24 15:00:00',
            ],
        ]);

        $this->assertNotEmpty($issues);
    }

    #[Test]
    public function it_allows_adjacent_non_overlapping_timesheet_slots(): void
    {
        $issues = $this->validator->overlappingSlotIssues([
            [
                'roundOffClockInTime' => '2026-06-24 08:00:00',
                'roundOffClockOutTime' => '2026-06-24 12:00:00',
            ],
            [
                'roundOffClockInTime' => '2026-06-24 12:00:00',
                'roundOffClockOutTime' => '2026-06-24 16:00:00',
            ],
        ]);

        $this->assertSame([], $issues);
    }
}
