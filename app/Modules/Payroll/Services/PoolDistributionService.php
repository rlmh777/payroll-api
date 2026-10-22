<?php

namespace App\Modules\Payroll\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePoolPoint;
use App\Models\EmploymentDetail;
use App\Models\PayrollRun;
use App\Models\PayrollRunPoolDistribution;
use App\Models\PayrollRunPoolShare;
use App\Models\PayrollRunPoolShareBatch;
use App\Models\PayrollRunPoolTotal;
use App\Models\PoolDistributionType;
use App\Modules\Payroll\Support\MoneyPrecision;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PoolDistributionService
{
    public function __construct(
        private readonly EmployeeHoursBankService $hoursBankService,
        private readonly PayrollTimesheetScopeService $payrollTimesheetScopeService,
        private readonly PayrollRunFrequencyResolver $payrollRunFrequencyResolver,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function totalsForRun(PayrollRun $payrollRun): array
    {
        $types = PoolDistributionType::query()
            ->with(['payrollEarningCode.account', 'departmentShares.department'])
            ->where('is_active', true)
            ->where('calculation_mode', '!=', PoolDistributionType::MODE_DISABLED)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $totals = PayrollRunPoolTotal::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->get()
            ->keyBy('pool_distribution_type_id');

        $shareSummary = PayrollRunPoolShare::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->selectRaw('pool_distribution_type_id, COUNT(DISTINCT employee_id) as share_count, SUM(shares) as share_total, COUNT(DISTINCT batch_id) as batch_count, COUNT(*) FILTER (WHERE batch_id IS NULL) as legacy_rows')
            ->groupBy('pool_distribution_type_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->pool_distribution_type_id);

        return $types->map(function (PoolDistributionType $type) use ($totals, $shareSummary) {
            $total = $totals->get($type->id);
            $shares = $shareSummary->get($type->id);
            $earningCode = $type->payrollEarningCode;
            $account = $earningCode?->account;

            return [
                'poolDistributionTypeId' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'calculationMode' => $type->calculation_mode,
                'requiresHoursEligibility' => (bool) $type->requires_hours_eligibility,
                'isTaxable' => (bool) $type->is_taxable,
                'isSsSubject' => (bool) $type->is_ss_subject,
                'payrollEarningCodeId' => $type->payroll_earning_code_id,
                'earningCodeName' => $earningCode?->name,
                'accountId' => $account?->id,
                'accountName' => $account?->name,
                'accountCode' => $account?->code1,
                'importedShareCount' => (int) ($shares->share_count ?? 0),
                'importedShareTotal' => round((float) ($shares->share_total ?? 0), 4),
                'importedFileCount' => (int) ($shares->batch_count ?? 0) + ((int) ($shares->legacy_rows ?? 0) > 0 ? 1 : 0),
                'totalAmount' => MoneyPrecision::store($total?->total_amount ?? 0),
                'notes' => $total?->notes,
                'totalId' => $total?->id,
                'departmentShares' => $type->departmentShares->map(fn ($share) => [
                    'departmentId' => (int) $share->department_id,
                    'departmentName' => $share->department?->name,
                    'percent' => round((float) $share->percent, 2),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * @param list<array{poolDistributionTypeId:int|string, totalAmount:float|int|string, notes?:string|null}> $totals
     * @return list<array<string, mixed>>
     */
    public function saveTotals(PayrollRun $payrollRun, array $totals): array
    {
        return DB::transaction(function () use ($payrollRun, $totals) {
            foreach ($totals as $row) {
                $typeId = (int) ($row['poolDistributionTypeId'] ?? 0);
                if ($typeId <= 0) {
                    continue;
                }

                $amount = MoneyPrecision::store($row['totalAmount'] ?? 0);
                $notes = $row['notes'] ?? null;

                $existing = PayrollRunPoolTotal::query()
                    ->where('payroll_run_id', $payrollRun->id)
                    ->where('pool_distribution_type_id', $typeId)
                    ->first();

                if ($amount <= 0 && $existing) {
                    $existing->delete();
                    continue;
                }

                if ($amount <= 0) {
                    continue;
                }

                if ($existing) {
                    $existing->update([
                        'total_amount' => $amount,
                        'notes' => $notes,
                    ]);
                } else {
                    PayrollRunPoolTotal::create([
                        'id' => (string) Str::uuid(),
                        'payroll_run_id' => $payrollRun->id,
                        'pool_distribution_type_id' => $typeId,
                        'total_amount' => $amount,
                        'notes' => $notes,
                    ]);
                }
            }

            return $this->totalsForRun($payrollRun);
        });
    }

    /**
     * @param list<array{employeeCode:string, shares:float|int|string, amount?:float|int|string|null}> $rows
     * @return array{importedShareCount:int, importedShareTotal:float, mode:string, fileTotal:float, employees:list<array{employeeId:string, employeeCode:string, shares:float, amount:float|null}>}
     */
    public function replaceShares(
        PayrollRun $payrollRun,
        int $poolDistributionTypeId,
        array $rows,
        string $mode = 'replace',
        ?float $fileTotal = null,
        ?string $sourceName = null,
    ): array
    {
        $type = PoolDistributionType::query()
            ->whereKey($poolDistributionTypeId)
            ->where('is_active', true)
            ->where('calculation_mode', '!=', PoolDistributionType::MODE_DISABLED)
            ->first();

        if (!$type) {
            throw ValidationException::withMessages([
                'poolDistributionTypeId' => 'Choose an active pool such as tips or shares.',
            ]);
        }

        $weightLabel = $this->usesTipPercentage($type) ? 'percentage' : 'share count';
        $combined = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['employeeCode'] ?? ''));
            $shares = round((float) ($row['shares'] ?? 0), 4);
            if ($code === '') {
                continue;
            }
            if ($shares <= 0) {
                throw ValidationException::withMessages([
                    'shares' => "The {$weightLabel} for {$code} must be greater than zero.",
                ]);
            }
            $amount = $this->nullableAmount($row['amount'] ?? null);
            $key = mb_strtolower($code);
            if (!isset($combined[$key])) {
                $combined[$key] = [
                    'code' => $code,
                    'shares' => $shares,
                    'amount' => $amount,
                ];
                continue;
            }

            $combined[$key]['shares'] = round($combined[$key]['shares'] + $shares, 4);
            if ($amount !== null || $combined[$key]['amount'] !== null) {
                $combined[$key]['amount'] = round(($combined[$key]['amount'] ?? 0) + ($amount ?? 0), 2);
            }
        }

        if ($combined === []) {
            throw ValidationException::withMessages([
                'shares' => "The file needs at least one employee and a {$weightLabel}.",
            ]);
        }

        $employeesByKey = $this->employeesForIdentifiers(array_keys($combined));
        $missing = [];
        $resolved = [];
        foreach ($combined as $key => $row) {
            $matches = $employeesByKey->get($key, collect());
            if ($matches->isEmpty()) {
                $missing[] = $row['code'];
                continue;
            }
            if ($matches->count() > 1) {
                throw ValidationException::withMessages([
                    'shares' => "{$row['code']} matches more than one employee. Use a unique employee code or internal code.",
                ]);
            }
            $resolved[$key] = $matches->first();
        }

        if ($missing !== []) {
            $shown = array_slice($missing, 0, 8);
            $suffix = count($missing) > 8 ? ' and '.(count($missing) - 8).' more' : '';
            throw ValidationException::withMessages([
                'shares' => 'These employee codes were not found: '.implode(', ', $shown).$suffix.'.',
            ]);
        }

        $mode = $mode === 'amend' ? 'amend' : 'replace';
        $batchTotal = $this->fileBatchTotal($combined, $fileTotal);

        return DB::transaction(function () use ($payrollRun, $type, $combined, $resolved, $mode, $batchTotal, $sourceName) {
            if ($mode === 'amend') {
                $this->attachLegacySharesToBatch($payrollRun, (int) $type->id);
            } else {
                PayrollRunPoolShare::query()
                    ->where('payroll_run_id', $payrollRun->id)
                    ->where('pool_distribution_type_id', $type->id)
                    ->delete();
                PayrollRunPoolShareBatch::query()
                    ->where('payroll_run_id', $payrollRun->id)
                    ->where('pool_distribution_type_id', $type->id)
                    ->delete();
            }

            $batch = PayrollRunPoolShareBatch::query()->create([
                'id' => (string) Str::uuid(),
                'payroll_run_id' => $payrollRun->id,
                'pool_distribution_type_id' => $type->id,
                'total_amount' => $batchTotal,
                'source_name' => $sourceName !== null && trim($sourceName) !== '' ? trim($sourceName) : null,
            ]);

            $saved = [];
            foreach ($combined as $key => $row) {
                $employee = $resolved[$key];
                PayrollRunPoolShare::query()->create([
                    'id' => (string) Str::uuid(),
                    'payroll_run_id' => $payrollRun->id,
                    'pool_distribution_type_id' => $type->id,
                    'batch_id' => $batch->id,
                    'employee_id' => $employee->id,
                    'shares' => $row['shares'],
                    'payout_amount' => $row['amount'],
                ]);
                $saved[] = [
                    'employeeId' => (string) $employee->id,
                    'employeeCode' => (string) $employee->code,
                    'shares' => $row['shares'],
                    'amount' => $row['amount'],
                ];
            }

            $combinedTotal = (float) PayrollRunPoolShareBatch::query()
                ->where('payroll_run_id', $payrollRun->id)
                ->where('pool_distribution_type_id', $type->id)
                ->sum('total_amount');
            $this->savePoolTotal($payrollRun, (int) $type->id, $combinedTotal);

            usort($saved, fn (array $left, array $right) => strcmp($left['employeeCode'], $right['employeeCode']));

            return [
                'importedShareCount' => count($saved),
                'importedShareTotal' => round(array_sum(array_column($saved, 'shares')), 4),
                'mode' => $mode,
                'fileTotal' => $batchTotal,
                'employees' => $saved,
            ];
        });
    }

    /**
     * @param  array<string, array{code:string, shares:float, amount:float|null}>  $combined
     */
    private function fileBatchTotal(array $combined, ?float $fileTotal): float
    {
        $allHaveAmounts = collect($combined)->every(fn (array $row) => $row['amount'] !== null);
        if ($allHaveAmounts) {
            return round((float) array_sum(array_column($combined, 'amount')), 2);
        }

        $total = MoneyPrecision::store($fileTotal ?? 0);
        if ($total <= 0) {
            throw ValidationException::withMessages([
                'totalAmount' => 'Type a total for this file, or put an amount on every row.',
            ]);
        }

        $this->allocateUploadedPayouts($total, array_map(
            fn (array $row) => ['weight' => $row['shares'], 'amount' => $row['amount']],
            array_values($combined),
        ));

        return $total;
    }

    private function attachLegacySharesToBatch(PayrollRun $payrollRun, int $typeId): void
    {
        $hasLegacy = PayrollRunPoolShare::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->where('pool_distribution_type_id', $typeId)
            ->whereNull('batch_id')
            ->exists();
        if (!$hasLegacy) {
            return;
        }

        $total = (float) (PayrollRunPoolTotal::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->where('pool_distribution_type_id', $typeId)
            ->value('total_amount') ?? 0);
        $batch = PayrollRunPoolShareBatch::query()->create([
            'id' => (string) Str::uuid(),
            'payroll_run_id' => $payrollRun->id,
            'pool_distribution_type_id' => $typeId,
            'total_amount' => MoneyPrecision::store($total),
            'source_name' => 'Earlier upload',
        ]);

        PayrollRunPoolShare::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->where('pool_distribution_type_id', $typeId)
            ->whereNull('batch_id')
            ->update(['batch_id' => $batch->id]);
    }

    /**
     * @param array{amount:float|int|string, paidShares?:float|int|string|null, paidPercent?:float|int|string|null} $input
     */
    public function correctDistribution(
        PayrollRun $payrollRun,
        PayrollRunPoolDistribution $distribution,
        array $input,
    ): PayrollRunPoolDistribution {
        if ((string) $distribution->payroll_run_id !== (string) $payrollRun->id) {
            abort(404);
        }

        return DB::transaction(function () use ($payrollRun, $distribution, $input) {
            $amount = MoneyPrecision::store($input['amount'] ?? 0);
            $paidShares = $this->nullableShare($input['paidShares'] ?? null);
            $paidPercent = $this->nullableShare($input['paidPercent'] ?? null);
            $oldAmount = MoneyPrecision::store($distribution->amount);
            $unit = $paidPercent ?? $paidShares;

            $distribution->update([
                'amount' => $amount,
                'paid_shares' => $paidShares,
                'paid_percent' => $paidPercent,
                'points' => $unit ?? $distribution->points,
                'weight' => 1,
                'weighted_points' => $unit ?? $distribution->weighted_points,
                'is_eligible' => $amount > 0,
                'eligibility_reason' => $amount > 0 ? null : 'Amount corrected to zero',
                'manually_corrected' => true,
            ]);

            $this->syncCorrectedShares($distribution, $oldAmount, $amount, $paidShares, $paidPercent);

            $poolTotal = (float) PayrollRunPoolDistribution::query()
                ->where('payroll_run_id', $payrollRun->id)
                ->where('pool_distribution_type_id', $distribution->pool_distribution_type_id)
                ->sum('amount');
            $this->savePoolTotal($payrollRun, (int) $distribution->pool_distribution_type_id, $poolTotal);

            return $distribution->fresh(['poolDistributionType', 'employee.person', 'department']) ?? $distribution;
        });
    }

    private function nullableShare(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 4);
    }

    private function syncCorrectedShares(
        PayrollRunPoolDistribution $distribution,
        float $oldAmount,
        float $amount,
        ?float $paidShares,
        ?float $paidPercent,
    ): void {
        $shares = PayrollRunPoolShare::query()
            ->where('payroll_run_id', $distribution->payroll_run_id)
            ->where('pool_distribution_type_id', $distribution->pool_distribution_type_id)
            ->where('employee_id', $distribution->employee_id)
            ->orderBy('created_at')
            ->get();
        if ($shares->isEmpty()) {
            return;
        }

        $newWeight = $paidPercent ?? $paidShares;
        if ($newWeight !== null) {
            $current = round((float) $shares->sum(fn (PayrollRunPoolShare $share) => (float) $share->shares), 4);
            $running = 0.0;
            $lastIndex = $shares->count() - 1;
            foreach ($shares as $index => $share) {
                if ($current <= 0) {
                    $share->shares = $index === $lastIndex ? $newWeight : 0;
                } elseif ($index === $lastIndex) {
                    $share->shares = round($newWeight - $running, 4);
                } else {
                    $portion = round(((float) $share->shares / $current) * $newWeight, 4);
                    $share->shares = $portion;
                    $running = round($running + $portion, 4);
                }
            }
        }

        $knownAmounts = $shares->every(fn (PayrollRunPoolShare $share) => $share->payout_amount !== null);
        $oldSum = round((float) $shares->sum(fn (PayrollRunPoolShare $share) => (float) ($share->payout_amount ?? 0)), 2);
        $lastIndex = $shares->count() - 1;
        $payouts = [];
        $runningAmount = 0.0;
        foreach ($shares as $index => $share) {
            if ($index === $lastIndex) {
                $payouts[] = MoneyPrecision::store($amount - $runningAmount);
                continue;
            }
            if ($knownAmounts && $oldSum > 0) {
                $portion = MoneyPrecision::store(((float) $share->payout_amount / $oldSum) * $amount);
                $payouts[] = $portion;
                $runningAmount = MoneyPrecision::store($runningAmount + $portion);
                continue;
            }
            $payouts[] = 0.0;
        }
        if (collect($payouts)->contains(fn ($value) => (float) $value < 0)) {
            $payouts = array_fill(0, $shares->count(), 0.0);
            $payouts[$lastIndex] = $amount;
        }
        foreach ($shares as $index => $share) {
            $share->payout_amount = $payouts[$index] ?? 0;
            $share->save();
        }

        $delta = MoneyPrecision::store($amount - $oldAmount);
        $batchId = $shares->last()?->batch_id;
        if ($batchId && abs($delta) > 0.0000001) {
            $batch = PayrollRunPoolShareBatch::query()->find($batchId);
            if ($batch) {
                $batch->update([
                    'total_amount' => max(0, MoneyPrecision::store((float) $batch->total_amount + $delta)),
                ]);
            }
        }
    }

    /**
     * Split a pool total across share weights. The last share absorbs the rounding remainder.
     *
     * @param  list<float|int|string>  $shares
     * @return list<float>
     */
    public function allocateByShares(float $total, array $shares): array
    {
        $weights = array_values(array_map(fn ($share) => round((float) $share, 4), $shares));
        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        $poolAmount = MoneyPrecision::store($total);
        $denominator = round(array_sum($weights), 4);
        if ($denominator <= 0 || $poolAmount <= 0) {
            return array_fill(0, $count, 0.0);
        }

        $amounts = [];
        $allocated = 0.0;
        foreach ($weights as $index => $weight) {
            if ($index === $count - 1) {
                $amounts[] = MoneyPrecision::store($poolAmount - $allocated);
                break;
            }

            $amount = MoneyPrecision::store($poolAmount * ($weight / $denominator));
            $amounts[] = $amount;
            $allocated = MoneyPrecision::store($allocated + $amount);
        }

        return $amounts;
    }

    /**
     * Pay explicit file amounts as entered. Rows without an amount split the remaining total by weight.
     *
     * @param  list<array{weight:float|int|string, amount?:float|int|string|null}>  $rows
     * @return list<float>
     */
    public function allocateUploadedPayouts(float $total, array $rows): array
    {
        $result = array_fill(0, count($rows), 0.0);
        $openIndexes = [];
        $openWeights = [];
        $fixedTotal = 0.0;

        foreach ($rows as $index => $row) {
            $amount = $this->nullableAmount($row['amount'] ?? null);
            if ($amount !== null) {
                $stored = MoneyPrecision::store($amount);
                $result[$index] = $stored;
                $fixedTotal = MoneyPrecision::store($fixedTotal + $stored);
                continue;
            }

            $openIndexes[] = $index;
            $openWeights[] = $row['weight'] ?? 0;
        }

        if ($openIndexes === []) {
            return $result;
        }

        $remainder = MoneyPrecision::store($total - $fixedTotal);
        if ($remainder < 0) {
            throw ValidationException::withMessages([
                'totalAmount' => 'The typed total is less than the amounts already set on the file.',
            ]);
        }

        $allocated = $this->allocateByShares($remainder, $openWeights);
        foreach ($openIndexes as $position => $index) {
            $result[$index] = $allocated[$position] ?? 0.0;
        }

        return $result;
    }

    /**
     * Rebuild shares pools from the employee pool points that are effective on the pay period.
     * An uploaded share file for that pool is left as saved.
     */
    public function refreshSharePoolsFromEmployeePoints(PayrollRun $payrollRun): void
    {
        if (strtolower((string) $payrollRun->status) === 'posted') {
            return;
        }

        $payrollRun->loadMissing(['payPeriodSchedule']);
        $asOfDate = Carbon::parse(
            $payrollRun->payPeriodSchedule?->end_date
            ?? $payrollRun->payPeriodSchedule?->pay_date
            ?? now()
        )->startOfDay();
        $employeeIds = $this->employeeIdsForDistribution($payrollRun, $asOfDate);
        if ($employeeIds === []) {
            return;
        }

        $types = PoolDistributionType::query()
            ->where('is_active', true)
            ->where('calculation_mode', '!=', PoolDistributionType::MODE_DISABLED)
            ->get()
            ->filter(fn (PoolDistributionType $type) => $this->usesEmployeeSharePoints($type))
            ->values();
        if ($types->isEmpty()) {
            return;
        }

        $uploadedTypeIds = PayrollRunPoolShare::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->whereIn('pool_distribution_type_id', $types->pluck('id'))
            ->distinct()
            ->pluck('pool_distribution_type_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $correctedTypeIds = PayrollRunPoolDistribution::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->where('manually_corrected', true)
            ->pluck('pool_distribution_type_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $types = $types
            ->reject(fn (PoolDistributionType $type) => in_array((int) $type->id, $uploadedTypeIds, true))
            ->reject(fn (PoolDistributionType $type) => in_array((int) $type->id, $correctedTypeIds, true))
            ->values();
        if ($types->isEmpty()) {
            return;
        }

        $hoursEligibility = $this->hoursBankService->syncForPayrollRun($payrollRun, $employeeIds);

        foreach ($types as $type) {
            if ($type->calculation_mode === PoolDistributionType::MODE_DEPARTMENT_EQUAL_SHARE || !$type->isDistributable()) {
                continue;
            }

            $poolAmount = MoneyPrecision::store(
                PayrollRunPoolTotal::query()
                    ->where('payroll_run_id', $payrollRun->id)
                    ->where('pool_distribution_type_id', $type->id)
                    ->value('total_amount') ?? 0
            );
            if ($poolAmount <= 0) {
                continue;
            }

            PayrollRunPoolDistribution::query()
                ->where('payroll_run_id', $payrollRun->id)
                ->where('pool_distribution_type_id', $type->id)
                ->delete();

            $this->distributeByEmployeePoints(
                $payrollRun,
                $type,
                $poolAmount,
                $employeeIds,
                $hoursEligibility,
                $asOfDate,
            );
        }
    }

    /**
     * @param list<string> $employeeIds
     * @return array{
     *     totals: list<array<string, mixed>>,
     *     distributions: list<array<string, mixed>>,
     *     departmentBreakdown: list<array<string, mixed>>,
     *     hoursEligibility: array<string, array<string, mixed>>
     * }
     */
    public function distribute(PayrollRun $payrollRun, array $employeeIds): array
    {
        $payrollRun->loadMissing(['payPeriodSchedule']);
        $asOfDate = Carbon::parse(
            $payrollRun->payPeriodSchedule?->end_date
            ?? $payrollRun->payPeriodSchedule?->pay_date
            ?? now()
        )->startOfDay();
        $workedIds = $this->timesheetEmployeeIds($payrollRun);

        return DB::transaction(function () use ($payrollRun, $employeeIds, $asOfDate, $workedIds) {
            $hoursEligibility = $this->hoursBankService->syncForPayrollRun($payrollRun, $employeeIds);

            PayrollRunPoolDistribution::query()
                ->where('payroll_run_id', $payrollRun->id)
                ->delete();

            $totals = PayrollRunPoolTotal::query()
                ->with('poolDistributionType')
                ->where('payroll_run_id', $payrollRun->id)
                ->get();

            $uploadedShares = PayrollRunPoolShare::query()
                ->with(['employee.person', 'batch'])
                ->where('payroll_run_id', $payrollRun->id)
                ->get()
                ->groupBy(fn (PayrollRunPoolShare $share) => (int) $share->pool_distribution_type_id);

            $distributions = [];
            $departmentBreakdown = [];
            $pendingTotals = $totals->keyBy(fn (PayrollRunPoolTotal $total) => (int) $total->pool_distribution_type_id);

            foreach ($uploadedShares as $typeId => $shareRows) {
                if ($pendingTotals->has((int) $typeId) || $shareRows->isEmpty()) {
                    continue;
                }

                $type = PoolDistributionType::query()->find((int) $typeId);
                if (!$type) {
                    continue;
                }

                $placeholder = new PayrollRunPoolTotal([
                    'payroll_run_id' => $payrollRun->id,
                    'pool_distribution_type_id' => (int) $typeId,
                    'total_amount' => 0,
                ]);
                $placeholder->setRelation('poolDistributionType', $type);
                $pendingTotals->put((int) $typeId, $placeholder);
            }

            foreach ($pendingTotals as $total) {
                $type = $total->poolDistributionType;
                if (!$type || $type->calculation_mode === PoolDistributionType::MODE_DISABLED || !$type->is_active) {
                    continue;
                }

                $poolAmount = MoneyPrecision::store($total->total_amount);
                $shareRows = $uploadedShares->get((int) $type->id, collect());
                if ($shareRows->isNotEmpty()) {
                    array_push($distributions, ...$this->distributeByUploadedShares(
                        $payrollRun,
                        $type,
                        $poolAmount,
                        $shareRows,
                    ));
                    continue;
                }

                if ($poolAmount <= 0) {
                    continue;
                }

                if (!$type->isDistributable()) {
                    continue;
                }

                if ($type->calculation_mode === PoolDistributionType::MODE_DEPARTMENT_EQUAL_SHARE) {
                    $result = $this->distributeByDepartment(
                        $payrollRun,
                        $type,
                        $poolAmount,
                        $workedIds,
                        $hoursEligibility,
                        $asOfDate,
                    );
                    array_push($distributions, ...$result['distributions']);
                    array_push($departmentBreakdown, ...$result['departmentBreakdown']);
                    continue;
                }

                array_push($distributions, ...$this->distributeByEmployeePoints(
                    $payrollRun,
                    $type,
                    $poolAmount,
                    $employeeIds,
                    $hoursEligibility,
                    $asOfDate,
                ));
            }

            return [
                'totals' => $this->totalsForRun($payrollRun),
                'distributions' => $distributions,
                'departmentBreakdown' => $departmentBreakdown,
                'hoursEligibility' => $hoursEligibility,
            ];
        });
    }

    /**
     * @param list<string> $employeeIds
     * @param array<string, array<string, mixed>> $hoursEligibility
     * @return list<array<string, mixed>>
     */
    private function distributeByEmployeePoints(
        PayrollRun $payrollRun,
        PoolDistributionType $type,
        float $poolAmount,
        array $employeeIds,
        array $hoursEligibility,
        Carbon $asOfDate,
    ): array {
        $pointRows = $this->currentPointsForEmployees($employeeIds, (int) $type->id, $asOfDate);
        $eligibleParticipants = [];
        $distributions = [];

        foreach ($employeeIds as $employeeId) {
            $employeeId = (string) $employeeId;
            $point = $pointRows->get($employeeId);
            $points = round((float) ($point?->points ?? 0), 4);
            $weight = round((float) ($point?->weight ?? 1), 4);
            $configuredPercent = $point?->tip_percent !== null
                ? round((float) $point->tip_percent, 4)
                : null;
            if ($this->usesTipPercentage($type) && $configuredPercent !== null) {
                $points = $configuredPercent;
                $weight = 1.0;
            }
            $weighted = round($points * $weight, 4);
            $paidUnits = $this->configuredPayoutUnits($type, $configuredPercent, $points);
            $eligibility = $hoursEligibility[$employeeId] ?? $this->defaultEligibility();
            $requiresEligibility = (bool) $type->requires_hours_eligibility;
            $isEligible = !$requiresEligibility || (bool) ($eligibility['isEligible'] ?? true);
            $reason = $isEligible ? null : ($eligibility['reason'] ?? 'Not eligible based on hours');

            if ($type->calculation_mode === PoolDistributionType::MODE_EQUAL_SHARE) {
                $weighted = $isEligible ? 1.0 : 0.0;
            }

            if (!$isEligible || $weighted <= 0) {
                $distributions[] = $this->storeDistribution(
                    $payrollRun->id,
                    (int) $type->id,
                    $employeeId,
                    $points,
                    $weight,
                    $weighted,
                    0,
                    0,
                    false,
                    $reason ?? ($weighted <= 0 ? 'No points assigned for this pool type' : null),
                    $eligibility,
                    $paidUnits,
                );
                continue;
            }

            $eligibleParticipants[] = [
                'employeeId' => $employeeId,
                'points' => $points,
                'weight' => $weight,
                'weighted' => $weighted,
                'eligibility' => $eligibility,
                'paidUnits' => $paidUnits,
            ];
        }

        $denominator = round(collect($eligibleParticipants)->sum('weighted'), 4);
        if ($denominator <= 0) {
            return $distributions;
        }

        $allocated = 0.0;
        $lastIndex = count($eligibleParticipants) - 1;
        foreach ($eligibleParticipants as $index => $participant) {
            $ratio = round($participant['weighted'] / $denominator, 8);
            if ($index === $lastIndex) {
                $amount = MoneyPrecision::store($poolAmount - $allocated);
            } else {
                $amount = MoneyPrecision::store($poolAmount * $ratio);
                $allocated = MoneyPrecision::store($allocated + $amount);
            }

            $distributions[] = $this->storeDistribution(
                $payrollRun->id,
                (int) $type->id,
                $participant['employeeId'],
                $participant['points'],
                $participant['weight'],
                $participant['weighted'],
                $ratio,
                $amount,
                true,
                null,
                $participant['eligibility'],
                $participant['paidUnits'],
            );
        }

        return $distributions;
    }

    private function usesEmployeeSharePoints(PoolDistributionType $type): bool
    {
        $code = strtoupper((string) $type->code);

        return $code === 'SHARES' || str_contains($code, 'SHARE');
    }

    /**
     * @return list<string>
     */
    private function employeeIdsForDistribution(PayrollRun $payrollRun, Carbon $asOfDate): array
    {
        $schedule = $payrollRun->payPeriodSchedule;
        if (!$schedule?->start_date || !$schedule?->end_date) {
            return [];
        }

        $startDate = Carbon::parse($schedule->start_date)->startOfDay();
        $endDate = Carbon::parse($schedule->end_date)->startOfDay();
        $payPeriodGroupId = (string) $schedule->pay_period_group_id;
        $frequencyId = $this->payrollRunFrequencyResolver->resolveForRun($payrollRun, $schedule);
        $fromTimesheets = collect(
            $this->payrollTimesheetScopeService->employeeIds(
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $frequencyId,
            ),
        );
        $fromPoints = EmployeePoolPoint::query()
            ->whereDate('effective_date', '<=', $asOfDate->toDateString())
            ->where(function ($query) use ($asOfDate) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $asOfDate->toDateString());
            })
            ->pluck('employee_id');

        return $fromTimesheets
            ->merge($fromPoints)
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param list<string> $workedEmployeeIds
     * @param array<string, array<string, mixed>> $hoursEligibility
     * @return array{distributions: list<array<string, mixed>>, departmentBreakdown: list<array<string, mixed>>}
     */
    private function distributeByDepartment(
        PayrollRun $payrollRun,
        PoolDistributionType $type,
        float $poolAmount,
        array $workedEmployeeIds,
        array $hoursEligibility,
        Carbon $asOfDate,
    ): array {
        $shares = $type->departmentShares()->with('department')->get();
        $percentSum = round((float) $shares->sum('percent'), 2);
        if ($shares->isEmpty() || abs($percentSum - 100) > 0.009) {
            throw ValidationException::withMessages([
                'department_shares' => "{$type->name} department percentages must be configured and add up to 100%.",
            ]);
        }

        $departments = Department::query()->get(['id', 'name', 'parentId']);
        $parentById = $departments->pluck('parentId', 'id')->all();
        $nameById = $departments->pluck('name', 'id')->all();
        $configuredPercents = $shares->mapWithKeys(
            fn ($share) => [(int) $share->department_id => round((float) $share->percent, 2)]
        )->all();

        $schedule = $payrollRun->payPeriodSchedule;
        $startDate = Carbon::parse($schedule?->start_date ?? $asOfDate)->startOfDay();
        $endDate = Carbon::parse($schedule?->end_date ?? $asOfDate)->startOfDay();
        $payPeriodGroupId = (string) ($schedule?->pay_period_group_id ?? '');
        $frequencyId = $this->payrollRunFrequencyResolver->resolveForRun($payrollRun, $schedule);

        $workedIds = collect($workedEmployeeIds)->map(fn ($id) => (string) $id)->unique();
        $activeRows = EmploymentDetail::query()
            ->with(['employee.person'])
            ->where('isActive', true)
            ->whereNotNull('departmentId')
            ->where(function ($query) use ($startDate) {
                $query->whereNull('endDate')->orWhereDate('endDate', '>=', $startDate->toDateString());
            })
            ->where(function ($query) use ($endDate) {
                $query->whereNull('startDate')->orWhereDate('startDate', '<=', $endDate->toDateString());
            })
            ->when($payPeriodGroupId !== '', function ($query) use ($payPeriodGroupId) {
                $query->where('defaultPayPeriodGroupId', $payPeriodGroupId);
            })
            ->when($frequencyId, function ($query) use ($frequencyId) {
                $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('payrateFrequencyId', $frequencyId));
            })
            ->get(['employeeId', 'departmentId', 'startDate']);

        $employeesById = [];
        foreach ($activeRows as $detail) {
            $employeeId = (string) $detail->employeeId;
            if (! isset($employeesById[$employeeId]) || (string) $detail->startDate > (string) ($employeesById[$employeeId]['startDate'] ?? '')) {
                $employeesById[$employeeId] = [
                    'employeeId' => $employeeId,
                    'departmentId' => (int) $detail->departmentId,
                    'startDate' => (string) $detail->startDate,
                    'name' => $this->employeeDisplayName($detail->employee),
                    'code' => $detail->employee?->code,
                ];
            }
        }

        foreach ($workedIds as $employeeId) {
            if (isset($employeesById[$employeeId])) {
                continue;
            }
            $employee = Employee::query()->with('person')->find($employeeId);
            $departmentId = (int) (EmploymentDetail::query()
                ->where('employeeId', $employeeId)
                ->where('isActive', true)
                ->orderByDesc('startDate')
                ->value('departmentId') ?? 0);
            $employeesById[$employeeId] = [
                'employeeId' => $employeeId,
                'departmentId' => $departmentId,
                'startDate' => '',
                'name' => $this->employeeDisplayName($employee),
                'code' => $employee?->code,
            ];
        }

        $grouped = [];
        foreach ($employeesById as $employee) {
            $shareDepartmentId = $this->resolveShareDepartmentId(
                (int) $employee['departmentId'],
                $configuredPercents,
                $parentById,
            );
            $grouped[$shareDepartmentId ?? 0][] = $employee;
        }

        $distributions = [];
        $departmentBreakdown = [];

        foreach ($shares as $share) {
            $departmentId = (int) $share->department_id;
            $percent = round((float) $share->percent, 2);
            $departmentAmount = MoneyPrecision::store($poolAmount * ($percent / 100));
            $members = $grouped[$departmentId] ?? [];
            $working = [];
            $listed = [];

            foreach ($members as $member) {
                $worked = $workedIds->contains($member['employeeId']);
                $eligibility = $hoursEligibility[$member['employeeId']] ?? $this->defaultEligibility();
                $hoursEligible = ! $type->requires_hours_eligibility
                    || (bool) ($eligibility['isEligible'] ?? true);
                $paid = $worked && $hoursEligible;
                $listed[] = [
                    ...$member,
                    'worked' => $worked,
                    'hoursEligible' => $hoursEligible,
                    'eligibility' => $eligibility,
                    'reason' => ! $worked
                        ? 'Did not work this period'
                        : (! $hoursEligible ? ($eligibility['reason'] ?? 'Not eligible based on hours') : null),
                    'paid' => $paid,
                ];
                if ($paid) {
                    $working[] = $member['employeeId'];
                }
            }

            $paidCount = count($working);
            $shareEach = $paidCount > 0 ? MoneyPrecision::store($departmentAmount / $paidCount) : 0.0;
            $allocated = 0.0;
            $lastPaidIndex = $paidCount > 0 ? $paidCount - 1 : -1;
            $paidIndex = 0;

            foreach ($listed as $member) {
                $amount = 0.0;
                $ratio = 0.0;
                if ($member['paid'] && $paidCount > 0) {
                    if ($paidIndex === $lastPaidIndex) {
                        $amount = MoneyPrecision::store($departmentAmount - $allocated);
                    } else {
                        $amount = $shareEach;
                        $allocated = MoneyPrecision::store($allocated + $amount);
                    }
                    $ratio = round(1 / $paidCount, 8);
                    $paidIndex++;
                }

                $distributions[] = $this->storeDistribution(
                    $payrollRun->id,
                    (int) $type->id,
                    $member['employeeId'],
                    $member['paid'] ? 1 : 0,
                    1,
                    $member['paid'] ? 1 : 0,
                    $ratio,
                    $amount,
                    (bool) $member['paid'],
                    $member['reason'],
                    $member['eligibility'],
                    [
                        'employeeName' => $member['name'],
                        'employeeCode' => $member['code'],
                        'departmentId' => $departmentId,
                        'departmentName' => $nameById[$departmentId] ?? $share->department?->name,
                        'poolName' => $type->name,
                        'departmentPercent' => $percent,
                        'departmentAmount' => $departmentAmount,
                        'workedThisPeriod' => (bool) $member['worked'],
                    ],
                );
            }

            $departmentBreakdown[] = [
                'poolDistributionTypeId' => (int) $type->id,
                'poolCode' => $type->code,
                'poolName' => $type->name,
                'departmentId' => $departmentId,
                'departmentName' => $nameById[$departmentId] ?? $share->department?->name,
                'percent' => $percent,
                'departmentAmount' => $departmentAmount,
                'workingCount' => $paidCount,
                'listedCount' => count($listed),
            ];
        }

        foreach ($grouped[0] ?? [] as $member) {
            $worked = $workedIds->contains($member['employeeId']);
            $eligibility = $hoursEligibility[$member['employeeId']] ?? $this->defaultEligibility();
            $distributions[] = $this->storeDistribution(
                $payrollRun->id,
                (int) $type->id,
                $member['employeeId'],
                0,
                1,
                0,
                0,
                0,
                false,
                'Department is not in the tips split',
                $eligibility,
                [
                    'employeeName' => $member['name'],
                    'employeeCode' => $member['code'],
                    'departmentId' => $member['departmentId'] ?: null,
                    'departmentName' => $nameById[$member['departmentId']] ?? null,
                    'poolName' => $type->name,
                    'departmentPercent' => null,
                    'departmentAmount' => 0,
                    'workedThisPeriod' => $worked,
                ],
            );
        }

        return [
            'distributions' => $distributions,
            'departmentBreakdown' => $departmentBreakdown,
        ];
    }

    /**
     * @return list<string>
     */
    public function timesheetEmployeeIds(PayrollRun $payrollRun): array
    {
        $payrollRun->loadMissing(['payPeriodSchedule']);
        $schedule = $payrollRun->payPeriodSchedule;
        if (!$schedule?->start_date || !$schedule?->end_date) {
            return [];
        }

        $startDate = Carbon::parse($schedule->start_date)->startOfDay();
        $endDate = Carbon::parse($schedule->end_date)->startOfDay();
        $payPeriodGroupId = (string) $schedule->pay_period_group_id;
        $frequencyId = $this->payrollRunFrequencyResolver->resolveForRun($payrollRun, $schedule);

        return $this->payrollTimesheetScopeService->employeeIds(
            $startDate,
            $endDate,
            $payPeriodGroupId,
            $frequencyId,
        );
    }

    /**
     * Walk up the department tree until a configured share is found.
     *
     * @param array<int, float> $configuredPercents
     * @param array<int, int|null> $parentById
     */
    public function resolveShareDepartmentId(int $departmentId, array $configuredPercents, array $parentById): ?int
    {
        $current = $departmentId;
        $seen = [];
        while ($current && ! isset($seen[$current])) {
            $seen[$current] = true;
            if (isset($configuredPercents[$current])) {
                return $current;
            }
            $current = (int) ($parentById[$current] ?? 0);
        }

        return null;
    }

    private function employeeDisplayName(?Employee $employee): string
    {
        if (! $employee) {
            return '';
        }

        $name = trim(collect([
            $employee->firstName ?? $employee->person?->firstName,
            $employee->lastName ?? $employee->person?->lastName,
        ])->filter()->implode(' '));

        return $name !== '' ? $name : (string) ($employee->code ?? $employee->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultEligibility(): array
    {
        return [
            'workedHours' => 0.0,
            'expectedHours' => 0.0,
            'bankHoursApplied' => 0.0,
            'isEligible' => true,
            'reason' => null,
        ];
    }

    /**
     * @param Collection<int, string>|list<string> $employeeIds
     * @return Collection<string, EmployeePoolPoint>
     */
    public function currentPointsForEmployees($employeeIds, int $typeId, Carbon $asOfDate): Collection
    {
        $ids = collect($employeeIds)->map(fn ($id) => (string) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $rows = EmployeePoolPoint::query()
            ->where('pool_distribution_type_id', $typeId)
            ->whereIn('employee_id', $ids)
            ->whereDate('effective_date', '<=', $asOfDate->toDateString())
            ->where(function ($query) use ($asOfDate) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $asOfDate->toDateString());
            })
            ->orderByDesc('effective_date')
            ->orderByDesc('created_at')
            ->get();

        return $rows->groupBy('employee_id')->map(fn (Collection $group) => $group->first());
    }

    /**
     * @param  Collection<int, PayrollRunPoolShare>  $shareRows
     * @return list<array<string, mixed>>
     */
    private function distributeByUploadedShares(
        PayrollRun $payrollRun,
        PoolDistributionType $type,
        float $poolAmount,
        Collection $shareRows,
    ): array {
        $merged = [];
        $groups = $shareRows->groupBy(
            fn (PayrollRunPoolShare $share) => (string) ($share->batch_id ?? 'legacy'),
        );

        foreach ($groups as $rows) {
            /** @var PayrollRunPoolShare|null $first */
            $first = $rows->first();
            $batch = $first?->batch;
            $batchTotal = $batch ? MoneyPrecision::store($batch->total_amount) : $poolAmount;
            $ordered = $rows
                ->sortBy(fn (PayrollRunPoolShare $share) => mb_strtolower((string) ($share->employee?->code ?? $share->employee_id)))
                ->values();
            $needsTotal = $ordered->contains(
                fn (PayrollRunPoolShare $share) => $share->payout_amount === null,
            );
            if ($needsTotal && $batchTotal <= 0) {
                $fileLabel = $batch?->source_name ? " ({$batch->source_name})" : '';
                throw ValidationException::withMessages([
                    'totalAmount' => "Type a total for {$type->name}{$fileLabel}, or put an amount on every row in the file.",
                ]);
            }

            $amounts = $this->allocateUploadedPayouts(
                $batchTotal,
                $ordered->map(fn (PayrollRunPoolShare $share) => [
                    'weight' => (float) $share->shares,
                    'amount' => $share->payout_amount,
                ])->all(),
            );

            foreach ($ordered as $index => $share) {
                $employeeId = (string) $share->employee_id;
                $weight = round((float) $share->shares, 4);
                $amount = $amounts[$index] ?? 0.0;
                if (!isset($merged[$employeeId])) {
                    $merged[$employeeId] = [
                        'employee' => $share->employee,
                        'weight' => $weight,
                        'amount' => $amount,
                    ];
                    continue;
                }

                $merged[$employeeId]['weight'] = round($merged[$employeeId]['weight'] + $weight, 4);
                $merged[$employeeId]['amount'] = MoneyPrecision::store($merged[$employeeId]['amount'] + $amount);
            }
        }

        $orderedEmployees = collect($merged)
            ->map(fn (array $row, string $employeeId) => $row + ['employeeId' => $employeeId])
            ->sortBy(fn (array $row) => mb_strtolower((string) ($row['employee']?->code ?? $row['employeeId'])))
            ->values();
        $denominator = round((float) $orderedEmployees->sum('weight'), 4);
        $usesBatches = $shareRows->contains(fn (PayrollRunPoolShare $share) => $share->batch_id !== null);
        if ($usesBatches || $shareRows->every(fn (PayrollRunPoolShare $share) => $share->payout_amount !== null)) {
            $this->savePoolTotal(
                $payrollRun,
                (int) $type->id,
                round((float) $orderedEmployees->sum('amount'), 2),
            );
        }

        $distributions = [];
        foreach ($orderedEmployees as $row) {
            $weight = (float) $row['weight'];
            $amount = (float) $row['amount'];
            $ratio = $denominator > 0 ? round($weight / $denominator, 8) : 0.0;
            $distributions[] = $this->storeDistribution(
                $payrollRun->id,
                (int) $type->id,
                (string) $row['employeeId'],
                $weight,
                1,
                $weight,
                $ratio,
                $amount,
                $amount > 0,
                $amount > 0 ? null : 'Share amount rounded to zero',
                $this->defaultEligibility(),
                [
                    'poolName' => $type->name,
                    'employeeName' => $this->employeeDisplayName($row['employee']),
                    'employeeCode' => $row['employee']?->code,
                    'paidShares' => $this->usesTipPercentage($type) ? null : $weight,
                    'paidPercent' => $this->usesTipPercentage($type) ? $weight : null,
                ],
            );
        }

        return $distributions;
    }

    private function usesTipPercentage(PoolDistributionType $type): bool
    {
        return strtoupper((string) $type->code) === 'TIPS';
    }

    /**
     * @return array{paidShares?: float|null, paidPercent?: float|null}
     */
    private function configuredPayoutUnits(
        PoolDistributionType $type,
        ?float $configuredPercent,
        float $points,
    ): array {
        if ($this->usesTipPercentage($type)) {
            $percent = $configuredPercent ?? ($points > 0 ? $points : null);

            return ['paidPercent' => $percent];
        }

        return ['paidShares' => $points > 0 ? round($points, 4) : null];
    }

    private function nullableAmount(mixed $amount): ?float
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return round((float) $amount, 2);
    }

    /**
     * @param  list<string>  $identifiers
     * @return Collection<string, Collection<int, Employee>>
     */
    private function employeesForIdentifiers(array $identifiers): Collection
    {
        $identifiers = array_values(array_unique(array_filter($identifiers, fn ($identifier) => $identifier !== '')));
        $grouped = collect($identifiers)->mapWithKeys(fn (string $identifier) => [$identifier => collect()]);
        if ($identifiers === []) {
            return $grouped;
        }

        $placeholders = implode(',', array_fill(0, count($identifiers), '?'));
        $employees = Employee::query()
            ->where(function ($query) use ($placeholders, $identifiers) {
                $query->whereRaw('LOWER(code) IN ('.$placeholders.')', $identifiers)
                    ->orWhereRaw('LOWER(COALESCE("internalId1", \'\')) IN ('.$placeholders.')', $identifiers)
                    ->orWhereRaw('LOWER(COALESCE("internalId2", \'\')) IN ('.$placeholders.')', $identifiers);
            })
            ->get();

        foreach ($employees as $employee) {
            foreach (['code', 'internalId1', 'internalId2'] as $field) {
                $key = mb_strtolower(trim((string) ($employee->{$field} ?? '')));
                if ($key === '' || !$grouped->has($key)) {
                    continue;
                }

                $bucket = $grouped->get($key);
                if (!$bucket->contains(fn (Employee $existing) => (string) $existing->id === (string) $employee->id)) {
                    $bucket->push($employee);
                }
            }
        }

        return $grouped;
    }

    private function savePoolTotal(PayrollRun $payrollRun, int $typeId, float $amount): void
    {
        $amount = MoneyPrecision::store($amount);
        $existing = PayrollRunPoolTotal::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->where('pool_distribution_type_id', $typeId)
            ->first();

        if ($existing) {
            $existing->update(['total_amount' => $amount]);

            return;
        }

        PayrollRunPoolTotal::query()->create([
            'id' => (string) Str::uuid(),
            'payroll_run_id' => $payrollRun->id,
            'pool_distribution_type_id' => $typeId,
            'total_amount' => $amount,
        ]);
    }

    /**
     * @param array<string, mixed> $eligibility
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function storeDistribution(
        string $payrollRunId,
        int $typeId,
        string $employeeId,
        float $points,
        float $weight,
        float $weighted,
        float $ratio,
        float $amount,
        bool $isEligible,
        ?string $reason,
        array $eligibility,
        array $extra = [],
    ): array {
        $row = PayrollRunPoolDistribution::create([
            'id' => (string) Str::uuid(),
            'payroll_run_id' => $payrollRunId,
            'pool_distribution_type_id' => $typeId,
            'employee_id' => $employeeId,
            'department_id' => $extra['departmentId'] ?? null,
            'department_percent' => $extra['departmentPercent'] ?? null,
            'department_amount' => isset($extra['departmentAmount'])
                ? MoneyPrecision::store($extra['departmentAmount'])
                : null,
            'worked_this_period' => $extra['workedThisPeriod'] ?? null,
            'points' => $points,
            'weight' => $weight,
            'weighted_points' => $weighted,
            'share_ratio' => $ratio,
            'paid_shares' => array_key_exists('paidShares', $extra) ? $extra['paidShares'] : null,
            'paid_percent' => array_key_exists('paidPercent', $extra) ? $extra['paidPercent'] : null,
            'amount' => $amount,
            'is_eligible' => $isEligible,
            'eligibility_reason' => $reason,
            'worked_hours' => $eligibility['workedHours'] ?? null,
            'expected_hours' => $eligibility['expectedHours'] ?? null,
            'bank_hours_applied' => $eligibility['bankHoursApplied'] ?? null,
        ]);

        return [
            'id' => $row->id,
            'payrollRunId' => $payrollRunId,
            'poolDistributionTypeId' => $typeId,
            'poolName' => $extra['poolName'] ?? null,
            'employeeId' => $employeeId,
            'employeeName' => $extra['employeeName'] ?? null,
            'employeeCode' => $extra['employeeCode'] ?? null,
            'departmentId' => $extra['departmentId'] ?? null,
            'departmentName' => $extra['departmentName'] ?? null,
            'departmentPercent' => $extra['departmentPercent'] ?? null,
            'departmentAmount' => isset($extra['departmentAmount'])
                ? MoneyPrecision::store($extra['departmentAmount'])
                : null,
            'workedThisPeriod' => $extra['workedThisPeriod'] ?? null,
            'points' => round($points, 4),
            'weight' => round($weight, 4),
            'weightedPoints' => round($weighted, 4),
            'shareRatio' => round($ratio, 8),
            'paidShares' => array_key_exists('paidShares', $extra) && $extra['paidShares'] !== null
                ? round((float) $extra['paidShares'], 4)
                : null,
            'paidPercent' => array_key_exists('paidPercent', $extra) && $extra['paidPercent'] !== null
                ? round((float) $extra['paidPercent'], 4)
                : null,
            'amount' => MoneyPrecision::store($amount),
            'isEligible' => $isEligible,
            'eligibilityReason' => $reason,
            'workedHours' => isset($eligibility['workedHours']) ? round((float) $eligibility['workedHours'], 4) : null,
            'expectedHours' => isset($eligibility['expectedHours']) ? round((float) $eligibility['expectedHours'], 4) : null,
            'bankHoursApplied' => isset($eligibility['bankHoursApplied']) ? round((float) $eligibility['bankHoursApplied'], 4) : null,
        ];
    }
}
