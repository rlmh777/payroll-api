<?php

namespace App\Modules\Hr\Services\Attendance;

use App\Enums\CompensationMethod;

class CompensationTimesheetHoursService
{
    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    public function applyToSlot(
        array $slot,
        CompensationMethod $method,
        bool $requiresClocking,
        float $scheduledHours,
        ?float $holidayPayMultiplier,
        bool $isUnpaidLeave,
    ): array {
        $clockedHours = round((float) ($slot['clockedHoursWorked'] ?? $slot['hoursWorked'] ?? 0), 2);
        $hasClockTimes = !empty($slot['clockInTime']) || !empty($slot['clockOutTime']);

        if ($method->isBaseBased()) {
            return $this->applyBasePay($slot, $method, $requiresClocking, $scheduledHours, $holidayPayMultiplier, $isUnpaidLeave, $clockedHours, $hasClockTimes);
        }

        return $this->applyHourlyPay($slot, $method, $scheduledHours, $holidayPayMultiplier, $isUnpaidLeave, $clockedHours, $hasClockTimes);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildNoPunchDay(
        CompensationMethod $method,
        bool $requiresClocking,
        float $scheduledHours,
        ?float $holidayPayMultiplier,
        bool $isUnpaidLeave,
    ): array {
        $hoursWorked = 0.0;
        $regularHours = 0.0;
        $overtimeHours = 0.0;
        $holidayHours = 0.0;
        $unpaidHours = 0.0;
        $workingStatus = 'REGULAR';

        if ($scheduledHours <= 0) {
            return TimesheetPayFields::apply(compact('hoursWorked', 'regularHours', 'overtimeHours', 'holidayHours', 'unpaidHours', 'workingStatus'), $isUnpaidLeave);
        }

        if ($holidayPayMultiplier !== null) {
            $payable = $method->isBaseBased() ? $scheduledHours : 0.0;
            $hoursWorked = $payable;
            $holidayHours = round($payable * $holidayPayMultiplier, 2);
            $workingStatus = 'HOLIDAY';

            return TimesheetPayFields::apply(compact('hoursWorked', 'regularHours', 'overtimeHours', 'holidayHours', 'unpaidHours', 'workingStatus'), $isUnpaidLeave);
        }

        if ($method->isHourlyBased()) {
            $unpaidHours = $scheduledHours;
            $workingStatus = 'UNPAID';

            return TimesheetPayFields::apply(compact('hoursWorked', 'regularHours', 'overtimeHours', 'holidayHours', 'unpaidHours', 'workingStatus'), $isUnpaidLeave);
        }

        if ($isUnpaidLeave && !$requiresClocking) {
            $unpaidHours = $scheduledHours;
            $workingStatus = 'UNPAID';

            return TimesheetPayFields::apply(compact('hoursWorked', 'regularHours', 'overtimeHours', 'holidayHours', 'unpaidHours', 'workingStatus'), $isUnpaidLeave);
        }

        $hoursWorked = $scheduledHours;
        $regularHours = $scheduledHours;

        return TimesheetPayFields::apply(compact('hoursWorked', 'regularHours', 'overtimeHours', 'holidayHours', 'unpaidHours', 'workingStatus'), $isUnpaidLeave);
    }

    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function applyBasePay(
        array $slot,
        CompensationMethod $method,
        bool $requiresClocking,
        float $scheduledHours,
        ?float $holidayPayMultiplier,
        bool $isUnpaidLeave,
        float $clockedHours,
        bool $hasClockTimes,
    ): array {
        if ($holidayPayMultiplier !== null && $scheduledHours > 0) {
            $payableHours = round(max(0.0, $scheduledHours), 2);
            $unpaidHours = $method === CompensationMethod::BaseNoOt
                ? round(max(0.0, $clockedHours - $payableHours), 2)
                : 0.0;

            $slot['hoursWorked'] = $payableHours;
            $slot['regularHours'] = 0.0;
            $slot['overtimeHours'] = 0.0;
            $slot['holidayHours'] = round($payableHours * $holidayPayMultiplier, 2);
            $slot['unpaidHours'] = $unpaidHours;
            $slot['workingStatus'] = 'HOLIDAY';
            $slot['clockedHoursWorked'] = $hasClockTimes ? $clockedHours : 0.0;

            return TimesheetPayFields::apply($slot, $isUnpaidLeave);
        }

        if ($isUnpaidLeave && !$requiresClocking) {
            $slot['hoursWorked'] = 0.0;
            $slot['regularHours'] = 0.0;
            $slot['overtimeHours'] = 0.0;
            $slot['holidayHours'] = 0.0;
            $slot['unpaidHours'] = $scheduledHours;
            $slot['workingStatus'] = 'UNPAID';

            return TimesheetPayFields::apply($slot, $isUnpaidLeave);
        }

        if ($method === CompensationMethod::BaseOt) {
            $payable = $hasClockTimes ? $clockedHours : $scheduledHours;

            $slot['hoursWorked'] = $payable;
            $slot['regularHours'] = 0.0;
            $slot['overtimeHours'] = 0.0;
            $slot['holidayHours'] = 0.0;
            $slot['unpaidHours'] = 0.0;
            $slot['workingStatus'] = 'REGULAR';
            $slot['clockedHoursWorked'] = $hasClockTimes ? $clockedHours : 0.0;

            return TimesheetPayFields::apply($slot, $isUnpaidLeave);
        }

        $payableHours = round(max(0.0, $scheduledHours), 2);
        $unpaidHours = round(max(0.0, $clockedHours - $payableHours), 2);

        $slot['hoursWorked'] = $payableHours;
        $slot['regularHours'] = $payableHours;
        $slot['overtimeHours'] = 0.0;
        $slot['holidayHours'] = 0.0;
        $slot['unpaidHours'] = $unpaidHours;
        $slot['workingStatus'] = 'REGULAR';
        $slot['clockedHoursWorked'] = $hasClockTimes ? $clockedHours : 0.0;

        return TimesheetPayFields::apply($slot, $isUnpaidLeave);
    }

    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function applyHourlyPay(
        array $slot,
        CompensationMethod $method,
        float $scheduledHours,
        ?float $holidayPayMultiplier,
        bool $isUnpaidLeave,
        float $clockedHours,
        bool $hasClockTimes,
    ): array {
        $hoursWorked = round((float) ($slot['hoursWorked'] ?? $clockedHours), 2);

        if ($holidayPayMultiplier !== null) {
            $slot['hoursWorked'] = $hoursWorked;
            $slot['regularHours'] = 0.0;
            $slot['overtimeHours'] = 0.0;
            $slot['holidayHours'] = round($hoursWorked * $holidayPayMultiplier, 2);
            $slot['unpaidHours'] = 0.0;
            $slot['workingStatus'] = 'HOLIDAY';

            return TimesheetPayFields::apply($slot, $isUnpaidLeave);
        }

        if (!$hasClockTimes && $scheduledHours > 0) {
            $slot['hoursWorked'] = 0.0;
            $slot['regularHours'] = 0.0;
            $slot['overtimeHours'] = 0.0;
            $slot['unpaidHours'] = $scheduledHours;
            $slot['workingStatus'] = $isUnpaidLeave ? 'UNPAID' : 'UNPAID';

            return TimesheetPayFields::apply($slot, $isUnpaidLeave);
        }

        $slot['hoursWorked'] = $hoursWorked;
        $slot['clockedHoursWorked'] = $clockedHours;

        if ($method === CompensationMethod::HourlyNoOt) {
            $slot['regularHours'] = $hoursWorked;
            $slot['overtimeHours'] = 0.0;
            $slot['workingStatus'] = 'REGULAR';
        } else {
            $slot['regularHours'] = 0.0;
            $slot['overtimeHours'] = 0.0;
            $slot['workingStatus'] = 'REGULAR';
        }

        $slot['holidayHours'] = 0.0;
        $slot['unpaidHours'] = 0.0;

        return TimesheetPayFields::apply($slot, $isUnpaidLeave);
    }
}
