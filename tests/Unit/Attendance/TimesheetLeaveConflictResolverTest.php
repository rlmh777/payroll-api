<?php

namespace Tests\Unit\Attendance;

use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\TimesheetLeaveConflictResolver;
use App\Modules\Hr\Services\Attendance\TimesheetLeaveConflictService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TimesheetLeaveConflictResolverTest extends TestCase
{
    #[Test]
    public function it_detects_unresolved_leave_conflicts_from_remarks(): void
    {
        $resolver = new TimesheetLeaveConflictResolver(
            new TimesheetLeaveConflictService(),
            $this->createMock(\App\Modules\Hr\Services\Attendance\TimesheetRoundOffService::class),
        );

        $timesheet = new Timesheet([
            'remarks' => TimesheetLeaveConflictService::CONFLICT_MESSAGE,
        ]);

        $this->assertTrue($resolver->hasUnresolvedLeaveConflict($timesheet));
    }

    #[Test]
    public function it_does_not_flag_resolved_timesheets(): void
    {
        $resolver = new TimesheetLeaveConflictResolver(
            new TimesheetLeaveConflictService(),
            $this->createMock(\App\Modules\Hr\Services\Attendance\TimesheetRoundOffService::class),
        );

        $timesheet = new Timesheet([
            'remarks' => TimesheetLeaveConflictResolver::RESOLUTION_PREFIX.' Supervisor approved emergency coverage.',
        ]);

        $this->assertFalse($resolver->hasUnresolvedLeaveConflict($timesheet));
    }
}
