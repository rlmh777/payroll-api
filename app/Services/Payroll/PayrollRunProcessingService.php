<?php

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayrollRunProcessingService
{
    public function __construct(
        private readonly PayrollRunCalculationService $payrollRunCalculationService,
        private readonly PayrollRunEarningLineBuilderService $payrollRunEarningLineBuilderService,
    ) {
    }

    /**
     * @return array{
     *     payrollRunId: string,
     *     status: string,
     *     employeeCount: int,
     *     asOfDate: string,
     *     totals: array<string, float>
     * }
     */
    public function process(PayrollRun $payrollRun): array
    {
        if ($payrollRun->status === 'posted') {
            throw new InvalidArgumentException('This payroll run has already been processed.');
        }

        return DB::transaction(function () use ($payrollRun) {
            $summary = $this->payrollRunCalculationService->calculate($payrollRun, true, true);
            $rows = $summary['rows'] ?? [];

            if ($rows === []) {
                throw new InvalidArgumentException('No employees are in scope for this payroll run.');
            }

            $this->payrollRunEarningLineBuilderService->syncForRun($payrollRun, $summary);

            $payrollRun->update(['status' => 'posted']);
            $payrollRun->refresh();

            return [
                'payrollRunId' => (string) $payrollRun->id,
                'status' => (string) $payrollRun->status,
                'employeeCount' => count($rows),
                'asOfDate' => (string) ($summary['asOfDate'] ?? ''),
                'totals' => $summary['totals'] ?? [],
            ];
        });
    }
}
