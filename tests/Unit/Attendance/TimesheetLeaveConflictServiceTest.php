<?php

namespace Tests\Unit\Attendance;

use App\Models\EmployeeLeave;
use App\Services\Attendance\TimesheetLeaveConflictService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TimesheetLeaveConflictServiceTest extends TestCase
{
    private TimesheetLeaveConflictService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TimesheetLeaveConflictService();
    }

    #[Test]
    public function it_detects_work_during_full_day_leave(): void
    {
        $leave = new EmployeeLeave([
            'startDate' => '2026-06-24',
            'endDate' => '2026-06-24',
            'duration' => 'Full Day',
            'fromTime' => '08:00:00',
            'toTime' => '17:00:00',
        ]);

        $conflicts = $this->service->conflictingLeaves(
            [$leave],
            '2026-06-24',
            '2026-06-24 08:00:00',
            '2026-06-24 12:00:00',
        );

        $this->assertCount(1, $conflicts);
    }

    #[Test]
    public function it_skips_scheduled_entries_when_employee_is_on_leave(): void
    {
        $leave = new EmployeeLeave([
            'startDate' => '2026-06-24',
            'endDate' => '2026-06-24',
            'duration' => 'Full Day',
        ]);

        $this->assertTrue($this->service->hasApprovedLeaveOnDate([$leave]));
    }

    #[Test]
    public function it_allows_work_outside_partial_leave_window(): void
    {
        $leave = new EmployeeLeave([
            'startDate' => '2026-06-24',
            'endDate' => '2026-06-24',
            'duration' => 'Custom',
            'fromTime' => '08:00:00',
            'toTime' => '12:00:00',
        ]);

        $conflicts = $this->service->conflictingLeaves(
            [$leave],
            '2026-06-24',
            '2026-06-24 13:00:00',
            '2026-06-24 17:00:00',
        );

        $this->assertCount(0, $conflicts);
    }
}
