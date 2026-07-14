<?php

namespace Tests\Unit\Attendance;

use App\Modules\Hr\Services\Attendance\TimesheetPayFields;
use Tests\TestCase;

class TimesheetPayFieldsTest extends TestCase
{
    public function test_manual_paid_toggle_moves_hours_to_paid_bucket(): void
    {
        $result = TimesheetPayFields::fromStoredHours(true, 8.0, 2.0, 0.0, 10.0);

        $this->assertTrue($result['isPaid']);
        $this->assertSame(10.0, $result['paidHours']);
        $this->assertSame(0.0, $result['unpaidHours']);
    }

    public function test_manual_unpaid_toggle_moves_hours_to_unpaid_bucket(): void
    {
        $result = TimesheetPayFields::fromStoredHours(false, 8.0, 2.0, 0.0, 10.0);

        $this->assertFalse($result['isPaid']);
        $this->assertSame(0.0, $result['paidHours']);
        $this->assertSame(10.0, $result['unpaidHours']);
    }

    public function test_manual_paid_toggle_uses_hours_worked_when_pay_components_are_zero(): void
    {
        $result = TimesheetPayFields::fromStoredHours(true, 0.0, 0.0, 0.0, 6.5);

        $this->assertTrue($result['isPaid']);
        $this->assertSame(6.5, $result['paidHours']);
        $this->assertSame(0.0, $result['unpaidHours']);
    }
}
