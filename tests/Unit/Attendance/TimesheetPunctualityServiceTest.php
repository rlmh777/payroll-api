<?php

namespace Tests\Unit\Attendance;

use App\Enums\TimesheetPunctualityStatus;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\TimesheetPunctualityService;
use App\Modules\Hr\Services\Attendance\TimesheetScheduleCoverageService;
use App\Modules\Hr\Services\Attendance\TimesheetScheduledHoursResolver;
use Carbon\Carbon;
use Tests\TestCase;

class TimesheetPunctualityServiceTest extends TestCase
{
    public function test_it_marks_clock_in_late_and_clock_out_early(): void
    {
        $service = $this->makeService();

        $timesheet = $this->makeTimesheet([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'clockInTime' => '2026-06-24 08:12:00',
            'clockOutTime' => '2026-06-24 16:45:00',
        ]);

        $scheduleSlots = [
            'emp-1|2026-06-24|0' => [
                'start' => '08:00',
                'end' => '17:00',
            ],
        ];

        $computed = $service->computePunctuality($timesheet, $scheduleSlots['emp-1|2026-06-24|0']);

        $this->assertSame(TimesheetPunctualityStatus::Late->value, $computed['clockIn']);
        $this->assertSame(TimesheetPunctualityStatus::Early->value, $computed['clockOut']);
    }

    public function test_null_stored_value_uses_schedule_comparison(): void
    {
        $service = $this->makeService();

        $timesheet = $this->makeTimesheet([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'clockInTime' => '2026-06-24 08:12:00',
            'clockOutTime' => '2026-06-24 17:00:00',
            'clockInPunctuality' => null,
            'clockOutPunctuality' => null,
        ]);

        $resolved = $service->resolveForTimesheet($timesheet, [
            'emp-1|2026-06-24|0' => [
                'start' => '08:00',
                'end' => '17:00',
            ],
        ]);

        $this->assertTrue($resolved['clockInPunctualityAuto']);
        $this->assertTrue($resolved['clockOutPunctualityAuto']);
        $this->assertSame(TimesheetPunctualityStatus::Late->value, $resolved['clockInPunctuality']);
        $this->assertSame(TimesheetPunctualityStatus::OnTime->value, $resolved['clockOutPunctuality']);
    }

    public function test_stored_override_is_returned_instead_of_schedule_comparison(): void
    {
        $service = $this->makeService();

        $timesheet = $this->makeTimesheet([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'clockInTime' => '2026-06-24 08:12:00',
            'clockOutTime' => '2026-06-24 17:00:00',
            'clockInPunctuality' => TimesheetPunctualityStatus::OnTime->value,
            'clockOutPunctuality' => null,
        ]);

        $resolved = $service->resolveForTimesheet($timesheet, [
            'emp-1|2026-06-24|0' => [
                'start' => '08:00',
                'end' => '17:00',
            ],
        ]);

        $this->assertFalse($resolved['clockInPunctualityAuto']);
        $this->assertTrue($resolved['clockOutPunctualityAuto']);
        $this->assertSame(TimesheetPunctualityStatus::OnTime->value, $resolved['clockInPunctuality']);
        $this->assertSame(TimesheetPunctualityStatus::OnTime->value, $resolved['clockOutPunctuality']);
    }

    private function makeService(): TimesheetPunctualityService
    {
        return new TimesheetPunctualityService(
            new TimesheetScheduleCoverageService(),
            $this->createMock(TimesheetScheduledHoursResolver::class),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeTimesheet(array $attributes): Timesheet
    {
        $timesheet = new Timesheet();
        $timesheet->forceFill($attributes);

        foreach (['date', 'clockInTime', 'clockOutTime', 'roundOffClockInTime', 'roundOffClockOutTime'] as $field) {
            if (!isset($attributes[$field])) {
                continue;
            }

            $timesheet->{$field} = Carbon::parse((string) $attributes[$field]);
        }

        return $timesheet;
    }
}
