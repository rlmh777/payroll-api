<?php

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Services\TimesheetDepartmentCostAllocator;
use Tests\TestCase;

class TimesheetDepartmentCostAllocatorTest extends TestCase
{
    public function test_splits_amount_and_hours_by_department_weight(): void
    {
        $slices = (new TimesheetDepartmentCostAllocator)->allocate(100.00, 10.0, [
            1 => 6,
            2 => 4,
        ], 9);

        $this->assertSame([
            ['departmentId' => 1, 'hours' => 6.0, 'amount' => 60.0],
            ['departmentId' => 2, 'hours' => 4.0, 'amount' => 40.0],
        ], $slices);
    }

    public function test_last_department_absorbs_rounding_remainder(): void
    {
        $slices = (new TimesheetDepartmentCostAllocator)->allocate(10.00, 3.0, [
            1 => 1,
            2 => 1,
            3 => 1,
        ], null);

        $this->assertCount(3, $slices);
        $this->assertEquals(10.0, round(array_sum(array_column($slices, 'amount')), 2));
        $this->assertEquals(3.0, round(array_sum(array_column($slices, 'hours')), 2));
        $this->assertSame(3, $slices[2]['departmentId']);
    }

    public function test_falls_back_to_one_department_when_there_are_no_hours(): void
    {
        $slices = (new TimesheetDepartmentCostAllocator)->allocate(25.50, null, [], 7);

        $this->assertSame([
            ['departmentId' => 7, 'hours' => null, 'amount' => 25.5],
        ], $slices);
    }
}
