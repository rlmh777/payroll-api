<?php

namespace App\Modules\Payroll\Services;

use App\Models\EmployeeDefaultDeduction;
use App\Models\HistoricalEmployeeDeduction;
use Illuminate\Support\Collection;

class DeductionResolutionService
{
    public function __construct(
        private readonly PayrollRunOccurrenceMatcher $occurrenceMatcher,
    ) {
    }

    /**
     * @return array{
     *     total: float,
     *     lines: list<array<string, mixed>>
     * }
     */
    public function forEmployee(
        string $employeeId,
        string $payrollRunId,
        float $netBeforeDeductions,
        ?PayrollRunOccurrenceContext $occurrenceContext = null,
    ): array {
        $lines = [];
        $occurrenceContext ??= $this->occurrenceMatcher->contextForRunId($payrollRunId);

        $defaults = EmployeeDefaultDeduction::query()
            ->with('deductionType')
            ->where('employeeId', $employeeId)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();

        foreach ($defaults as $default) {
            if (! $this->occurrenceMatcher->matchesAssignment($default, $occurrenceContext)) {
                continue;
            }

            $lines[] = [
                'source' => 'default',
                'sourceId' => (string) $default->id,
                'accountId' => $this->resolveAccountId($default->accountId, $default->deductionType?->accountId),
                'amount' => round((float) $default->amount, 2),
                'allowPartialDeduction' => (bool) $default->allowPartialDeduction,
                'priority' => (int) ($default->priority ?? 0),
            ];
        }

        $imported = HistoricalEmployeeDeduction::query()
            ->with('deductionType')
            ->where('payroll_run_id', $payrollRunId)
            ->where('employee_id', $employeeId)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();

        foreach ($imported as $record) {
            $lines[] = [
                'source' => 'import',
                'sourceId' => (string) $record->id,
                'accountId' => $this->resolveAccountId($record->accountId, $record->deductionType?->accountId),
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
        callable $netBeforeDeductionsResolver,
    ): array {
        $results = [];
        $occurrenceContext = $this->occurrenceMatcher->contextForRunId($payrollRunId);

        foreach ($employeeIds as $employeeId) {
            $employeeKey = (string) $employeeId;
            $results[$employeeKey] = $this->forEmployee(
                $employeeKey,
                $payrollRunId,
                (float) $netBeforeDeductionsResolver($employeeKey),
                $occurrenceContext,
            );
        }

        return $results;
    }

    private function resolveAccountId(mixed $assignedAccountId, mixed $typeAccountId): ?string
    {
        if (filled($assignedAccountId)) {
            return (string) $assignedAccountId;
        }

        return filled($typeAccountId) ? (string) $typeAccountId : null;
    }
}
