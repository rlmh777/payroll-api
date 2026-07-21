<?php

namespace App\Modules\Payroll\Services;

use App\Models\EmployeeDefaultAllowance;
use App\Models\HistoricalEmployeeAllowance;
use App\Models\PayrollRunPoolDistribution;
use Illuminate\Support\Collection;

class AllowanceResolutionService
{
    /**
     * @return array{
     *     taxableTotal: float,
     *     nonTaxableTotal: float,
     *     ssSubjectTotal: float,
     *     lines: list<array<string, mixed>>
     * }
     */
    public function forEmployee(
        string $employeeId,
        string $payrollRunId,
        ?int $payrateFrequencyId,
    ): array {
        $lines = [];
        $taxableTotal = 0.0;
        $nonTaxableTotal = 0.0;
        $ssSubjectTotal = 0.0;

        $defaults = EmployeeDefaultAllowance::query()
            ->with('allowance')
            ->where('employeeId', $employeeId)
            ->get();

        foreach ($defaults as $default) {
            $amount = round((float) $default->amount, 2);
            $line = $this->classifyAllowanceAmount(
                $amount,
                (bool) ($default->allowance?->isTaxable ?? false),
                (bool) ($default->allowance?->isSocialSecurityDeductable ?? false),
                'default',
                (string) $default->id,
                filled($default->accountId) ? (string) $default->accountId : null,
            );
            $lines[] = $line;
            $taxableTotal = round($taxableTotal + $line['taxableAmount'], 2);
            $nonTaxableTotal = round($nonTaxableTotal + $line['nonTaxableAmount'], 2);
            $ssSubjectTotal = round($ssSubjectTotal + $line['ssSubjectAmount'], 2);
        }

        $imported = HistoricalEmployeeAllowance::query()
            ->with('allowance')
            ->where('payroll_run_id', $payrollRunId)
            ->where('employee_id', $employeeId)
            ->get();

        foreach ($imported as $record) {
            $amount = round((float) $record->amount, 2);
            $isTaxable = $record->taxableAmount !== null
                ? (float) $record->taxableAmount > 0
                : (bool) ($record->allowance?->isTaxable ?? true);
            $isSsSubject = $record->ssSubjectAmount !== null
                ? (float) $record->ssSubjectAmount > 0
                : (bool) ($record->allowance?->isSocialSecurityDeductable ?? true);

            $taxableAmount = $record->taxableAmount !== null
                ? round((float) $record->taxableAmount, 2)
                : ($isTaxable ? $amount : 0.0);
            $ssSubjectAmount = $record->ssSubjectAmount !== null
                ? round((float) $record->ssSubjectAmount, 2)
                : ($isSsSubject ? $amount : 0.0);

            $line = [
                'source' => 'import',
                'sourceId' => (string) $record->id,
                'accountId' => filled($record->accountId) ? (string) $record->accountId : null,
                'amount' => $amount,
                'taxableAmount' => $taxableAmount,
                'nonTaxableAmount' => round(max(0, $amount - $taxableAmount), 2),
                'ssSubjectAmount' => $ssSubjectAmount,
            ];
            $lines[] = $line;
            $taxableTotal = round($taxableTotal + $line['taxableAmount'], 2);
            $nonTaxableTotal = round($nonTaxableTotal + $line['nonTaxableAmount'], 2);
            $ssSubjectTotal = round($ssSubjectTotal + $line['ssSubjectAmount'], 2);
        }

        $poolRows = PayrollRunPoolDistribution::query()
            ->with(['poolDistributionType.payrollEarningCode', 'poolDistributionType.allowance'])
            ->where('payroll_run_id', $payrollRunId)
            ->where('employee_id', $employeeId)
            ->where('is_eligible', true)
            ->where('amount', '>', 0)
            ->get();

        foreach ($poolRows as $poolRow) {
            $amount = round((float) $poolRow->amount, 2);
            if ($amount <= 0) {
                continue;
            }

            $type = $poolRow->poolDistributionType;
            $isTaxable = (bool) ($type?->is_taxable ?? true);
            $isSsSubject = (bool) ($type?->is_ss_subject ?? true);
            $accountId = $type?->payrollEarningCode?->account_id;

            $line = $this->classifyAllowanceAmount(
                $amount,
                $isTaxable,
                $isSsSubject,
                'pool',
                (string) $poolRow->id,
                filled($accountId) ? (string) $accountId : null,
            );
            $line['poolDistributionTypeId'] = $type?->id;
            $line['poolLabel'] = $type?->name;
            $line['payrollEarningCodeId'] = $type?->payroll_earning_code_id;

            $lines[] = $line;
            $taxableTotal = round($taxableTotal + $line['taxableAmount'], 2);
            $nonTaxableTotal = round($nonTaxableTotal + $line['nonTaxableAmount'], 2);
            $ssSubjectTotal = round($ssSubjectTotal + $line['ssSubjectAmount'], 2);
        }

        return [
            'taxableTotal' => $taxableTotal,
            'nonTaxableTotal' => $nonTaxableTotal,
            'ssSubjectTotal' => $ssSubjectTotal,
            'lines' => $lines,
        ];
    }

    /**
     * @param  Collection<int, string>  $employeeIds
     * @return array<string, array<string, mixed>>
     */
    public function forEmployees(
        Collection $employeeIds,
        string $payrollRunId,
        ?int $payrateFrequencyId,
    ): array {
        $results = [];

        foreach ($employeeIds as $employeeId) {
            $results[(string) $employeeId] = $this->forEmployee(
                (string) $employeeId,
                $payrollRunId,
                $payrateFrequencyId,
            );
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyAllowanceAmount(
        float $amount,
        bool $isTaxable,
        bool $isSsSubject,
        string $source,
        string $sourceId,
        ?string $accountId = null,
    ): array {
        $taxableAmount = $isTaxable ? $amount : 0.0;
        $ssSubjectAmount = $isSsSubject ? $amount : 0.0;

        return [
            'source' => $source,
            'sourceId' => $sourceId,
            'accountId' => $accountId,
            'amount' => $amount,
            'taxableAmount' => $taxableAmount,
            'nonTaxableAmount' => round(max(0, $amount - $taxableAmount), 2),
            'ssSubjectAmount' => $ssSubjectAmount,
        ];
    }
}
