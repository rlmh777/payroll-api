<?php

namespace Tests\Unit\Payroll;

use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Modules\Payroll\Services\PayrollMissingEmployeesService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PayrollMissingEmployeesServiceTest extends TestCase
{
    #[Test]
    public function it_treats_unpaid_leave_type_as_unpaid(): void
    {
        $leave = new EmployeeLeave(['multiplier' => 1]);
        $leave->setRelation('leaveType', new LeaveType(['isPaid' => false]));

        $this->assertTrue(PayrollMissingEmployeesService::leaveIsUnpaid($leave));
    }

    #[Test]
    public function it_treats_zero_multiplier_leave_as_unpaid(): void
    {
        $leave = new EmployeeLeave(['multiplier' => 0]);
        $leave->setRelation('leaveType', new LeaveType(['isPaid' => true]));

        $this->assertTrue(PayrollMissingEmployeesService::leaveIsUnpaid($leave));
    }

    #[Test]
    public function it_treats_paid_leave_type_with_positive_multiplier_as_paid(): void
    {
        $leave = new EmployeeLeave(['multiplier' => 1]);
        $leave->setRelation('leaveType', new LeaveType(['isPaid' => true]));

        $this->assertFalse(PayrollMissingEmployeesService::leaveIsUnpaid($leave));
    }
}
