<?php

namespace App\Modules\Hr\Services\Attendance;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetHoursCalculator
{
    public function __construct(
        private readonly ClockTimeRounder $clockTimeRounder
    ) {
    }

    /**
     * @param Collection<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}> $punches
     * @return array{
     *   clockInTime:?string,
     *   clockInDeviceId:?string,
     *   clockOutTime:?string,
     *   clockOutDeviceId:?string,
     *   roundOffClockInTime:?string,
     *   roundOffClockOutTime:?string,
     *   clockedHoursWorked:float,
     *   hoursWorked:float,
     *   regularHours:float,
     *   overtimeHours:float,
     *   issues:array<int, string>
     * }
     */
    public function calculate(
        Collection $punches,
        float $regularHoursThreshold,
        int $clockRoundOffMinutes = 30
    ): array {
        $slotCalc = $this->calculateSlots($punches, $clockRoundOffMinutes);
        $roundedSeconds = (float) collect($slotCalc['slots'])->sum('roundedSeconds');
        $clockedSeconds = (float) collect($slotCalc['slots'])->sum('clockedSeconds');

        $regularThresholdSeconds = max(0, (int) round($regularHoursThreshold * 3600));
        $regularSeconds = min($roundedSeconds, $regularThresholdSeconds);
        $overtimeSeconds = max(0, $roundedSeconds - $regularThresholdSeconds);
        $firstSlot = $slotCalc['slots'][0] ?? null;
        $lastSlot = $slotCalc['slots'] === []
            ? null
            : $slotCalc['slots'][array_key_last($slotCalc['slots'])];

        return [
            'clockInTime' => $firstSlot['clockInTime'] ?? null,
            'clockInDeviceId' => $firstSlot['clockInDeviceId'] ?? null,
            'clockOutTime' => $lastSlot['clockOutTime'] ?? null,
            'clockOutDeviceId' => $lastSlot['clockOutDeviceId'] ?? null,
            'roundOffClockInTime' => $firstSlot['roundOffClockInTime'] ?? null,
            'roundOffClockOutTime' => $lastSlot['roundOffClockOutTime'] ?? null,
            'clockedHoursWorked' => $this->secondsToHours($clockedSeconds),
            'hoursWorked' => $this->secondsToHours($roundedSeconds),
            'regularHours' => $this->secondsToHours($regularSeconds),
            'overtimeHours' => $this->secondsToHours($overtimeSeconds),
            'issues' => $slotCalc['issues'],
            'slots' => $slotCalc['slots'],
        ];
    }

    /**
     * @param Collection<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}> $punches
     * @return array{
     *   slots:array<int, array{
     *     clockInTime:?string,
     *     clockInDeviceId:?string,
     *     clockOutTime:?string,
     *     clockOutDeviceId:?string,
     *     roundOffClockInTime:?string,
     *     roundOffClockOutTime:?string,
     *     clockedSeconds:float,
     *     roundedSeconds:float,
     *     clockedHoursWorked:float,
     *     hoursWorked:float
     *   }>,
     *   issues:array<int, string>
     * }
     */
    public function calculateSlots(
        Collection $punches,
        int $clockRoundOffMinutes = 30
    ): array {
        $sortedPunches = $punches
            ->sortBy(fn (array $punch) => $punch['punchDateTime']->getTimestamp())
            ->values();
        $pairedPunches = $sortedPunches->contains(
            fn (array $punch) => in_array($punch['punchType'], ['IN', 'OUT'], true)
        )
            ? $this->pairDirectionalPunches($sortedPunches)
            : $this->pairChronologicalPunches($sortedPunches);

        $slots = [];

        foreach ($pairedPunches['pairs'] as $pair) {
            $clockedSeconds = (float) $pair['clockIn']['punchDateTime']->diffInSeconds(
                $pair['clockOut']['punchDateTime'],
                false,
            );

            $roundedIn = $this->clockTimeRounder->roundClockIn(
                $pair['clockIn']['punchDateTime'],
                $clockRoundOffMinutes
            );
            $roundedOut = $this->clockTimeRounder->roundClockOut(
                $pair['clockOut']['punchDateTime'],
                $clockRoundOffMinutes
            );

            $roundedSeconds = 0.0;
            if ($roundedOut->gt($roundedIn)) {
                $roundedSeconds = (float) $roundedIn->diffInSeconds($roundedOut, false);
            }

            $slots[] = [
                'clockInTime' => $pair['clockIn']['punchDateTime']->format('Y-m-d H:i:s'),
                'clockInDeviceId' => $pair['clockIn']['deviceId'] ?? null,
                'clockOutTime' => $pair['clockOut']['punchDateTime']->format('Y-m-d H:i:s'),
                'clockOutDeviceId' => $pair['clockOut']['deviceId'] ?? null,
                'roundOffClockInTime' => $roundedIn->format('Y-m-d H:i:s'),
                'roundOffClockOutTime' => $roundedOut->format('Y-m-d H:i:s'),
                'clockedSeconds' => $clockedSeconds,
                'roundedSeconds' => $roundedSeconds,
                'clockedHoursWorked' => $this->secondsToHours($clockedSeconds),
                'hoursWorked' => $this->secondsToHours($roundedSeconds),
            ];
        }

        return [
            'slots' => $slots,
            'issues' => array_values(array_unique($pairedPunches['issues'])),
        ];
    }

    /**
     * @param Collection<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}> $punches
     * @return array{
     *   pairs:Collection<int, array{
     *     clockIn:array{punchDateTime:Carbon, deviceId:?string, punchType:?string},
     *     clockOut:array{punchDateTime:Carbon, deviceId:?string, punchType:?string}
     *   }>,
     *   issues:array<int, string>
     * }
     */
    private function pairDirectionalPunches(Collection $punches): array
    {
        $pairs = collect();
        $issues = [];
        $pendingClockIn = null;

        foreach ($punches as $punch) {
            $punchType = $punch['punchType'];

            if ($punchType === null) {
                $punchType = $pendingClockIn === null ? 'IN' : 'OUT';
                $issues[] = "Untyped punch at {$punch['punchDateTime']->format('Y-m-d H:i:s')} was inferred as {$punchType}.";
            }

            if ($punchType === 'IN') {
                if ($pendingClockIn !== null) {
                    $issues[] = 'Consecutive punch-in records detected.';
                    continue;
                }

                $pendingClockIn = $punch;
                continue;
            }

            if ($pendingClockIn === null) {
                $issues[] = 'Punch-out record has no matching punch-in.';
                continue;
            }

            if ($punch['punchDateTime']->lte($pendingClockIn['punchDateTime'])) {
                $issues[] = 'Punch-out must occur after its matching punch-in.';
                continue;
            }

            if ($this->pairOverlapsExisting($pairs, $pendingClockIn['punchDateTime'], $punch['punchDateTime'])) {
                $issues[] = 'Overlapping punch pairs detected for the same day.';
                $pendingClockIn = null;
                continue;
            }

            $pairs->push([
                'clockIn' => $pendingClockIn,
                'clockOut' => $punch,
            ]);
            $pendingClockIn = null;
        }

        if ($pendingClockIn !== null) {
            $issues[] = 'Punch-in record has no matching punch-out.';
        }

        return [
            'pairs' => $pairs,
            'issues' => $issues,
        ];
    }

    /**
     * @param Collection<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}> $punches
     * @return array{
     *   pairs:Collection<int, array{
     *     clockIn:array{punchDateTime:Carbon, deviceId:?string, punchType:?string},
     *     clockOut:array{punchDateTime:Carbon, deviceId:?string, punchType:?string}
     *   }>,
     *   issues:array<int, string>
     * }
     */
    private function pairChronologicalPunches(Collection $punches): array
    {
        $pairs = collect();
        $issues = [];

        for ($index = 0; $index + 1 < $punches->count(); $index += 2) {
            $clockIn = $punches[$index];
            $clockOut = $punches[$index + 1];

            if ($clockOut['punchDateTime']->lte($clockIn['punchDateTime'])) {
                $issues[] = 'Punch-out must occur after its matching punch-in.';
                continue;
            }

            if ($this->pairOverlapsExisting($pairs, $clockIn['punchDateTime'], $clockOut['punchDateTime'])) {
                $issues[] = 'Overlapping punch pairs detected for the same day.';
                continue;
            }

            $pairs->push([
                'clockIn' => $clockIn,
                'clockOut' => $clockOut,
            ]);
        }

        if ($punches->count() === 1) {
            $issues[] = 'Missing punch pair (clock-in or clock-out).';
        } elseif ($punches->count() % 2 !== 0) {
            $issues[] = 'Unpaired punch records detected.';
        }

        return [
            'pairs' => $pairs,
            'issues' => $issues,
        ];
    }

    private function secondsToHours(float $seconds): float
    {
        return round($seconds / 3600, 2);
    }

    /**
     * @param Collection<int, array{
     *   clockIn:array{punchDateTime:Carbon},
     *   clockOut:array{punchDateTime:Carbon}
     * }> $pairs
     */
    private function pairOverlapsExisting(Collection $pairs, Carbon $clockIn, Carbon $clockOut): bool
    {
        foreach ($pairs as $pair) {
            $existingIn = $pair['clockIn']['punchDateTime'];
            $existingOut = $pair['clockOut']['punchDateTime'];

            if ($clockIn->lt($existingOut) && $existingIn->lt($clockOut)) {
                return true;
            }
        }

        return false;
    }
}
