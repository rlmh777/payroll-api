<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\TimesheetPunctualityStatus;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetExceptionAnalyzer
{
    public function __construct(
        private readonly TimesheetOverlapValidator $overlapValidator,
    ) {
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     * @param callable(Timesheet): float $scheduledHoursFor
     * @return array<string, list<array{code: string, severity: string, message: string}>>
     */
    public function analyzeCollection(
        Collection $timesheets,
        callable $isOutsideSchedule,
        callable $hasLeaveConflict,
        callable $scheduledHoursFor,
    ): array {
        $groupedByDay = $timesheets->groupBy(
            fn (Timesheet $timesheet) => sprintf(
                '%s|%s',
                (string) $timesheet->employeeId,
                $timesheet->date?->toDateString() ?? '',
            ),
        );

        $results = [];

        foreach ($timesheets as $timesheet) {
            $dayKey = sprintf(
                '%s|%s',
                (string) $timesheet->employeeId,
                $timesheet->date?->toDateString() ?? '',
            );
            $sameDayTimesheets = $groupedByDay->get($dayKey, collect());

            $results[(string) $timesheet->id] = $this->analyze(
                $timesheet,
                $sameDayTimesheets,
                (bool) $isOutsideSchedule($timesheet),
                (bool) $hasLeaveConflict($timesheet),
                (float) $scheduledHoursFor($timesheet),
            );
        }

        return $results;
    }

    /**
     * @param Collection<int, Timesheet> $sameDayTimesheets
     * @return list<array{code: string, severity: string, message: string}>
     */
    public function analyze(
        Timesheet $timesheet,
        Collection $sameDayTimesheets,
        bool $isOutsideSchedule = false,
        bool $hasLeaveConflict = false,
        float $scheduledHours = 0.0,
    ): array {
        $exceptions = [];

        foreach ($this->processingRemarkExceptions($timesheet) as $exception) {
            $exceptions[$exception['code'].'|'.$exception['message']] = $exception;
        }

        foreach ($this->rowLevelExceptions($timesheet, $isOutsideSchedule, $hasLeaveConflict, $scheduledHours) as $exception) {
            $exceptions[$exception['code'].'|'.$exception['message']] = $exception;
        }

        foreach ($this->dayLevelExceptions($sameDayTimesheets) as $exception) {
            $exceptions[$exception['code'].'|'.$exception['message']] = $exception;
        }

        return array_values($exceptions);
    }

    /**
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function processingRemarkExceptions(Timesheet $timesheet): array
    {
        $remarks = trim((string) ($timesheet->remarks ?? ''));
        if ($remarks === '') {
            return [];
        }

        $parts = preg_split('/\.\s+(?=[A-Z])/', $remarks) ?: [$remarks];
        $exceptions = [];

        foreach ($parts as $part) {
            $message = trim(rtrim(trim($part), '.'));
            if ($message === '') {
                continue;
            }

            $exceptions[] = [
                'code' => 'processing_issue',
                'severity' => 'error',
                'message' => str_ends_with($message, '.') ? $message : $message.'.',
            ];
        }

        if ($exceptions === []) {
            $exceptions[] = [
                'code' => 'processing_issue',
                'severity' => 'error',
                'message' => $remarks,
            ];
        }

        return $exceptions;
    }

    /**
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function rowLevelExceptions(
        Timesheet $timesheet,
        bool $isOutsideSchedule,
        bool $hasLeaveConflict,
        float $scheduledHours,
    ): array {
        $exceptions = [];
        $hasClockIn = $timesheet->clockInTime !== null || $timesheet->roundOffClockInTime !== null;
        $hasClockOut = $timesheet->clockOutTime !== null || $timesheet->roundOffClockOutTime !== null;

        if ($hasClockIn xor $hasClockOut) {
            $exceptions[] = [
                'code' => 'missing_clock_pair',
                'severity' => 'error',
                'message' => 'Missing clock-in or clock-out for this timesheet slot.',
            ];
        }

        $roundIn = $timesheet->roundOffClockInTime ? Carbon::parse($timesheet->roundOffClockInTime) : null;
        $roundOut = $timesheet->roundOffClockOutTime ? Carbon::parse($timesheet->roundOffClockOutTime) : null;

        if ($roundIn && $roundOut && ! $roundOut->gt($roundIn)) {
            $exceptions[] = [
                'code' => 'zero_rounded_duration',
                'severity' => 'error',
                'message' => 'Rounded clock-out is not after rounded clock-in.',
            ];
        }

        $payableHours = (float) ($timesheet->hoursWorked ?? 0);
        $dailyLimit = $this->excessiveDailyHoursLimit();
        if ($payableHours > $dailyLimit) {
            $exceptions[] = [
                'code' => 'excessive_daily_hours',
                'severity' => 'warning',
                'message' => sprintf(
                    'Payable hours (%.2f) exceed the daily review threshold of %.2f hours.',
                    $payableHours,
                    $dailyLimit,
                ),
            ];
        }

        $scheduledHours = max(0.0, $scheduledHours);
        if ($scheduledHours > 0) {
            $buffer = max(0.0, (float) config('attendance.excessive_scheduled_hours_buffer', 4));
            $threshold = $scheduledHours + $buffer;

            if ($payableHours > $threshold) {
                $exceptions[] = [
                    'code' => 'excessive_vs_scheduled',
                    'severity' => 'warning',
                    'message' => sprintf(
                        'Payable hours (%.2f) are more than %.2f hours above scheduled hours (%.2f).',
                        $payableHours,
                        $buffer,
                        $scheduledHours,
                    ),
                ];
            }
        }

        if ($hasLeaveConflict) {
            $exceptions[] = [
                'code' => 'leave_conflict',
                'severity' => 'error',
                'message' => 'Approved leave conflicts with recorded work time.',
            ];
        }

        if ($isOutsideSchedule) {
            $exceptions[] = [
                'code' => 'outside_schedule',
                'severity' => 'warning',
                'message' => 'Clock times fall outside the employee schedule for this day.',
            ];
        }

        foreach (['clockInPunctuality' => 'Clock-in', 'clockOutPunctuality' => 'Clock-out'] as $field => $label) {
            $status = TimesheetPunctualityStatus::fromStored($timesheet->{$field});
            if ($status === TimesheetPunctualityStatus::Late) {
                $exceptions[] = [
                    'code' => 'late_punch',
                    'severity' => 'warning',
                    'message' => sprintf('%s is marked late against the schedule.', $label),
                ];
            }
        }

        return $exceptions;
    }

    /**
     * @param Collection<int, Timesheet> $sameDayTimesheets
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function dayLevelExceptions(Collection $sameDayTimesheets): array
    {
        if ($sameDayTimesheets->count() <= 1) {
            return [];
        }

        $exceptions = [];
        $clockedRows = $sameDayTimesheets->filter(
            fn (Timesheet $timesheet) => $timesheet->roundOffClockInTime && $timesheet->roundOffClockOutTime,
        )->values();

        if ($clockedRows->count() <= 1) {
            return [];
        }

        $overlapIssues = $this->overlapValidator->overlappingSlotIssues(
            $clockedRows->map(fn (Timesheet $timesheet) => [
                'roundOffClockInTime' => $timesheet->roundOffClockInTime?->format('Y-m-d H:i:s'),
                'roundOffClockOutTime' => $timesheet->roundOffClockOutTime?->format('Y-m-d H:i:s'),
            ])->all(),
        );

        foreach ($overlapIssues as $message) {
            $exceptions[] = [
                'code' => 'overlapping_slots',
                'severity' => 'error',
                'message' => $message,
            ];
        }

        $roundedInCounts = [];
        $roundedOutCounts = [];
        $roundedPairCounts = [];

        foreach ($clockedRows as $timesheet) {
            $roundIn = Carbon::parse($timesheet->roundOffClockInTime)->format('Y-m-d H:i');
            $roundOut = Carbon::parse($timesheet->roundOffClockOutTime)->format('Y-m-d H:i');
            $pairKey = $roundIn.'|'.$roundOut;

            $roundedInCounts[$roundIn] = ($roundedInCounts[$roundIn] ?? 0) + 1;
            $roundedOutCounts[$roundOut] = ($roundedOutCounts[$roundOut] ?? 0) + 1;
            $roundedPairCounts[$pairKey] = ($roundedPairCounts[$pairKey] ?? 0) + 1;
        }

        foreach ($roundedInCounts as $time => $count) {
            if ($count > 1) {
                $exceptions[] = [
                    'code' => 'duplicate_rounded_clock_in',
                    'severity' => 'warning',
                    'message' => sprintf(
                        '%d clock-ins round to the same time (%s).',
                        $count,
                        Carbon::parse($time)->format('g:i A'),
                    ),
                ];
            }
        }

        foreach ($roundedOutCounts as $time => $count) {
            if ($count > 1) {
                $exceptions[] = [
                    'code' => 'duplicate_rounded_clock_out',
                    'severity' => 'warning',
                    'message' => sprintf(
                        '%d clock-outs round to the same time (%s).',
                        $count,
                        Carbon::parse($time)->format('g:i A'),
                    ),
                ];
            }
        }

        foreach ($roundedPairCounts as $pairKey => $count) {
            if ($count <= 1) {
                continue;
            }

            [$roundIn, $roundOut] = explode('|', $pairKey, 2);
            $exceptions[] = [
                'code' => 'duplicate_rounded_range',
                'severity' => 'error',
                'message' => sprintf(
                    '%d punch pairs collapse to the same rounded range (%s to %s).',
                    $count,
                    Carbon::parse($roundIn)->format('g:i A'),
                    Carbon::parse($roundOut)->format('g:i A'),
                ),
            ];
        }

        $totalPayableHours = round((float) $sameDayTimesheets->sum('hoursWorked'), 2);
        $dailyLimit = $this->excessiveDailyHoursLimit();
        if ($totalPayableHours > $dailyLimit && $clockedRows->count() > 1) {
            $exceptions[] = [
                'code' => 'excessive_daily_total_hours',
                'severity' => 'warning',
                'message' => sprintf(
                    'Combined payable hours for the day (%.2f) exceed the daily review threshold of %.2f hours.',
                    $totalPayableHours,
                    $dailyLimit,
                ),
            ];
        }

        return $exceptions;
    }

    private function excessiveDailyHoursLimit(): float
    {
        $configured = (float) config('attendance.excessive_daily_hours', 0);
        if ($configured > 0) {
            return $configured;
        }

        $standardDaily = max(0.0, (float) config('attendance.standard_daily_hours', 8));

        return max(16.0, round($standardDaily * 2, 2));
    }
}
