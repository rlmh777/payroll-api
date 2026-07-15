<?php

namespace Tests\Unit\Payroll;

use App\Models\Timesheet;
use App\Modules\Payroll\Services\PayrollRunTimesheetPaidMarker;
use Illuminate\Support\Str;
use Tests\TestCase;

class PayrollRunTimesheetPaidMarkerTest extends TestCase
{
    public function test_mark_collection_paid_sets_is_paid_and_paid_hours(): void
    {
        $timesheet = new Timesheet([
            'id' => (string) Str::uuid(),
            'employeeId' => (string) Str::uuid(),
            'date' => '2026-07-01',
            'regularHours' => 8,
            'overtimeHours' => 2,
            'holidayHours' => 0,
            'hoursWorked' => 10,
            'isPaid' => false,
            'paidHours' => 0,
            'unpaidHours' => 10,
            'workingStatus' => 'REGULAR',
        ]);
        $timesheet->syncOriginal();

        $marker = $this->app->make(PayrollRunTimesheetPaidMarker::class);
        $updated = $marker->markCollectionPaid(collect([$timesheet]));

        $this->assertSame(1, $updated);
        $this->assertTrue((bool) $timesheet->isPaid);
        $this->assertSame(10.0, (float) $timesheet->paidHours);
        $this->assertSame(0.0, (float) $timesheet->unpaidHours);
    }

    public function test_unpaid_working_status_is_skipped(): void
    {
        $timesheet = new Timesheet([
            'id' => (string) Str::uuid(),
            'employeeId' => (string) Str::uuid(),
            'date' => '2026-07-01',
            'regularHours' => 0,
            'overtimeHours' => 0,
            'holidayHours' => 0,
            'hoursWorked' => 8,
            'isPaid' => false,
            'paidHours' => 0,
            'unpaidHours' => 8,
            'workingStatus' => 'UNPAID',
        ]);
        $timesheet->syncOriginal();

        $marker = $this->app->make(PayrollRunTimesheetPaidMarker::class);
        $updated = $marker->markCollectionPaid(collect([$timesheet]));

        $this->assertSame(0, $updated);
        $this->assertFalse((bool) $timesheet->isPaid);
    }
}
