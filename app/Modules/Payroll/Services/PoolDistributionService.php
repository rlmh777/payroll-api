<?php

namespace App\Modules\Payroll\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePoolPoint;
use App\Models\EmploymentDetail;
use App\Models\PayrollRun;
use App\Models\PayrollRunPoolDistribution;
use App\Models\PayrollRunPoolTotal;
use App\Models\PoolDistributionType;
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
            ->with(['payrollEarningCode', 'departmentShares.department'])
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

            $distributions = [];
            $departmentBreakdown = [];

            foreach ($totals as $total) {
                $type = $total->poolDistributionType;
                if (!$type || !$type->isDistributable()) {
                    continue;
                }

                $poolAmount = round((float) $total->total_amount, 2);
                if ($poolAmount <= 0) {
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

                $pointRows = $this->currentPointsForEmployees($employeeIds, (int) $type->id, $asOfDate);
                $eligibleParticipants = [];

                foreach ($employeeIds as $employeeId) {
                    $employeeId = (string) $employeeId;
                    $point = $pointRows->get($employeeId);
                    $points = round((float) ($point?->points ?? 0), 4);
                    $weight = round((float) ($point?->weight ?? 1), 4);
                    $weighted = round($points * $weight, 4);
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
                'departmentBreakdown' => $departmentBreakdown,
                'hoursEligibility' => $hoursEligibility,
            ];
        });
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
            $departmentAmount = round($poolAmount * ($percent / 100), 2);
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
            $shareEach = $paidCount > 0 ? round($departmentAmount / $paidCount, 2) : 0.0;
            $allocated = 0.0;
            $lastPaidIndex = $paidCount > 0 ? $paidCount - 1 : -1;
            $paidIndex = 0;

            foreach ($listed as $member) {
                $amount = 0.0;
                $ratio = 0.0;
                if ($member['paid'] && $paidCount > 0) {
                    if ($paidIndex === $lastPaidIndex) {
                        $amount = round($departmentAmount - $allocated, 2);
                    } else {
                        $amount = $shareEach;
                        $allocated = round($allocated + $amount, 2);
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
            'department_amount' => $extra['departmentAmount'] ?? null,
            'worked_this_period' => $extra['workedThisPeriod'] ?? null,
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
            'poolName' => $extra['poolName'] ?? null,
            'employeeId' => $employeeId,
            'employeeName' => $extra['employeeName'] ?? null,
            'employeeCode' => $extra['employeeCode'] ?? null,
            'departmentId' => $extra['departmentId'] ?? null,
            'departmentName' => $extra['departmentName'] ?? null,
            'departmentPercent' => $extra['departmentPercent'] ?? null,
            'departmentAmount' => $extra['departmentAmount'] ?? null,
            'workedThisPeriod' => $extra['workedThisPeriod'] ?? null,
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
