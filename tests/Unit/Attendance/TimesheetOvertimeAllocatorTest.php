<?php

namespace Tests\Unit\Attendance;

use App\Enums\CompensationMethod;
use App\Models\Department;
use App\Models\Timesheet;
use App\Services\Attendance\PublicHolidayPayResolver;
use App\Services\Attendance\TimesheetLunchBreakResolver;
use App\Services\Attendance\TimesheetOvertimeAllocator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TimesheetOvertimeAllocatorTest extends TestCase
{
    public function test_base_ot_redistributes_overtime_across_same_day_slots(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'totalDailyHoursBeforeOvertime' => 8,
            'totalWeeklyHoursBeforeOvertime' => 45,
            'includeLunchHour' => false,
        ]);

        $slot0 = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::BaseOt->value,
            'hoursWorked' => 6.0,
            'isPaid' => true,
        ]);
        $slot1 = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 1,
            'departmentId' => 1,
            'payType' => CompensationMethod::BaseOt->value,
            'hoursWorked' => 6.0,
            'isPaid' => true,
        ]);

        $this->invokeDailyAllocation($allocator, collect([$slot0, $slot1]), $department);

        $this->assertSame(6.0, (float) $slot0->regularHours);
        $this->assertSame(0.0, (float) $slot0->overtimeHours);
        $this->assertSame(2.0, (float) $slot1->regularHours);
        $this->assertSame(4.0, (float) $slot1->overtimeHours);
        $this->assertSame(8.0, (float) $slot0->regularHours + (float) $slot1->regularHours);
        $this->assertSame(4.0, (float) $slot0->overtimeHours + (float) $slot1->overtimeHours);
        $this->assertSame(12.0, (float) $slot0->paidHours + (float) $slot1->paidHours);
    }

    public function test_hourly_daily_mode_uses_department_daily_threshold(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'DAILY',
            'totalDailyHoursBeforeOvertime' => 9,
            'totalWeeklyHoursBeforeOvertime' => 45,
            'includeLunchHour' => false,
        ]);

        $timesheet = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 10.0,
            'isPaid' => true,
        ]);

        $this->invokeDailyAllocation($allocator, collect([$timesheet]), $department);

        $this->assertSame(9.0, (float) $timesheet->regularHours);
        $this->assertSame(1.0, (float) $timesheet->overtimeHours);
        $this->assertSame(10.0, (float) $timesheet->paidHours);
        $this->assertSame('OVERTIME', $timesheet->workingStatus);
    }

    public function test_hourly_weekly_mode_uses_weekly_threshold_only(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'WEEKLY',
            'totalDailyHoursBeforeOvertime' => 8,
            'totalWeeklyHoursBeforeOvertime' => 10,
            'includeLunchHour' => false,
        ]);

        $dayOne = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-23',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 8.0,
            'isPaid' => true,
        ]);
        $dayTwo = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 8.0,
            'isPaid' => true,
        ]);

        $this->invokeWeeklyOnlyAllocation(
            $allocator,
            collect([$dayOne, $dayTwo]),
            $department,
        );

        $this->assertSame(8.0, (float) $dayOne->regularHours);
        $this->assertSame(0.0, (float) $dayOne->overtimeHours);
        $this->assertSame(2.0, (float) $dayTwo->regularHours);
        $this->assertSame(6.0, (float) $dayTwo->overtimeHours);
    }

    public function test_holiday_slot_receives_holiday_hours_and_syncs_paid_fields(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(2.0);
        $holidayResolver->method('isHoliday')->willReturn(true);

        $allocator = $this->makeAllocator($holidayResolver);

        $timesheet = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 8.0,
            'isPaid' => true,
        ]);

        $this->invokeDailyAllocation($allocator, collect([$timesheet]), null);

        $this->assertSame(0.0, (float) $timesheet->regularHours);
        $this->assertSame(0.0, (float) $timesheet->overtimeHours);
        $this->assertSame(16.0, (float) $timesheet->holidayHours);
        $this->assertSame(16.0, (float) $timesheet->paidHours);
        $this->assertSame('HOLIDAY', $timesheet->workingStatus);
    }

    public function test_hourly_daily_mode_subtracts_lunch_before_overtime(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'DAILY',
            'totalDailyHoursBeforeOvertime' => 9,
            'totalWeeklyHoursBeforeOvertime' => 45,
            'includeLunchHour' => false,
        ]);

        $timesheet = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'roundOffClockInTime' => '2026-06-24 08:00:00',
            'roundOffClockOutTime' => '2026-06-24 17:00:00',
            'clockedHoursWorked' => 9.0,
            'includeLunchHour' => true,
            'lunchHourHours' => 1.0,
            'hoursWorked' => 8.0,
            'isPaid' => true,
        ]);

        $this->invokeDailyAllocation($allocator, collect([$timesheet]), $department);

        $this->assertSame(8.0, (float) $timesheet->hoursWorked);
        $this->assertSame(8.0, (float) $timesheet->regularHours);
        $this->assertSame(0.0, (float) $timesheet->overtimeHours);
    }

    public function test_hourly_daily_mode_applies_overtime_after_effective_department_threshold(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'DAILY',
            'totalDailyHoursBeforeOvertime' => 9,
            'totalWeeklyHoursBeforeOvertime' => 45,
            'includeLunchHour' => false,
        ]);

        $timesheet = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'roundOffClockInTime' => '2026-06-24 08:00:00',
            'roundOffClockOutTime' => '2026-06-24 17:30:00',
            'clockedHoursWorked' => 9.5,
            'includeLunchHour' => true,
            'lunchHourHours' => 1.0,
            'hoursWorked' => 8.5,
            'isPaid' => true,
        ]);

        $this->invokeDailyAllocation($allocator, collect([$timesheet]), $department);

        $this->assertSame(8.0, (float) $timesheet->regularHours);
        $this->assertSame(0.5, (float) $timesheet->overtimeHours);
    }

    public function test_hourly_weekly_mode_subtracts_lunch_for_each_worked_day(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'WEEKLY',
            'totalDailyHoursBeforeOvertime' => 9,
            'totalWeeklyHoursBeforeOvertime' => 45,
        ]);

        $timesheets = collect(range(0, 4))->map(function (int $offset) {
            $date = Carbon::parse('2026-06-23')->addDays($offset)->toDateString();

            return $this->makeTimesheetWithoutDbSave([
                'employeeId' => 'emp-1',
                'date' => $date,
                'slotIndex' => 0,
                'departmentId' => 1,
                'payType' => CompensationMethod::HourlyOt->value,
                'roundOffClockInTime' => "{$date} 08:00:00",
                'roundOffClockOutTime' => "{$date} 17:00:00",
                'clockedHoursWorked' => 9.0,
                'includeLunchHour' => true,
                'lunchHourHours' => 1.0,
                'hoursWorked' => 9.0,
                'isPaid' => true,
            ]);
        });

        $this->invokeWeeklyOnlyAllocation(
            $allocator,
            $timesheets,
            $department,
        );

        $this->assertSame(40.0, round((float) $timesheets->sum('regularHours'), 2));
        $this->assertSame(0.0, round((float) $timesheets->sum('overtimeHours'), 2));
        $this->assertSame(8.0, (float) $timesheets->first()->hoursWorked);
    }

    public function test_hourly_no_ot_pay_type_clears_overtime_hours(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $timesheet = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyNoOt->value,
            'hoursWorked' => 10.0,
            'regularHours' => 8.0,
            'overtimeHours' => 2.0,
            'isPaid' => true,
        ]);

        $allocator->redistributeCollection(collect([$timesheet]));

        $this->assertSame(10.0, (float) $timesheet->regularHours);
        $this->assertSame(0.0, (float) $timesheet->overtimeHours);
        $this->assertSame('REGULAR', $timesheet->workingStatus);
    }

    public function test_base_ot_daily_mode_uses_department_threshold_not_scheduled_hours(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'DAILY',
            'totalDailyHoursBeforeOvertime' => 10,
            'totalWeeklyHoursBeforeOvertime' => 45,
            'includeLunchHour' => false,
        ]);

        $timesheet = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::BaseOt->value,
            'hoursWorked' => 10.0,
            'isPaid' => true,
        ]);

        $this->invokeDailyAllocation($allocator, collect([$timesheet]), $department);

        $this->assertSame(10.0, (float) $timesheet->regularHours);
        $this->assertSame(0.0, (float) $timesheet->overtimeHours);
    }

    public function test_overnight_split_daily_mode_applies_threshold_per_calendar_day(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'DAILY',
            'totalDailyHoursBeforeOvertime' => 7,
            'totalWeeklyHoursBeforeOvertime' => 45,
            'includeLunchHour' => false,
        ]);

        $dayOne = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-23',
            'slotIndex' => 1,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 2.0,
            'isPaid' => true,
        ]);
        $dayTwo = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 6.0,
            'isPaid' => true,
        ]);

        $this->invokeDailyAllocation($allocator, collect([$dayOne, $dayTwo]), $department);

        $this->assertSame(2.0, (float) $dayOne->regularHours);
        $this->assertSame(0.0, (float) $dayOne->overtimeHours);
        $this->assertSame(6.0, (float) $dayTwo->regularHours);
        $this->assertSame(0.0, (float) $dayTwo->overtimeHours);
    }

    public function test_overnight_attributed_day_daily_mode_applies_single_day_threshold(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'DAILY',
            'totalDailyHoursBeforeOvertime' => 7,
            'totalWeeklyHoursBeforeOvertime' => 45,
            'includeLunchHour' => false,
        ]);

        $timesheet = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-23',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 8.0,
            'isPaid' => true,
        ]);

        $this->invokeDailyAllocation($allocator, collect([$timesheet]), $department);

        $this->assertSame(7.0, (float) $timesheet->regularHours);
        $this->assertSame(1.0, (float) $timesheet->overtimeHours);
    }

    public function test_daily_and_weekly_mode_applies_both_thresholds(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'DAILY_AND_WEEKLY',
            'totalDailyHoursBeforeOvertime' => 8,
            'totalWeeklyHoursBeforeOvertime' => 10,
            'includeLunchHour' => false,
        ]);

        $dayOne = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-23',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 8.0,
            'isPaid' => true,
        ]);
        $dayTwo = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 8.0,
            'isPaid' => true,
        ]);

        $this->invokeOvertimeAllocation($allocator, collect([$dayOne, $dayTwo]), $department);

        $this->assertSame(8.0, (float) $dayOne->regularHours);
        $this->assertSame(0.0, (float) $dayOne->overtimeHours);
        $this->assertSame(2.0, (float) $dayTwo->regularHours);
        $this->assertSame(6.0, (float) $dayTwo->overtimeHours);
    }

    public function test_daily_allocation_uses_stored_hours_when_round_off_times_are_missing(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'DAILY',
            'totalDailyHoursBeforeOvertime' => 8,
            'totalWeeklyHoursBeforeOvertime' => 45,
            'includeLunchHour' => false,
        ]);

        $timesheet = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 10.0,
            'regularHours' => 8.0,
            'overtimeHours' => 0.0,
            'isPaid' => true,
        ]);

        $this->invokeDailyAllocation($allocator, collect([$timesheet]), $department);

        $this->assertSame(8.0, (float) $timesheet->regularHours);
        $this->assertSame(2.0, (float) $timesheet->overtimeHours);
        $this->assertSame('OVERTIME', $timesheet->workingStatus);
    }

    public function test_increasing_hours_from_zero_overtime_base_allocates_daily_overtime(): void
    {
        $holidayResolver = $this->createMock(PublicHolidayPayResolver::class);
        $holidayResolver->method('payMultiplierForDate')->willReturn(null);
        $holidayResolver->method('isHoliday')->willReturn(false);

        $allocator = $this->makeAllocator($holidayResolver);

        $department = new Department([
            'overtimeThresholdMode' => 'DAILY',
            'totalDailyHoursBeforeOvertime' => 9,
            'totalWeeklyHoursBeforeOvertime' => 45,
            'includeLunchHour' => true,
            'lunchHourHours' => 1,
        ]);

        $timesheet = $this->makeTimesheetWithoutDbSave([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'departmentId' => 1,
            'payType' => CompensationMethod::HourlyOt->value,
            'roundOffClockInTime' => '2026-06-24 08:00:00',
            'roundOffClockOutTime' => '2026-06-24 18:00:00',
            'clockedHoursWorked' => 10.0,
            'includeLunchHour' => true,
            'lunchHourHours' => 1.0,
            'hoursWorked' => 9.0,
            'regularHours' => 8.0,
            'overtimeHours' => 0.0,
            'isPaid' => true,
        ]);

        $this->invokeDailyAllocation($allocator, collect([$timesheet]), $department);

        $this->assertSame(8.0, (float) $timesheet->regularHours);
        $this->assertSame(1.0, (float) $timesheet->overtimeHours);
        $this->assertSame(9.0, (float) $timesheet->hoursWorked);
    }

    private function makeAllocator(
        PublicHolidayPayResolver $holidayResolver,
    ): TimesheetOvertimeAllocator {
        return new TimesheetOvertimeAllocator(
            $holidayResolver,
            new TimesheetLunchBreakResolver(),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeTimesheetWithoutDbSave(array $attributes): Timesheet
    {
        /** @var Timesheet $timesheet */
        $timesheet = \Mockery::mock(Timesheet::class)->makePartial();
        $timesheet->shouldReceive('save')->andReturn(true);
        $timesheet->forceFill(array_merge([
            'includeLunchHour' => false,
            'lunchHourHours' => 0,
        ], $attributes));
        $timesheet->date = Carbon::parse((string) $attributes['date']);

        return $timesheet;
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function invokeDailyAllocation(
        TimesheetOvertimeAllocator $allocator,
        Collection $timesheets,
        ?Department $department,
    ): void {
        $method = new \ReflectionMethod($allocator, 'applyDailyAllocation');
        $method->setAccessible(true);
        $method->invoke($allocator, $timesheets, $department);
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function invokeOvertimeAllocation(
        TimesheetOvertimeAllocator $allocator,
        Collection $timesheets,
        ?Department $department,
    ): void {
        $method = new \ReflectionMethod($allocator, 'applyOvertimeAllocation');
        $method->setAccessible(true);
        $method->invoke($allocator, $timesheets, $department);
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    private function invokeWeeklyOnlyAllocation(
        TimesheetOvertimeAllocator $allocator,
        Collection $timesheets,
        ?Department $department,
    ): void {
        $method = new \ReflectionMethod($allocator, 'applyWeeklyOnlyAllocation');
        $method->setAccessible(true);
        $method->invoke($allocator, $timesheets, $department);
    }
}
