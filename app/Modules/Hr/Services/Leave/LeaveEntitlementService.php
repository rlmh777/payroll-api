<?php

namespace App\Modules\Hr\Services\Leave;

use App\Enums\LeaveAccrualMethod;
use App\Enums\LeaveStatusCode;
use App\Models\EmployeeLeave;
use App\Models\EmploymentDetail;
use App\Models\EmploymentLeaveEntitlement;
use App\Models\LeaveType;
use App\Models\LeaveTypePolicy;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LeaveEntitlementService
{
    public function __construct(
        private readonly LeaveWorkflowService $workflowService,
    ) {
    }

    /**
     * @return array{
     *     leaveTypeId: int,
     *     code: string|null,
     *     name: string,
     *     isPaid: bool,
     *     affectsBalance: bool,
     *     requiresCertification: bool,
     *     annualEntitlementDays: float,
     *     accrualMethod: string,
     *     accruedDays: float,
     *     takenDays: float,
     *     scheduledDays: float,
     *     availableDays: float,
     *     policySource: string,
     *     periodStart: string,
     *     periodEnd: string,
     * }
     */
    public function balanceForType(
        string $employeeId,
        LeaveType $leaveType,
        ?EmploymentDetail $contract = null,
        ?Carbon $asOf = null,
    ): array {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $contract = $contract ?? $this->activeContract($employeeId, $asOf);
        $policy = $this->resolvePolicy($leaveType, $contract);
        $period = $this->policyPeriod($contract, $asOf);

        $accrued = $this->accruedDays(
            $policy['annualEntitlementDays'],
            $policy['accrualMethod'],
            $contract,
            $period['start'],
            $asOf,
        );

        $taken = $this->sumLeaveDaysByStatusCodes(
            $employeeId,
            $leaveType->id,
            $period['start'],
            $period['end'],
            LeaveStatusCode::takenBalanceStatuses(),
        );
        $scheduled = $this->sumLeaveDaysByStatusCodes(
            $employeeId,
            $leaveType->id,
            $period['start'],
            $period['end'],
            LeaveStatusCode::scheduledBalanceStatuses(),
        );

        $available = $leaveType->affectsBalance
            ? max(0, round($accrued - $taken - $scheduled, 2))
            : 0.0;

        return [
            'leaveTypeId' => (int) $leaveType->id,
            'code' => $leaveType->code,
            'name' => $leaveType->name,
            'isPaid' => (bool) $leaveType->isPaid,
            'affectsBalance' => (bool) $leaveType->affectsBalance,
            'requiresCertification' => (bool) $leaveType->requiresCertification,
            'annualEntitlementDays' => $policy['annualEntitlementDays'],
            'accrualMethod' => $policy['accrualMethod']->value,
            'accruedDays' => $accrued,
            'takenDays' => $taken,
            'scheduledDays' => $scheduled,
            'availableDays' => $available,
            'policySource' => $policy['source'],
            'periodStart' => $period['start']->toDateString(),
            'periodEnd' => $period['end']->toDateString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function balancesForEmployee(string $employeeId, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $this->workflowService->syncScheduledToTaken($employeeId);
        $contract = $this->activeContract($employeeId, $asOf);

        $leaveTypes = LeaveType::query()
            ->with('policy')
            ->where('isActive', true)
            ->orderBy('sortOrder')
            ->orderBy('name')
            ->get();

        return $leaveTypes
            ->map(fn (LeaveType $type) => $this->balanceForType($employeeId, $type, $contract, $asOf))
            ->values()
            ->all();
    }

    public function assertSufficientBalance(
        string $employeeId,
        int $leaveTypeId,
        float $requestedDays,
        ?Carbon $asOf = null,
        ?int $excludeLeaveId = null,
    ): ?string {
        $leaveType = LeaveType::query()->find($leaveTypeId);
        if (!$leaveType || !$leaveType->affectsBalance) {
            return null;
        }

        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $balance = $this->balanceForType($employeeId, $leaveType, null, $asOf);

        $scheduled = $balance['scheduledDays'];
        if ($excludeLeaveId) {
            $scheduled -= $this->leaveDaysForRecord($excludeLeaveId);
        }

        $available = max(0, $balance['accruedDays'] - $balance['takenDays'] - $scheduled);

        if ($requestedDays > $available + 0.001) {
            return sprintf(
                'Insufficient %s balance. Available: %.2f day(s), requested: %.2f day(s).',
                $leaveType->name,
                $available,
                $requestedDays,
            );
        }

        return null;
    }

    /**
     * @return array{
     *     annualEntitlementDays: float,
     *     accrualMethod: LeaveAccrualMethod,
     *     source: string,
     * }
     */
    public function resolvePolicy(LeaveType $leaveType, ?EmploymentDetail $contract): array
    {
        $orgPolicy = LeaveTypePolicy::query()
            ->where('leaveTypeId', $leaveType->id)
            ->where('isEnabled', true)
            ->first();

        $annual = (float) ($orgPolicy?->annualEntitlementDays ?? 0);
        $method = $orgPolicy?->accrualMethodEnum() ?? LeaveAccrualMethod::None;
        $source = 'organization';

        if ($contract) {
            $override = EmploymentLeaveEntitlement::query()
                ->where('employmentDetailId', $contract->id)
                ->where('leaveTypeId', $leaveType->id)
                ->first();

            if ($override) {
                if ($override->annualEntitlementDays !== null) {
                    $annual = (float) $override->annualEntitlementDays;
                    $source = 'contract';
                }
                if ($override->accrualMethod !== null) {
                    $method = $override->accrualMethodEnum() ?? $method;
                    $source = 'contract';
                }
            }
        }

        return [
            'annualEntitlementDays' => round($annual, 2),
            'accrualMethod' => $method,
            'source' => $source,
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public function policyPeriod(?EmploymentDetail $contract, Carbon $asOf): array
    {
        if (!$contract) {
            $start = $asOf->copy()->startOfYear();
            return [
                'start' => $start,
                'end' => $start->copy()->endOfYear()->startOfDay(),
            ];
        }

        $contractStart = Carbon::parse($contract->startDate)->startOfDay();
        $periodStart = $contractStart->copy();

        while ($periodStart->copy()->addYear()->lte($asOf)) {
            $periodStart->addYear();
        }

        return [
            'start' => $periodStart,
            'end' => $periodStart->copy()->addYear()->subDay()->startOfDay(),
        ];
    }

    public function accruedDays(
        float $annualEntitlement,
        LeaveAccrualMethod $method,
        ?EmploymentDetail $contract,
        Carbon $periodStart,
        Carbon $asOf,
    ): float {
        if ($annualEntitlement <= 0 || $method === LeaveAccrualMethod::None) {
            return 0.0;
        }

        $accrualStart = $contract
            ? Carbon::parse($contract->startDate)->startOfDay()->max($periodStart)
            : $periodStart->copy();

        if ($asOf->lt($accrualStart)) {
            return 0.0;
        }

        if ($method === LeaveAccrualMethod::Upfront) {
            return round($annualEntitlement, 2);
        }

        $monthsElapsed = $this->monthsElapsedInclusive($accrualStart, $asOf);
        $accrued = ($annualEntitlement / 12) * $monthsElapsed;

        return round(min($annualEntitlement, $accrued), 2);
    }

    private function monthsElapsedInclusive(Carbon $start, Carbon $asOf): float
    {
        if ($asOf->lt($start)) {
            return 0.0;
        }

        $months = ($asOf->year - $start->year) * 12 + ($asOf->month - $start->month);

        if ($asOf->day >= $start->day) {
            $months += 1;
        }

        return max(0, $months);
    }

    /**
     * @param array<int, LeaveStatusCode> $statusCodes
     */
    private function sumLeaveDaysByStatusCodes(
        string $employeeId,
        int $leaveTypeId,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $statusCodes,
    ): float {
        $codes = array_map(fn (LeaveStatusCode $code) => $code->value, $statusCodes);

        return (float) EmployeeLeave::query()
            ->where('employeeId', $employeeId)
            ->where('leaveTypeId', $leaveTypeId)
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', $codes))
            ->where('startDate', '>=', $periodStart->toDateString())
            ->where('startDate', '<=', $periodEnd->toDateString())
            ->sum('totalDays');
    }

    private function leaveDaysForRecord(int $leaveId): float
    {
        $leave = EmployeeLeave::query()->find($leaveId);

        return $leave ? (float) $leave->totalDays : 0.0;
    }

    private function activeContract(string $employeeId, Carbon $asOf): ?EmploymentDetail
    {
        return EmploymentDetail::query()
            ->where('employeeId', $employeeId)
            ->where('isActive', true)
            ->where('startDate', '<=', $asOf->toDateString())
            ->where(function ($query) use ($asOf) {
                $query->whereNull('endDate')
                    ->orWhere('endDate', '>=', $asOf->toDateString());
            })
            ->orderByDesc('startDate')
            ->first();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function contractEntitlementRows(EmploymentDetail $contract): Collection
    {
        $overrides = EmploymentLeaveEntitlement::query()
            ->where('employmentDetailId', $contract->id)
            ->get()
            ->keyBy('leaveTypeId');

        return LeaveType::query()
            ->with('policy')
            ->where('isActive', true)
            ->orderBy('sortOrder')
            ->orderBy('name')
            ->get()
            ->map(function (LeaveType $type) use ($contract, $overrides) {
                $org = $type->policy;
                $override = $overrides->get($type->id);
                $resolved = $this->resolvePolicy($type, $contract);

                return [
                    'leaveTypeId' => (int) $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'isPaid' => (bool) $type->isPaid,
                    'affectsBalance' => (bool) $type->affectsBalance,
                    'orgAnnualEntitlementDays' => (float) ($org?->annualEntitlementDays ?? 0),
                    'orgAccrualMethod' => $org?->accrualMethod ?? LeaveAccrualMethod::None->value,
                    'annualEntitlementDays' => $override?->annualEntitlementDays,
                    'accrualMethod' => $override?->accrualMethod,
                    'resolvedAnnualEntitlementDays' => $resolved['annualEntitlementDays'],
                    'resolvedAccrualMethod' => $resolved['accrualMethod']->value,
                    'usesContractOverride' => $override !== null
                        && ($override->annualEntitlementDays !== null || $override->accrualMethod !== null),
                ];
            });
    }
}
