<?php

namespace App\Services\Attendance;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetHoursCalculator
{
    /**
     * @param Collection<int, array{punchDateTime:Carbon, deviceId:?string, punchType:?string}> $punches
     * @return array{
     *   clockInTime:?string,
     *   clockInDeviceId:?string,
     *   clockOutTime:?string,
     *   clockOutDeviceId:?string,
     *   hoursWorked:float,
     *   regularHours:float,
     *   overtimeHours:float,
     *   issues:array<int, string>
     * }
     */
    public function calculate(Collection $punches, float $regularHoursThreshold): array
    {
        $sortedPunches = $punches
            ->sortBy(fn (array $punch) => $punch['punchDateTime']->getTimestamp())
            ->values();
        $pairedPunches = $sortedPunches->contains(
            fn (array $punch) => in_array($punch['punchType'], ['IN', 'OUT'], true)
        )
            ? $this->pairDirectionalPunches($sortedPunches)
            : $this->pairChronologicalPunches($sortedPunches);
        $workedSeconds = (float) $pairedPunches['pairs']->sum(
            fn (array $pair) => $pair['clockIn']['punchDateTime']->diffInSeconds(
                $pair['clockOut']['punchDateTime'],
                false,
            )
        );
        $regularThresholdSeconds = max(0, (int) round($regularHoursThreshold * 3600));
        $regularSeconds = min($workedSeconds, $regularThresholdSeconds);
        $overtimeSeconds = max(0, $workedSeconds - $regularThresholdSeconds);
        $firstPair = $pairedPunches['pairs']->first();
        $lastPair = $pairedPunches['pairs']->last();

        return [
            'clockInTime' => $firstPair
                ? $firstPair['clockIn']['punchDateTime']->format('Y-m-d H:i:s')
                : null,
            'clockInDeviceId' => $firstPair['clockIn']['deviceId'] ?? null,
            'clockOutTime' => $lastPair
                ? $lastPair['clockOut']['punchDateTime']->format('Y-m-d H:i:s')
                : null,
            'clockOutDeviceId' => $lastPair['clockOut']['deviceId'] ?? null,
            'hoursWorked' => $this->secondsToHours($workedSeconds),
            'regularHours' => $this->secondsToHours($regularSeconds),
            'overtimeHours' => $this->secondsToHours($overtimeSeconds),
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
}
