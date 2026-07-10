<?php

namespace App\Services\Attendance;

class TimesheetPayFields
{
    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    public static function apply(array $slot, bool $isUnpaidLeave = false): array
    {
        $regular = (float) ($slot['regularHours'] ?? 0);
        $overtime = (float) ($slot['overtimeHours'] ?? 0);
        $holiday = (float) ($slot['holidayHours'] ?? 0);
        $unpaid = (float) ($slot['unpaidHours'] ?? 0);
        $hoursWorked = (float) ($slot['hoursWorked'] ?? 0);
        $status = strtoupper((string) ($slot['workingStatus'] ?? 'REGULAR'));
        $payableComponents = round($regular + $overtime + $holiday, 2);

        if (array_key_exists('isPaid', $slot)) {
            $isPaid = (bool) $slot['isPaid'];
        } elseif ($isUnpaidLeave || ($status === 'UNPAID' && $unpaid > 0 && $payableComponents <= 0)) {
            $isPaid = false;
        } else {
            $isPaid = true;
        }

        $slot['isPaid'] = $isPaid;
        $slot['paidHours'] = $isPaid
            ? ($payableComponents > 0 ? $payableComponents : max(0.0, round($hoursWorked, 2)))
            : 0.0;

        return $slot;
    }

    /**
     * @return array{isPaid: bool, paidHours: float, unpaidHours: float}
     */
    public static function fromStoredHours(
        bool $isPaid,
        float $regularHours,
        float $overtimeHours,
        float $holidayHours,
        float $hoursWorked = 0.0,
    ): array {
        $payableComponents = round($regularHours + $overtimeHours + $holidayHours, 2);
        $payable = $payableComponents > 0
            ? $payableComponents
            : max(0.0, round($hoursWorked, 2));

        if (!$isPaid) {
            return [
                'isPaid' => false,
                'paidHours' => 0.0,
                'unpaidHours' => $payable,
            ];
        }

        return [
            'isPaid' => true,
            'paidHours' => $payable,
            'unpaidHours' => 0.0,
        ];
    }
}
