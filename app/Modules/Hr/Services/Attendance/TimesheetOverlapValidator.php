<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TimesheetOverlapValidator
{
    /**
     * @param array<int, array{roundOffClockInTime:?string, roundOffClockOutTime:?string}> $slots
     * @return array<int, string>
     */
    public function overlappingSlotIssues(array $slots): array
    {
        $issues = [];

        for ($leftIndex = 0; $leftIndex < count($slots); $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < count($slots); $rightIndex++) {
                $left = $slots[$leftIndex];
                $right = $slots[$rightIndex];

                if ($this->datetimeRangesOverlap(
                    $left['roundOffClockInTime'] ?? null,
                    $left['roundOffClockOutTime'] ?? null,
                    $right['roundOffClockInTime'] ?? null,
                    $right['roundOffClockOutTime'] ?? null,
                )) {
                    $issues[] = 'Overlapping timesheet time ranges detected.';
                    break 2;
                }
            }
        }

        return $issues;
    }

    /**
     * @throws ValidationException
     */
    public function assertNoOverlapWithExisting(
        string $employeeId,
        string $date,
        ?Carbon $clockIn,
        ?Carbon $clockOut,
        ?string $excludeTimesheetId = null,
    ): void {
        if (!$clockIn || !$clockOut || $clockOut->lte($clockIn)) {
            return;
        }

        $query = Timesheet::query()
            ->where('employeeId', $employeeId)
            ->whereDate('date', $date);

        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }

        /** @var Collection<int, Timesheet> $existing */
        $existing = $query->get();

        foreach ($existing as $timesheet) {
            $otherIn = $timesheet->roundOffClockInTime
                ? Carbon::parse($timesheet->roundOffClockInTime)
                : null;
            $otherOut = $timesheet->roundOffClockOutTime
                ? Carbon::parse($timesheet->roundOffClockOutTime)
                : null;

            if ($this->datetimeRangesOverlap(
                $clockIn->format('Y-m-d H:i:s'),
                $clockOut->format('Y-m-d H:i:s'),
                $otherIn?->format('Y-m-d H:i:s'),
                $otherOut?->format('Y-m-d H:i:s'),
            )) {
                throw ValidationException::withMessages([
                    'overlap' => ['Timesheet time range overlaps with another entry for this employee on the same day.'],
                ]);
            }
        }
    }

    private function datetimeRangesOverlap(
        ?string $start1,
        ?string $end1,
        ?string $start2,
        ?string $end2,
    ): bool {
        if (!$start1 || !$end1 || !$start2 || !$end2) {
            return false;
        }

        $from1 = Carbon::parse($start1);
        $to1 = Carbon::parse($end1);
        $from2 = Carbon::parse($start2);
        $to2 = Carbon::parse($end2);

        if ($to1->lte($from1) || $to2->lte($from2)) {
            return false;
        }

        return $from1->lt($to2) && $from2->lt($to1);
    }
}
