<?php

namespace App\Modules\Payroll\Services;

use App\Models\EmployeePoolPoint;
use App\Models\PayrollRun;
use App\Models\PayrollRunPoolDistribution;
use App\Models\PayrollRunPoolTotal;
use App\Models\PoolDistributionType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PoolDistributionService
{
    public function __construct(
        private readonly EmployeeHoursBankService $hoursBankService,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function totalsForRun(PayrollRun $payrollRun): array
    {
        $types = PoolDistributionType::query()
            ->with('payrollEarningCode')
            ->where('is_active', true)
            ->where('calculation_mode', '!=', PoolDistributionType::MODE_DISABLED)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $totals = PayrollRunPoolTotal::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->get()
            ->keyBy('pool_distribution_type_id');

        return $types->map(function (PoolDistributionType $type) use ($totals) {
            $total = $totals->get($type->id);

            return [
                'poolDistributionTypeId' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'calculationMode' => $type->calculation_mode,
                'requiresHoursEligibility' => (bool) $type->requires_hours_eligibility,
                'isTaxable' => (bool) $type->is_taxable,
                'isSsSubject' => (bool) $type->is_ss_subject,
                'payrollEarningCodeId' => $type->payroll_earning_code_id,
                'totalAmount' => round((float) ($total?->total_amount ?? 0), 2),
                'notes' => $total?->notes,
                'totalId' => $total?->id,
            ];
        })->values()->all();
    }

    /**
     * @param list<array{poolDistributionTypeId:int|string, totalAmount:float|int|string, notes?:string|null}> $totals
     */
    public function saveTotals(PayrollRun $payrollRun, array $totals): array
    {
        return DB::transaction(function () use ($payrollRun, $totals) {
            foreach ($totals as $row) {
                $typeId = (int) ($row['poolDistributionTypeId'] ?? 0);
                if ($typeId <= 0) {
                    continue;
                }

                $amount = round((float) ($row['totalAmount'] ?? 0), 2);
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
     * Distribute saved pool totals across employees and sync hours bank eligibility.
     *
     * @param list<string> $employeeIds
     * @return array{
     *     totals: list<array<string, mixed>>,
     *     distributions: list<array<string, mixed>>,
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

        return DB::transaction(function () use ($payrollRun, $employeeIds, $asOfDate) {
            $hoursEligibility = $this->hoursBankService->syncForPayrollRun($payrollRun, $employeeIds);

            PayrollRunPoolDistribution::query()
                ->where('payroll_run_id', $payrollRun->id)
                ->delete();

            $totals = PayrollRunPoolTotal::query()
                ->with('poolDistributionType')
                ->where('payroll_run_id', $payrollRun->id)
                ->get();

            $distributions = [];

            foreach ($totals as $total) {
                $type = $total->poolDistributionType;
                if (!$type || !$type->isDistributable()) {
                    continue;
                }

                $poolAmount = round((float) $total->total_amount, 2);
                if ($poolAmount <= 0) {
                    continue;
                }

                $pointRows = $this->currentPointsForEmployees($employeeIds, (int) $type->id, $asOfDate);
                $eligibleParticipants = [];

                foreach ($employeeIds as $employeeId) {
                    $employeeId = (string) $employeeId;
                    $point = $pointRows->get($employeeId);
                    $points = round((float) ($point?->points ?? 0), 4);
                    $weight = round((float) ($point?->weight ?? 1), 4);
                    $weighted = round($points * $weight, 4);

                    $eligibility = $hoursEligibility[$employeeId] ?? [
                        'workedHours' => 0.0,
                        'expectedHours' => 0.0,
                        'bankHoursApplied' => 0.0,
                        'isEligible' => true,
                        'reason' => null,
                    ];

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
                        );
                        continue;
                    }

                    $eligibleParticipants[] = [
                        'employeeId' => $employeeId,
                        'points' => $points,
                        'weight' => $weight,
                        'weighted' => $weighted,
                        'eligibility' => $eligibility,
                    ];
                }

                $denominator = round(collect($eligibleParticipants)->sum('weighted'), 4);
                if ($denominator <= 0) {
                    continue;
                }

                $allocated = 0.0;
                $lastIndex = count($eligibleParticipants) - 1;
                foreach ($eligibleParticipants as $index => $participant) {
                    $ratio = round($participant['weighted'] / $denominator, 8);
                    if ($index === $lastIndex) {
                        $amount = round($poolAmount - $allocated, 2);
                    } else {
                        $amount = round($poolAmount * $ratio, 2);
                        $allocated = round($allocated + $amount, 2);
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
                    );
                }
            }

            return [
                'totals' => $this->totalsForRun($payrollRun),
                'distributions' => $distributions,
                'hoursEligibility' => $hoursEligibility,
            ];
        });
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
     * @param array<string, mixed> $eligibility
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
    ): array {
        $row = PayrollRunPoolDistribution::create([
            'id' => (string) Str::uuid(),
            'payroll_run_id' => $payrollRunId,
            'pool_distribution_type_id' => $typeId,
            'employee_id' => $employeeId,
            'points' => $points,
            'weight' => $weight,
            'weighted_points' => $weighted,
            'share_ratio' => $ratio,
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
            'employeeId' => $employeeId,
            'points' => round($points, 4),
            'weight' => round($weight, 4),
            'weightedPoints' => round($weighted, 4),
            'shareRatio' => round($ratio, 8),
            'amount' => round($amount, 2),
            'isEligible' => $isEligible,
            'eligibilityReason' => $reason,
            'workedHours' => isset($eligibility['workedHours']) ? round((float) $eligibility['workedHours'], 4) : null,
            'expectedHours' => isset($eligibility['expectedHours']) ? round((float) $eligibility['expectedHours'], 4) : null,
            'bankHoursApplied' => isset($eligibility['bankHoursApplied']) ? round((float) $eligibility['bankHoursApplied'], 4) : null,
        ];
    }
}
