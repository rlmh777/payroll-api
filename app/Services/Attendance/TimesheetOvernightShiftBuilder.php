<?php

namespace App\Services\Attendance;

use App\Enums\OvernightShiftMode;
use Carbon\Carbon;

class TimesheetOvernightShiftBuilder
{
    public function __construct(
        private readonly TimesheetHoursCalculator $hoursCalculator,
        private readonly TimesheetShiftDaySplitter $daySplitter,
        private readonly TimesheetLunchBreakResolver $lunchBreakResolver,
    ) {
    }

    /**
     * Pair employee punches across days, split overnight work at midnight, and attribute
     * payable hours to each calendar day (including holiday-specific days).
     *
     * @param array<string, array<string, array<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}>>> $logGroupsByEmployee
     * @param callable(string, Carbon): (?int) $departmentIdResolver
     * @param callable(string, Carbon): OvernightShiftMode $overnightShiftModeResolver
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    public function buildSlotPayloadsByEmployeeDate(
        array $logGroupsByEmployee,
        callable $departmentIdResolver,
        int $clockRoundOffMinutes,
        callable $overnightShiftModeResolver,
    ): array {
        $result = [];

        foreach ($logGroupsByEmployee as $employeeId => $dateGroups) {
            $allPunches = collect();

            foreach ($dateGroups as $punches) {
                $allPunches = $allPunches->merge($punches);
            }

            if ($allPunches->isEmpty()) {
                continue;
            }

            $slotCalc = $this->hoursCalculator->calculateSlots(
                $allPunches
                    ->sortBy(fn (array $punch) => $punch['punchDateTime']->getTimestamp())
                    ->values(),
                $clockRoundOffMinutes,
            );

            foreach ($slotCalc['slots'] as $pairIndex => $slot) {
                $roundedIn = Carbon::parse((string) $slot['roundOffClockInTime']);
                $roundedOut = Carbon::parse((string) $slot['roundOffClockOutTime']);
                $shiftStartDate = $roundedIn->copy()->startOfDay();
                $departmentId = $departmentIdResolver((string) $employeeId, $shiftStartDate);
                $overnightShiftMode = $overnightShiftModeResolver((string) $employeeId, $shiftStartDate);
                $lunchSettings = $this->lunchBreakResolver->lunchSettingsFor(
                    (string) $employeeId,
                    $shiftStartDate,
                    $departmentId,
                    $pairIndex,
                );
                $lunchMinutes = LunchBreakHelper::breakMinutes(
                    $lunchSettings['include_lunch_hour'],
                    $lunchSettings['lunch_hour_hours'],
                );

                $segments = $this->daySplitter->buildSegments($roundedIn, $roundedOut, $overnightShiftMode);
                $totalRawSeconds = round(array_sum(array_map(
                    static fn (array $segment) => (float) $segment['seconds'],
                    $segments,
                )), 2);
                $totalPayableSeconds = max(0.0, $totalRawSeconds - ($lunchMinutes * 60));
                $payableBySegment = $this->daySplitter->distributePayableSeconds($totalPayableSeconds, $segments);
                $remarks = $pairIndex === 0 && !empty($slotCalc['issues'])
                    ? implode(' ', $slotCalc['issues'])
                    : null;

                foreach ($segments as $segmentIndex => $segment) {
                    $date = $segment['date'];
                    $result[$employeeId][$date] ??= [];
                    $slotIndex = count($result[$employeeId][$date]);
                    $payableSeconds = $payableBySegment[$segmentIndex] ?? 0.0;

                    $result[$employeeId][$date][] = [
                        'slotIndex' => $slotIndex,
                        'clockInTime' => $segment['start']->format('Y-m-d H:i:s'),
                        'clockOutTime' => $segment['end']->format('Y-m-d H:i:s'),
                        'clockInDeviceId' => $slot['clockInDeviceId'] ?? null,
                        'clockOutDeviceId' => $slot['clockOutDeviceId'] ?? null,
                        'roundOffClockInTime' => $segment['start']->format('Y-m-d H:i:s'),
                        'roundOffClockOutTime' => $segment['end']->format('Y-m-d H:i:s'),
                        'clockedHoursWorked' => $segment['hours'],
                        'hoursWorked' => round($payableSeconds / 3600, 2),
                        'includeLunchHour' => $segmentIndex === 0
                            && (bool) $lunchSettings['include_lunch_hour'],
                        'lunchHourHours' => (float) $lunchSettings['lunch_hour_hours'],
                        'remarks' => $remarks,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   segments:array<int, array{
     *     date:string,
     *     start:Carbon,
     *     end:Carbon,
     *     seconds:float,
     *     hours:float,
     *     payableHours:float
     *   }>,
     *   totalPayableHours:float
     * }
     */
    public function buildManualShiftSegments(
        Carbon $roundOffIn,
        Carbon $roundOffOut,
        int $lunchMinutes,
        OvernightShiftMode $overnightShiftMode = OvernightShiftMode::SplitAtMidnight,
    ): array {
        $segments = $this->daySplitter->buildSegments($roundOffIn, $roundOffOut, $overnightShiftMode);
        $totalRawSeconds = round(array_sum(array_map(
            static fn (array $segment) => (float) $segment['seconds'],
            $segments,
        )), 2);
        $totalPayableSeconds = max(0.0, $totalRawSeconds - ($lunchMinutes * 60));
        $payableBySegment = $this->daySplitter->distributePayableSeconds($totalPayableSeconds, $segments);

        $mapped = [];

        foreach ($segments as $index => $segment) {
            $payableSeconds = $payableBySegment[$index] ?? 0.0;
            $mapped[] = [
                'date' => $segment['date'],
                'start' => $segment['start'],
                'end' => $segment['end'],
                'seconds' => $segment['seconds'],
                'hours' => $segment['hours'],
                'payableHours' => round($payableSeconds / 3600, 2),
            ];
        }

        return [
            'segments' => $mapped,
            'totalPayableHours' => round($totalPayableSeconds / 3600, 2),
        ];
    }
}
