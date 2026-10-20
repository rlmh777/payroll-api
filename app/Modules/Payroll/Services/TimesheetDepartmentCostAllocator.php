<?php

namespace App\Modules\Payroll\Services;

/**
 * Splits one earning amount across timesheet departments by hours.
 * The last department receives the rounding remainder so the slices sum to the original amount.
 */
class TimesheetDepartmentCostAllocator
{
    /**
     * @param  array<int, float>  $hoursByDepartment
     * @return list<array{departmentId: int|null, hours: float|null, amount: float}>
     */
    public function allocate(
        float $amount,
        ?float $hours,
        array $hoursByDepartment,
        ?int $fallbackDepartmentId,
    ): array {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return [];
        }

        $weights = [];
        foreach ($hoursByDepartment as $departmentId => $weight) {
            $weight = round((float) $weight, 2);
            if ($weight <= 0) {
                continue;
            }

            $weights[(int) $departmentId] = $weight;
        }

        if ($weights === []) {
            return [[
                'departmentId' => $fallbackDepartmentId,
                'hours' => $hours,
                'amount' => $amount,
            ]];
        }

        $totalWeight = array_sum($weights);
        $totalHours = $hours !== null ? round(max(0, $hours), 2) : null;
        $assignedAmount = 0.0;
        $assignedHours = 0.0;
        $lastDepartmentId = array_key_last($weights);
        $slices = [];

        foreach ($weights as $departmentId => $weight) {
            $isLast = $departmentId === $lastDepartmentId;
            if ($isLast) {
                $sliceAmount = round($amount - $assignedAmount, 2);
                $sliceHours = $totalHours === null ? null : round($totalHours - $assignedHours, 2);
            } else {
                $share = $weight / $totalWeight;
                $sliceAmount = round($amount * $share, 2);
                $assignedAmount = round($assignedAmount + $sliceAmount, 2);
                $sliceHours = null;
                if ($totalHours !== null) {
                    $sliceHours = round($totalHours * $share, 2);
                    $assignedHours = round($assignedHours + $sliceHours, 2);
                }
            }

            if ($sliceAmount <= 0 && ($sliceHours === null || $sliceHours <= 0)) {
                continue;
            }

            $slices[] = [
                'departmentId' => $departmentId,
                'hours' => $sliceHours,
                'amount' => $sliceAmount,
            ];
        }

        if ($slices === []) {
            return [[
                'departmentId' => $fallbackDepartmentId,
                'hours' => $hours,
                'amount' => $amount,
            ]];
        }

        return $slices;
    }
}
