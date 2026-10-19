<?php

namespace Tests\Unit\Attendance;

use App\Enums\CompensationMethod;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\HolidayTimesheetClassifier;
use Tests\TestCase;

class HolidayTimesheetClassifierTest extends TestCase
{
    public function test_hourly_employees_receive_holiday_premium(): void
    {
        $this->assertTrue(HolidayTimesheetClassifier::receivesHolidayPremium(CompensationMethod::HourlyOt->value));
        $this->assertTrue(HolidayTimesheetClassifier::receivesHolidayPremium(CompensationMethod::HourlyNoOt->value));
        $this->assertFalse(HolidayTimesheetClassifier::receivesHolidayPremium(CompensationMethod::BaseNoOt->value));
        $this->assertFalse(HolidayTimesheetClassifier::receivesHolidayPremium(CompensationMethod::BaseOt->value));
    }

    public function test_double_time_is_only_a_two_times_rate(): void
    {
        $this->assertTrue(HolidayTimesheetClassifier::isDoubleTime(2.0));
        $this->assertFalse(HolidayTimesheetClassifier::isDoubleTime(1.5));
        $this->assertFalse(HolidayTimesheetClassifier::isDoubleTime(null));
    }

    public function test_base_salary_stores_unmultiplied_holiday_hours(): void
    {
        $this->assertSame(8.0, HolidayTimesheetClassifier::storedHolidayHours(
            8.0,
            2.0,
            CompensationMethod::BaseNoOt->value,
        ));
        $this->assertSame(16.0, HolidayTimesheetClassifier::storedHolidayHours(
            8.0,
            2.0,
            CompensationMethod::HourlyOt->value,
        ));
    }

    public function test_salary_holiday_pay_multiplier_is_one(): void
    {
        $this->assertSame(1.0, HolidayTimesheetClassifier::payMultiplier(
            CompensationMethod::BaseNoOt->value,
            2.0,
        ));
        $this->assertSame(2.0, HolidayTimesheetClassifier::payMultiplier(
            CompensationMethod::HourlyOt->value,
            2.0,
        ));
    }

    public function test_clocked_hours_undo_stored_double_time_hours(): void
    {
        $timesheet = new Timesheet([
            'payType' => CompensationMethod::HourlyOt->value,
            'hoursWorked' => 0,
            'regularHours' => 0,
            'overtimeHours' => 0,
            'holidayHours' => 16,
        ]);

        $this->assertSame(8.0, HolidayTimesheetClassifier::clockedHours($timesheet, 2.0));
    }
}
