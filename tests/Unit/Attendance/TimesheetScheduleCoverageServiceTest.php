<?php

namespace Tests\Unit\Attendance;

use App\Enums\ScheduleComparisonSource;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\TimesheetScheduleCoverageService;
use App\Modules\Hr\Services\Attendance\TimesheetScheduledHoursResolver;
use Carbon\Carbon;
use Tests\TestCase;

class TimesheetScheduleCoverageServiceTest extends TestCase
{
    public function test_rounded_mode_ignores_raw_clock_variance_when_rounded_times_match_schedule(): void
    {
        $service = $this->makeService();

        $timesheet = $this->makeTimesheet([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'clockInTime' => '2026-06-24 08:07:00',
            'clockOutTime' => '2026-06-24 17:12:00',
            'roundOffClockInTime' => '2026-06-24 08:00:00',
            'roundOffClockOutTime' => '2026-06-24 17:00:00',
        ]);

        $scheduleSlots = [
            'emp-1|2026-06-24|0' => [
                'start' => '08:00',
                'end' => '17:00',
            ],
        ];

        $this->assertFalse($service->isOutsideSchedule(
            $timesheet,
            $scheduleSlots,
            ScheduleComparisonSource::Rounded,
        ));
    }

    public function test_rounded_mode_flags_rows_when_rounded_times_differ_from_scheduled_slot(): void
    {
        $service = $this->makeService();

        $timesheet = $this->makeTimesheet([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'clockInTime' => '2026-06-24 08:07:00',
            'clockOutTime' => '2026-06-24 17:00:00',
            'roundOffClockInTime' => '2026-06-24 08:30:00',
            'roundOffClockOutTime' => '2026-06-24 17:00:00',
        ]);

        $scheduleSlots = [
            'emp-1|2026-06-24|0' => [
                'start' => '08:00',
                'end' => '17:00',
            ],
        ];

        $this->assertTrue($service->isOutsideSchedule(
            $timesheet,
            $scheduleSlots,
            ScheduleComparisonSource::Rounded,
        ));
    }

    public function test_clock_mode_uses_raw_clock_times(): void
    {
        $service = $this->makeService();

        $timesheet = $this->makeTimesheet([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'clockInTime' => '2026-06-24 08:07:00',
            'clockOutTime' => '2026-06-24 17:00:00',
            'roundOffClockInTime' => '2026-06-24 08:00:00',
            'roundOffClockOutTime' => '2026-06-24 17:00:00',
        ]);

        $scheduleSlots = [
            'emp-1|2026-06-24|0' => [
                'start' => '08:00',
                'end' => '17:00',
            ],
        ];

        $this->assertTrue($service->isOutsideSchedule(
            $timesheet,
            $scheduleSlots,
            ScheduleComparisonSource::Clock,
        ));
    }

    public function test_clock_mode_does_not_flag_rows_when_raw_clock_times_match_scheduled_slot(): void
    {
        $service = $this->makeService();

        $timesheet = $this->makeTimesheet([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'clockInTime' => '2026-06-24 08:00:00',
            'clockOutTime' => '2026-06-24 17:00:00',
        ]);

        $scheduleSlots = [
            'emp-1|2026-06-24|0' => [
                'start' => '08:00',
                'end' => '17:00',
            ],
        ];

        $this->assertFalse($service->isOutsideSchedule(
            $timesheet,
            $scheduleSlots,
            ScheduleComparisonSource::Clock,
        ));
    }

    public function test_it_flags_rows_without_a_matching_schedule(): void
    {
        $service = $this->makeService();

        $timesheet = $this->makeTimesheet([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
            'roundOffClockInTime' => '2026-06-24 08:00:00',
            'roundOffClockOutTime' => '2026-06-24 17:00:00',
        ]);

        $this->assertTrue($service->isOutsideSchedule(
            $timesheet,
            [],
            ScheduleComparisonSource::Rounded,
        ));
    }

    public function test_it_ignores_rows_without_punch_times(): void
    {
        $service = $this->makeService();

        $timesheet = $this->makeTimesheet([
            'employeeId' => 'emp-1',
            'date' => '2026-06-24',
            'slotIndex' => 0,
        ]);

        $this->assertFalse($service->isOutsideSchedule(
            $timesheet,
            [],
            ScheduleComparisonSource::Rounded,
        ));
    }

    private function makeService(): TimesheetScheduleCoverageService
    {
        return new TimesheetScheduleCoverageService(
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
