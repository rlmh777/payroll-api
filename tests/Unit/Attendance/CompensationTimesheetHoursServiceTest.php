<?php

namespace Tests\Unit\Attendance;

use App\Enums\CompensationMethod;
use App\Modules\Hr\Services\Attendance\CompensationTimesheetHoursService;
use Tests\TestCase;

class CompensationTimesheetHoursServiceTest extends TestCase
{
    private CompensationTimesheetHoursService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CompensationTimesheetHoursService::class);
    }

    public function test_base_no_ot_without_punches_pays_scheduled_hours(): void
    {
        $result = $this->service->buildNoPunchDay(
            CompensationMethod::BaseNoOt,
            true,
            8.0,
            null,
            false,
        );

        $this->assertSame(8.0, $result['hoursWorked']);
        $this->assertSame(8.0, $result['regularHours']);
        $this->assertSame(0.0, $result['overtimeHours']);
        $this->assertTrue($result['isPaid']);
        $this->assertSame(8.0, $result['paidHours']);
    }

    public function test_unpaid_leave_day_has_zero_paid_hours(): void
    {
        $result = $this->service->buildNoPunchDay(
            CompensationMethod::BaseNoOt,
            false,
            8.0,
            null,
            true,
        );

        $this->assertSame(8.0, $result['unpaidHours']);
        $this->assertFalse($result['isPaid']);
        $this->assertSame(0.0, $result['paidHours']);
    }

    public function test_hourly_without_punches_is_unpaid(): void
    {
        $result = $this->service->buildNoPunchDay(
            CompensationMethod::HourlyOt,
            true,
            8.0,
            null,
            false,
        );

        $this->assertSame(0.0, $result['hoursWorked']);
        $this->assertSame(8.0, $result['unpaidHours']);
        $this->assertFalse($result['isPaid']);
        $this->assertSame(0.0, $result['paidHours']);
    }

    public function test_base_no_ot_with_extra_clocked_hours_has_no_overtime(): void
    {
        $slot = [
            'clockInTime' => '2024-01-02 08:00:00',
            'clockOutTime' => '2024-01-02 18:00:00',
            'clockedHoursWorked' => 10.0,
            'hoursWorked' => 10.0,
        ];

        $result = $this->service->applyToSlot(
            $slot,
            CompensationMethod::BaseNoOt,
            true,
            8.0,
            null,
            false,
        );

        $this->assertSame(10.0, $result['clockedHoursWorked']);
        $this->assertSame(8.0, $result['hoursWorked']);
        $this->assertSame(8.0, $result['regularHours']);
        $this->assertSame(0.0, $result['overtimeHours']);
    }

    public function test_base_ot_defers_regular_and_overtime_to_allocator(): void
    {
        $slot = [
            'clockInTime' => '2024-01-02 08:00:00',
            'clockOutTime' => '2024-01-02 18:00:00',
            'clockedHoursWorked' => 10.0,
            'hoursWorked' => 10.0,
        ];

        $result = $this->service->applyToSlot(
            $slot,
            CompensationMethod::BaseOt,
            true,
            8.0,
            null,
            false,
        );

        $this->assertSame(10.0, $result['hoursWorked']);
        $this->assertSame(0.0, $result['regularHours']);
        $this->assertSame(0.0, $result['overtimeHours']);
        $this->assertSame('REGULAR', $result['workingStatus']);
        $this->assertTrue($result['isPaid']);
        $this->assertSame(10.0, $result['paidHours']);
    }

    public function test_hourly_no_ot_puts_all_clocked_hours_in_regular(): void
    {
        $slot = [
            'clockInTime' => '2024-01-02 08:00:00',
            'clockOutTime' => '2024-01-02 18:00:00',
            'clockedHoursWorked' => 10.0,
            'hoursWorked' => 10.0,
        ];

        $result = $this->service->applyToSlot(
            $slot,
            CompensationMethod::HourlyNoOt,
            true,
            8.0,
            null,
            false,
        );

        $this->assertSame(10.0, $result['regularHours']);
        $this->assertSame(0.0, $result['overtimeHours']);
    }
}
