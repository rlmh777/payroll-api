<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\ScheduleComparisonSource;
use App\Models\AttendanceSetting;
use App\Models\ScheduledWork;
use App\Models\ScheduleEmployeeTimesheet;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetScheduleCoverageService
{
    private ?ScheduleComparisonSource $comparisonSource = null;

    public function setComparisonSource(?ScheduleComparisonSource $comparisonSource): void
    {
        $this->comparisonSource = $comparisonSource;
    }

    /**
     * @param Collection<int, Timesheet>|iterable<Timesheet> $timesheets
     * @return array<string, array{start:string,end:string}>
     */
    public function buildScheduleSlots(iterable $timesheets): array
    {
        $collection = $timesheets instanceof Collection ? $timesheets : collect($timesheets);

        if ($collection->isEmpty()) {
            return [];
        }

        $employeeIds = $collection->pluck('employeeId')->filter()->unique()->values();
        $departmentIds = $collection->pluck('departmentId')->filter()->unique()->values();
        $minDate = $collection->min(fn (Timesheet $timesheet) => $timesheet->date?->format('Y-m-d'));
        $maxDate = $collection->max(fn (Timesheet $timesheet) => $timesheet->date?->format('Y-m-d'));

        if (!$minDate || !$maxDate || $employeeIds->isEmpty()) {
            return [];
        }

        $slots = [];

        $scheduledWork = ScheduledWork::query()
            ->whereDate('startDate', '<=', $maxDate)
            ->whereDate('endDate', '>=', $minDate)
            ->where(function ($query) use ($employeeIds, $departmentIds) {
                $query->whereIn('employeeId', $employeeIds);

                if ($departmentIds->isNotEmpty()) {
                    $query->orWhere(function ($departmentQuery) use ($departmentIds) {
                        $departmentQuery
                            ->whereNull('employeeId')
                            ->whereIn('departmentId', $departmentIds);
                    });
                }
            })
            ->orderBy('startTime')
            ->orderBy('id')
            ->get(['employeeId', 'departmentId', 'startDate', 'endDate', 'startTime', 'endTime']);

        $this->mergeScheduledWorkSlots($slots, $scheduledWork, $minDate, $maxDate);

        $employeeSchedules = ScheduleEmployeeTimesheet::query()
            ->whereIn('employeeId', $employeeIds)
            ->whereDate('date', '>=', $minDate)
            ->whereDate('date', '<=', $maxDate)
            ->orderBy('startTime')
            ->orderBy('id')
            ->get(['employeeId', 'date', 'startTime', 'endTime']);

        $this->mergeEmployeeScheduleSlots($slots, $employeeSchedules);

        // Skip per-timesheet template fallback here — it is N+1 and list payloads
        // already have schedule slots from scheduled_work / schedule_employee_timesheet.

        return $slots;
    }

    /**
     * @deprecated Use buildScheduleSlots()
     *
     * @param Collection<int, Timesheet>|iterable<Timesheet> $timesheets
     * @return array<string, true>
     */
    public function buildCoverageKeys(iterable $timesheets): array
    {
        return array_fill_keys(array_keys($this->buildScheduleSlots($timesheets)), true);
    }

    /**
     * @param array<string, array{start:string,end:string}> $scheduleSlots
     * @return array{start:string,end:string}|null
     */
    public function scheduledSlotForTimesheet(Timesheet $timesheet, array $scheduleSlots): ?array
    {
        return $this->resolveScheduledSlot($timesheet, $scheduleSlots);
    }

    /**
     * @param array<string, array{start:string,end:string}> $scheduleSlots
     */
    public function isOutsideSchedule(
        Timesheet $timesheet,
        array $scheduleSlots,
        ?ScheduleComparisonSource $comparisonSource = null,
    ): bool {
        if (!$this->hasComparablePunchTimes($timesheet, $comparisonSource)) {
            return false;
        }

        $slot = $this->resolveScheduledSlot($timesheet, $scheduleSlots);
        if ($slot === null) {
            return true;
        }

        return $this->punchTimesDeviatesFromSlot($timesheet, $slot, $comparisonSource);
    }

    /**
     * @param array<string, array{start:string,end:string}> $scheduleSlots
     * @return array{start:string,end:string}|null
     */
    private function resolveScheduledSlot(Timesheet $timesheet, array $scheduleSlots): ?array
    {
        $key = $this->employeeSlotKey($timesheet);
        if ($key !== null && isset($scheduleSlots[$key])) {
            return $scheduleSlots[$key];
        }

        if ($timesheet->departmentId) {
            $departmentKey = $this->departmentSlotKey(
                (int) $timesheet->departmentId,
                $timesheet->date?->format('Y-m-d'),
                (int) ($timesheet->slotIndex ?? 0),
            );

            if (isset($scheduleSlots[$departmentKey])) {
                return $scheduleSlots[$departmentKey];
            }
        }

        // Do not fall back to TimesheetScheduledHoursResolver here: that path is N+1
        // (template + employment lookups per row). List endpoints already bulk-load
        // schedule slots via buildScheduleSlots(); missing slot => outside schedule.
        return null;
    }

    /**
     * @param array{start:string,end:string} $slot
     */
    private function punchTimesDeviatesFromSlot(
        Timesheet $timesheet,
        array $slot,
        ?ScheduleComparisonSource $comparisonSource = null,
    ): bool {
        $scheduledStart = $this->normalizeTime($slot['start']);
        $scheduledEnd = $this->normalizeTime($slot['end']);
        $punchIn = $this->punchInTime($timesheet, $comparisonSource);
        $punchOut = $this->punchOutTime($timesheet, $comparisonSource);

        if ($punchIn) {
            $clockIn = $this->normalizeTime($punchIn->format('H:i:s'));
            if ($clockIn !== $scheduledStart) {
                return true;
            }
        }

        if ($punchOut) {
            $clockOut = $this->normalizeTime($punchOut->format('H:i:s'));
            if ($clockOut !== $scheduledEnd) {
                return true;
            }
        }

        return false;
    }

    private function hasComparablePunchTimes(
        Timesheet $timesheet,
        ?ScheduleComparisonSource $comparisonSource = null,
    ): bool {
        return $this->punchInTime($timesheet, $comparisonSource) !== null
            || $this->punchOutTime($timesheet, $comparisonSource) !== null;
    }

    private function punchInTime(
        Timesheet $timesheet,
        ?ScheduleComparisonSource $comparisonSource = null,
    ): ?Carbon {
        if ($this->resolveComparisonSource($comparisonSource)->usesRoundedTimes()) {
            return $timesheet->roundOffClockInTime ?? $timesheet->clockInTime;
        }

        return $timesheet->clockInTime ?? $timesheet->roundOffClockInTime;
    }

    private function punchOutTime(
        Timesheet $timesheet,
        ?ScheduleComparisonSource $comparisonSource = null,
    ): ?Carbon {
        if ($this->resolveComparisonSource($comparisonSource)->usesRoundedTimes()) {
            return $timesheet->roundOffClockOutTime ?? $timesheet->clockOutTime;
        }

        return $timesheet->clockOutTime ?? $timesheet->roundOffClockOutTime;
    }

    private function resolveComparisonSource(?ScheduleComparisonSource $comparisonSource = null): ScheduleComparisonSource
    {
        if ($comparisonSource !== null) {
            return $comparisonSource;
        }

        if ($this->comparisonSource !== null) {
            return $this->comparisonSource;
        }

        $this->comparisonSource = AttendanceSetting::current()->scheduleComparisonSourceEnum();

        return $this->comparisonSource;
    }

    /**
     * @param array<string, array{start:string,end:string}> $slots
     * @param Collection<int, ScheduledWork> $entries
     */
    private function mergeScheduledWorkSlots(
        array &$slots,
        Collection $entries,
        string $minDate,
        string $maxDate,
    ): void {
        $rangeStart = Carbon::parse($minDate)->startOfDay();
        $rangeEnd = Carbon::parse($maxDate)->startOfDay();

        $grouped = [];

        foreach ($entries as $entry) {
            $entryStart = Carbon::parse($entry->startDate)->startOfDay()->max($rangeStart);
            $entryEnd = Carbon::parse($entry->endDate)->startOfDay()->min($rangeEnd);
            $window = [
                'start' => $this->normalizeTime($entry->startTime ?? '00:00'),
                'end' => $this->normalizeTime($entry->endTime ?? '00:00'),
            ];

            for ($date = $entryStart->copy(); $date->lte($entryEnd); $date->addDay()) {
                $dateString = $date->format('Y-m-d');
                $prefix = $entry->employeeId
                    ? (string) $entry->employeeId.'|'.$dateString
                    : 'dept:'.(int) $entry->departmentId.'|'.$dateString;

                $grouped[$prefix][] = $window;
            }
        }

        foreach ($grouped as $prefix => $daySlots) {
            foreach (array_values($daySlots) as $slotIndex => $window) {
                $slots["{$prefix}|{$slotIndex}"] = $window;
            }
        }
    }

    /**
     * @param array<string, array{start:string,end:string}> $slots
     * @param Collection<int, ScheduleEmployeeTimesheet> $entries
     */
    private function mergeEmployeeScheduleSlots(array &$slots, Collection $entries): void
    {
        $grouped = [];

        foreach ($entries as $entry) {
            if (!$entry->employeeId || !$entry->date) {
                continue;
            }

            $prefix = (string) $entry->employeeId.'|'.$entry->date->format('Y-m-d');
            $grouped[$prefix][] = [
                'start' => $this->normalizeTime($entry->startTime ?? '00:00'),
                'end' => $this->normalizeTime($entry->endTime ?? '00:00'),
            ];
        }

        foreach ($grouped as $prefix => $daySlots) {
            foreach (array_values($daySlots) as $slotIndex => $window) {
                $key = "{$prefix}|{$slotIndex}";

                if (!isset($slots[$key])) {
                    $slots[$key] = $window;
                }
            }
        }
    }

    private function employeeSlotKey(Timesheet $timesheet): ?string
    {
        if (!$timesheet->employeeId || !$timesheet->date) {
            return null;
        }

        return sprintf(
            '%s|%s|%d',
            (string) $timesheet->employeeId,
            $timesheet->date->format('Y-m-d'),
            (int) ($timesheet->slotIndex ?? 0),
        );
    }

    private function departmentSlotKey(int $departmentId, ?string $date, int $slotIndex): string
    {
        return sprintf('dept:%d|%s|%d', $departmentId, $date ?? '', $slotIndex);
    }

    private function normalizeTime(mixed $value): string
    {
        $time = trim((string) $value);

        if ($time === '') {
            return '00:00';
        }

        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            return '00:00';
        }

        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }
}
