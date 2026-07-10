<?php

namespace App\Services\Payroll;

use App\Models\EmployeeDefaultDeduction;
use App\Models\HistoricalEmployeeDeduction;
use Illuminate\Support\Collection;

class DeductionResolutionService
{
    /**
     * @return array{
     *     total: float,
     *     lines: list<array<string, mixed>>
     * }
     */
    public function forEmployee(
        string $employeeId,
        string $payrollRunId,
        ?int $payrateFrequencyId,
        float $netBeforeDeductions,
    ): array {
        $lines = [];

        $defaults = EmployeeDefaultDeduction::query()
            ->where('employeeId', $employeeId)
            ->when($payrateFrequencyId, fn ($query) => $query->where('frequencyId', $payrateFrequencyId))
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();

        foreach ($defaults as $default) {
            $lines[] = [
                'source' => 'default',
                'sourceId' => (string) $default->id,
                'accountId' => filled($default->accountId) ? (string) $default->accountId : null,
                'amount' => round((float) $default->amount, 2),
                'allowPartialDeduction' => (bool) $default->allowPartialDeduction,
                'priority' => (int) ($default->priority ?? 0),
            ];
        }

        $imported = HistoricalEmployeeDeduction::query()
            ->where('payroll_run_id', $payrollRunId)
            ->where('employee_id', $employeeId)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();

        foreach ($imported as $record) {
            $lines[] = [
                'source' => 'import',
                'sourceId' => (string) $record->id,
                'accountId' => filled($record->accountId) ? (string) $record->accountId : null,
                'amount' => round((float) $record->amount, 2),
                'allowPartialDeduction' => false,
                'priority' => (int) ($record->priority ?? 0),
            ];
        }

        usort($lines, function (array $left, array $right) {
            return [$left['priority'], $left['sourceId']] <=> [$right['priority'], $right['sourceId']];
        });

        $remaining = max(0, round($netBeforeDeductions, 2));
        $total = 0.0;
        $appliedLines = [];

        foreach ($lines as $line) {
            $requested = (float) $line['amount'];

            if ($line['allowPartialDeduction']) {
                $applied = round(min($requested, $remaining), 2);
            } else {
                $applied = $requested;
            }

            $appliedLines[] = [
                ...$line,
                'appliedAmount' => $applied,
            ];
            $total = round($total + $applied, 2);
            $remaining = round($remaining - $applied, 2);
        }

        return [
            'total' => $total,
            'lines' => $appliedLines,
        ];
    }

    /**
     * @param Collection<int, string> $employeeIds
     * @return array<string, array<string, mixed>>
     */
    public function forEmployees(
        Collection $employeeIds,
        string $payrollRunId,
        ?int $payrateFrequencyId,
        callable $netBeforeDeductionsResolver,
    ): array {
        $results = [];

        foreach ($employeeIds as $employeeId) {
            $employeeKey = (string) $employeeId;
            $results[$employeeKey] = $this->forEmployee(
                $employeeKey,
                $payrollRunId,
                $payrateFrequencyId,
                (float) $netBeforeDeductionsResolver($employeeKey),
            );
        }

        return $results;
    }
}
