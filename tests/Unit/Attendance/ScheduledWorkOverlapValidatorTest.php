<?php

namespace Tests\Unit\Attendance;

use App\Modules\Hr\Services\Attendance\ScheduledWorkOverlapValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ScheduledWorkOverlapValidatorTest extends TestCase
{
    private ScheduledWorkOverlapValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ScheduledWorkOverlapValidator();
    }

    #[Test]
    public function it_detects_overlapping_shift_times_on_shared_days(): void
    {
        $this->assertTrue($this->validator->schedulesOverlap(
            '2026-06-24',
            '2026-06-24',
            '09:00',
            '17:00',
            '2026-06-24',
            '2026-06-24',
            '16:00',
            '20:00',
        ));
    }

    #[Test]
    public function it_allows_adjacent_non_overlapping_shifts(): void
    {
        $this->assertFalse($this->validator->schedulesOverlap(
            '2026-06-24',
            '2026-06-24',
            '09:00',
            '17:00',
            '2026-06-24',
            '2026-06-24',
            '17:00',
            '21:00',
        ));
    }

    #[Test]
    public function it_allows_shifts_on_non_overlapping_dates(): void
    {
        $this->assertFalse($this->validator->schedulesOverlap(
            '2026-06-24',
            '2026-06-24',
            '09:00',
            '17:00',
            '2026-06-25',
            '2026-06-25',
            '09:00',
            '17:00',
        ));
    }
}
