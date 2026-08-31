<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\TimesheetPunctualityStatus;
use App\Models\Timesheet;
use Carbon\Carbon;

class TimesheetPunctualityService
{
    public function __construct(
        private readonly TimesheetScheduleCoverageService $scheduleCoverage,
        private readonly TimesheetScheduledHoursResolver $scheduledHoursResolver,
    ) {
    }

    /**
     * Stored null = auto from schedule; non-null = manual override.
     *
     * @param array<string, array{start:string,end:string}> $scheduleSlots
     * @return array{
     *   clockInPunctuality:?string,
     *   clockOutPunctuality:?string,
     *   clockInPunctualityAuto:bool,
     *   clockOutPunctualityAuto:bool,
     *   scheduledStartTime:?string,
     *   scheduledEndTime:?string
     * }
     */
    public function resolveForTimesheet(Timesheet $timesheet, array $scheduleSlots = []): array
    {
        $slot = $this->resolveScheduleSlot($timesheet, $scheduleSlots);
        $computed = $this->computePunctuality($timesheet, $slot);

        $clockInAuto = $timesheet->clockInPunctuality === null;
        $clockOutAuto = $timesheet->clockOutPunctuality === null;

        return [
            'clockInPunctuality' => $clockInAuto
                ? $computed['clockIn']
                : TimesheetPunctualityStatus::fromStored($timesheet->clockInPunctuality)?->value,
            'clockOutPunctuality' => $clockOutAuto
                ? $computed['clockOut']
                : TimesheetPunctualityStatus::fromStored($timesheet->clockOutPunctuality)?->value,
            'clockInPunctualityAuto' => $clockInAuto,
            'clockOutPunctualityAuto' => $clockOutAuto,
            'scheduledStartTime' => $slot['start'] ?? null,
            'scheduledEndTime' => $slot['end'] ?? null,
        ];
    }

    /**
     * @param array<string, array{start:string,end:string}> $scheduleSlots
     * @return array{start:string,end:string}|null
     */
    public function resolveScheduleSlot(Timesheet $timesheet, array $scheduleSlots = []): ?array
    {
        if ($scheduleSlots !== []) {
            return $this->scheduleCoverage->scheduledSlotForTimesheet($timesheet, $scheduleSlots);
        }

        return $this->scheduledHoursResolver->scheduledWindowForTimesheet($timesheet);
    }

    /**
     * @param array{start:string,end:string}|null $slot
     * @return array{clockIn:?string,clockOut:?string}
     */
    public function computePunctuality(Timesheet $timesheet, ?array $slot): array
    {
        if ($slot === null) {
            return [
                'clockIn' => null,
                'clockOut' => null,
            ];
        }

        return [
            'clockIn' => $this->comparePunchToSchedule(
                $timesheet->clockInTime,
                $slot['start'],
            ),
            'clockOut' => $this->comparePunchToSchedule(
                $timesheet->clockOutTime,
                $slot['end'],
            ),
        ];
    }

    private function comparePunchToSchedule(?Carbon $punchTime, string $scheduledTime): ?string
    {
        if ($punchTime === null) {
            return null;
        }

        $punchMinutes = $this->timeToMinutes($punchTime->format('H:i:s'));
        $scheduledMinutes = $this->timeToMinutes($scheduledTime);

        if ($punchMinutes < $scheduledMinutes) {
            return TimesheetPunctualityStatus::Early->value;
        }

        if ($punchMinutes > $scheduledMinutes) {
            return TimesheetPunctualityStatus::Late->value;
        }

        return TimesheetPunctualityStatus::OnTime->value;
    }

    private function timeToMinutes(string $time): int
    {
        $normalized = trim($time);

        if ($normalized === '' || preg_match('/^(\d{1,2}):(\d{2})/', $normalized, $matches) !== 1) {
            return 0;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }
}
