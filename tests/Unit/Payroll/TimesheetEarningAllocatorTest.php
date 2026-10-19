<?php

namespace Tests\Unit\Payroll;

use App\Models\PayrollEarningCode;
use App\Models\Timesheet;
use App\Modules\Payroll\Services\TimesheetEarningAllocator;
use Tests\TestCase;

class TimesheetEarningAllocatorTest extends TestCase
{
    public function test_hourly_double_time_holiday_uses_the_double_time_earning_code(): void
    {
        $codes = $this->codes();
        $timesheet = new Timesheet([
            'payType' => 'HOURLY_OT',
            'isPaid' => true,
            'regularHours' => 0,
            'overtimeHours' => 0,
            'holidayHours' => 16,
        ]);

        $lines = $this->allocator()->allocate($timesheet, $codes, 2.0, 10, 0, 8, 1.5);

        $this->assertSame([
            'earning:3' => ['key' => 'earning:3', 'hours' => 8.0, 'amount' => 160.0],
        ], $this->keyed($lines));
    }

    public function test_hourly_time_and_a_half_holiday_uses_its_own_earning_code(): void
    {
        $timesheet = new Timesheet([
            'payType' => 'HOURLY_OT',
            'isPaid' => true,
            'regularHours' => 0,
            'overtimeHours' => 0,
            'holidayHours' => 12,
        ]);

        $lines = $this->allocator()->allocate($timesheet, $this->codes(), 1.5, 10, 0, 8, 1.5);

        $this->assertSame([
            'earning:2' => ['key' => 'earning:2', 'hours' => 8.0, 'amount' => 120.0],
        ], $this->keyed($lines));
    }

    public function test_base_salary_holiday_is_daily_rate_regular_pay(): void
    {
        $timesheet = new Timesheet([
            'payType' => 'BASE_NO_OT',
            'isPaid' => true,
            'regularHours' => 0,
            'overtimeHours' => 0,
            'holidayHours' => 8,
        ]);

        $lines = $this->allocator()->allocate($timesheet, $this->codes(), 2.0, 10, 80, 8, 1.5);

        $this->assertSame([
            'earning:1' => ['key' => 'earning:1', 'hours' => 8.0, 'amount' => 80.0],
        ], $this->keyed($lines));
    }

    public function test_daily_rate_holiday_receives_the_premium_earning_code(): void
    {
        $timesheet = new Timesheet([
            'payType' => 'DAILY_RATE',
            'isPaid' => true,
            'regularHours' => 0,
            'overtimeHours' => 0,
            'holidayHours' => 16,
        ]);

        $lines = $this->allocator()->allocate($timesheet, $this->codes(), 2.0, 12, 96, 8, 1.5);

        $this->assertSame([
            'earning:3' => ['key' => 'earning:3', 'hours' => 8.0, 'amount' => 192.0],
        ], $this->keyed($lines));
    }

    public function test_overtime_stays_on_the_overtime_code_for_hourly_pay(): void
    {
        $timesheet = new Timesheet([
            'payType' => 'HOURLY_OT',
            'isPaid' => true,
            'regularHours' => 8,
            'overtimeHours' => 2,
            'holidayHours' => 0,
        ]);

        $lines = $this->allocator()->allocate($timesheet, $this->codes(), null, 10, 0, 8, 1.5);

        $this->assertSame([
            'earning:1' => ['key' => 'earning:1', 'hours' => 8.0, 'amount' => 80.0],
            'earning:4' => ['key' => 'earning:4', 'hours' => 2.0, 'amount' => 30.0],
        ], $this->keyed($lines));
    }

    private function allocator(): TimesheetEarningAllocator
    {
        return new TimesheetEarningAllocator();
    }

    /**
     * @return \Illuminate\Support\Collection<int, PayrollEarningCode>
     */
    private function codes()
    {
        $regular = new PayrollEarningCode([
            'code' => 'REGULAR',
            'name' => 'Regular Pay',
            'source' => PayrollEarningCode::SOURCE_TIMESHEET_REGULAR,
            'sort_order' => 1,
        ]);
        $regular->id = 1;

        $holidayHalf = new PayrollEarningCode([
            'code' => 'HOLIDAY',
            'name' => 'Holiday Pay (1.5 time)',
            'source' => PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
            'pay_multiplier' => 1.5,
            'sort_order' => 3,
        ]);
        $holidayHalf->id = 2;

        $holidayDouble = new PayrollEarningCode([
            'code' => 'HOLIDAY_DT',
            'name' => 'Holiday Pay (double time)',
            'source' => PayrollEarningCode::SOURCE_TIMESHEET_HOLIDAY,
            'pay_multiplier' => 2,
            'sort_order' => 4,
        ]);
        $holidayDouble->id = 3;

        $overtime = new PayrollEarningCode([
            'code' => 'OVERTIME',
            'name' => 'Overtime',
            'source' => PayrollEarningCode::SOURCE_TIMESHEET_OVERTIME,
            'sort_order' => 2,
        ]);
        $overtime->id = 4;

        return collect([$regular, $holidayHalf, $holidayDouble, $overtime]);
    }

    /**
     * @param list<array{key: string, hours: float, amount: float}> $lines
     * @return array<string, array{key: string, hours: float, amount: float}>
     */
    private function keyed(array $lines): array
    {
        $keyed = [];
        foreach ($lines as $line) {
            $keyed[$line['key']] = $line;
        }

        return $keyed;
    }
}
