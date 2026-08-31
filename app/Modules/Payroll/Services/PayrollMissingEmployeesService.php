<?php

namespace App\Modules\Payroll\Services;

use App\Enums\CompensationMethod;
use App\Enums\LeaveStatusCode;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeDayWork;
use App\Models\EmployeeLeave;
use App\Models\EmploymentDetail;
use App\Modules\Hr\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PayrollMissingEmployeesService
{
    public function __construct(
        private readonly PayrollTimesheetScopeService $payrollTimesheetScopeService,
        private readonly PayrollRunFrequencyResolver $payrollRunFrequencyResolver,
    ) {
    }

    /**
     * @return array{
     *   expectedEmployeeCount: int,
     *   missingEmployeeCount: int,
     *   missingEmployees: list<array<string, mixed>>
     * }
     */
    public function resolve(
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $frequencyId = null,
    ): array {
        if ($payPeriodGroupId === '') {
            return $this->emptyResult();
        }

        $expected = $this->loadExpectedEmployees($startDate, $endDate, $payPeriodGroupId, $frequencyId);
        $presentIds = $this->loadPresentEmployeeIds($startDate, $endDate, $payPeriodGroupId, $frequencyId);
        $leavesByEmployee = $this->loadLeavesByEmployee($startDate, $endDate, array_keys($expected));

        $missingEmployees = [];

        foreach ($expected as $employeeId => $context) {
            if ($presentIds->contains($employeeId)) {
                continue;
            }

            $missingEmployees[] = $this->buildMissingEmployeeRow(
                $employeeId,
                $context,
                $leavesByEmployee->get($employeeId, collect()),
                $startDate,
                $endDate,
            );
        }

        usort(
            $missingEmployees,
            fn (array $left, array $right) => strcasecmp(
                (string) ($left['employeeName'] ?? ''),
                (string) ($right['employeeName'] ?? ''),
            ),
        );

        return [
            'expectedEmployeeCount' => count($expected),
            'missingEmployeeCount' => count($missingEmployees),
            'missingEmployees' => $missingEmployees,
        ];
    }

    public static function leaveIsUnpaid(EmployeeLeave $leave): bool
    {
        if ((float) ($leave->multiplier ?? 1) <= 0) {
            return true;
        }

        return $leave->leaveType !== null && ! (bool) $leave->leaveType->isPaid;
    }

    /**
     * @return array{expectedEmployeeCount: int, missingEmployeeCount: int, missingEmployees: list<array<string, mixed>>}
     */
    private function emptyResult(): array
    {
        return [
            'expectedEmployeeCount' => 0,
            'missingEmployeeCount' => 0,
            'missingEmployees' => [],
        ];
    }

    /**
     * @return array<string, array{employmentDetail: EmploymentDetail, compensation: EmployeeCompensation|null}>
     */
    private function loadExpectedEmployees(
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $frequencyId,
    ): array {
        $rows = EmploymentDetail::query()
            ->with([
                'employee.person',
                'employee.employeeStatus',
                'department',
                'jobTitle',
                'contractType',
                'defaultPayPeriodGroup',
                'employee.employeeCompensations' => function ($query) use ($startDate, $endDate) {
                    $query
                        ->where('isActive', true)
                        ->whereDate('effectiveDate', '<=', $endDate->toDateString())
                        ->where(function ($inner) use ($startDate) {
                            $inner->whereNull('endDate')
                                ->orWhereDate('endDate', '>=', $startDate->toDateString());
                        })
                        ->orderByDesc('effectiveDate');
                },
            ])
            ->where('isActive', true)
            ->where('defaultPayPeriodGroupId', $payPeriodGroupId)
            ->whereDate('startDate', '<=', $endDate->toDateString())
            ->where(function ($query) use ($startDate) {
                $query->whereNull('endDate')
                    ->orWhereDate('endDate', '>=', $startDate->toDateString());
            })
            ->whereHas('employee', function ($employeeQuery) use ($frequencyId) {
                $employeeQuery->whereHas('employeeStatus', function ($statusQuery) {
                    $statusQuery->whereRaw('LOWER(name) = ?', ['active']);
                });

                if ($frequencyId !== null) {
                    $employeeQuery->where('payrateFrequencyId', $frequencyId);
                }
            })
            ->whereHas('employee.employeeCompensations', function ($compensationQuery) use ($startDate, $endDate) {
                $compensationQuery
                    ->where('isActive', true)
                    ->whereDate('effectiveDate', '<=', $endDate->toDateString())
                    ->where(function ($inner) use ($startDate) {
                        $inner->whereNull('endDate')
                            ->orWhereDate('endDate', '>=', $startDate->toDateString());
                    });
            })
            ->orderByDesc('startDate')
            ->get();

        $expected = [];

        foreach ($rows as $employmentDetail) {
            $employeeId = (string) $employmentDetail->employeeId;

            if (isset($expected[$employeeId])) {
                continue;
            }

            $compensation = $employmentDetail->employee?->employeeCompensations?->first();

            $expected[$employeeId] = [
                'employmentDetail' => $employmentDetail,
                'compensation' => $compensation instanceof EmployeeCompensation ? $compensation : null,
            ];
        }

        return $expected;
    }

    /**
     * @return Collection<int, string>
     */
    private function loadPresentEmployeeIds(
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $frequencyId,
    ): Collection {
        $fromTimesheets = collect(
            $this->payrollTimesheetScopeService->employeeIds(
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $frequencyId,
            ),
        );

        $dayWorkQuery = EmployeeDayWork::query()
            ->whereDate('date', '>=', $startDate->toDateString())
            ->whereDate('date', '<=', $endDate->toDateString())
            ->whereHas('employmentDetail', function ($employmentQuery) use ($payPeriodGroupId, $startDate, $endDate) {
                $employmentQuery
                    ->where('defaultPayPeriodGroupId', $payPeriodGroupId)
                    ->where('isActive', true)
                    ->whereDate('startDate', '<=', $endDate->toDateString())
                    ->where(function ($inner) use ($startDate) {
                        $inner->whereNull('endDate')
                            ->orWhereDate('endDate', '>=', $startDate->toDateString());
                    });
            });

        if ($frequencyId !== null) {
            $dayWorkQuery->whereHas('employee', function ($employeeQuery) use ($frequencyId) {
                $employeeQuery->where('payrateFrequencyId', $frequencyId);
            });
        }

        $fromDayWork = $dayWorkQuery
            ->distinct()
            ->pluck('employeeId')
            ->map(fn ($id) => (string) $id);

        return $fromTimesheets
            ->merge($fromDayWork)
            ->unique()
            ->values();
    }

    /**
     * @param list<string> $employeeIds
     * @return Collection<string, Collection<int, EmployeeLeave>>
     */
    private function loadLeavesByEmployee(
        Carbon $startDate,
        Carbon $endDate,
        array $employeeIds,
    ): Collection {
        if ($employeeIds === []) {
            return collect();
        }

        return EmployeeLeave::query()
            ->with('leaveType')
            ->whereIn('employeeId', $employeeIds)
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::activeAbsenceStatuses(),
            )))
            ->whereDate('startDate', '<=', $endDate->toDateString())
            ->whereDate('endDate', '>=', $startDate->toDateString())
            ->get()
            ->groupBy(fn (EmployeeLeave $leave) => (string) $leave->employeeId);
    }

    /**
     * @param array{employmentDetail: EmploymentDetail, compensation: EmployeeCompensation|null} $context
     * @param Collection<int, EmployeeLeave> $leaves
     * @return array<string, mixed>
     */
    private function buildMissingEmployeeRow(
        string $employeeId,
        array $context,
        Collection $leaves,
        Carbon $startDate,
        Carbon $endDate,
    ): array {
        $employmentDetail = $context['employmentDetail'];
        $compensation = $context['compensation'];
        $employee = $employmentDetail->employee;
        $payType = $compensation
            ? CompensationMethod::fromStored($compensation->compensationMethod)->storedPayType()
            : null;
        $isBaseRate = $compensation
            ? CompensationMethod::fromStored($compensation->compensationMethod)->isBaseBased()
            : false;
        $isDailyRate = $compensation
            ? CompensationMethod::fromStored($compensation->compensationMethod)->isDailyRateBased()
            : false;

        $leaveSummary = $this->summarizeLeave($leaves, $startDate, $endDate);
        $status = $this->resolveMissingStatus($leaveSummary, $isBaseRate, $isDailyRate);

        return [
            'employeeId' => $employeeId,
            'employmentDetailId' => (string) $employmentDetail->id,
            'employeeCode' => $employee?->code,
            'employeeName' => $this->employeeDisplayName($employee),
            'departmentId' => $employmentDetail->departmentId,
            'departmentName' => $employmentDetail->department?->name,
            'employmentContractLabel' => $this->employmentContractLabel($employmentDetail),
            'payType' => $payType ? strtoupper($payType) : null,
            'isBaseRate' => $isBaseRate,
            'status' => $status,
            'reason' => $this->reasonForStatus($status, $leaveSummary, $isBaseRate, $isDailyRate),
            'leaveDaysInPeriod' => $leaveSummary['leaveDaysInPeriod'],
            'unpaidLeaveDaysInPeriod' => $leaveSummary['unpaidLeaveDaysInPeriod'],
            'paidLeaveDaysInPeriod' => $leaveSummary['paidLeaveDaysInPeriod'],
            'leaveTypes' => $leaveSummary['leaveTypeNames'],
            'onLeaveWithoutPay' => $leaveSummary['hasUnpaidLeave'] && ! $leaveSummary['hasPaidLeave'],
            'onPaidLeave' => $leaveSummary['hasPaidLeave'],
        ];
    }

    /**
     * @param Collection<int, EmployeeLeave> $leaves
     * @return array{
     *   leaveDaysInPeriod: int,
     *   unpaidLeaveDaysInPeriod: int,
     *   paidLeaveDaysInPeriod: int,
     *   hasUnpaidLeave: bool,
     *   hasPaidLeave: bool,
     *   leaveTypeNames: list<string>
     * }
     */
    private function summarizeLeave(Collection $leaves, Carbon $startDate, Carbon $endDate): array
    {
        $leaveDates = [];
        $unpaidDates = [];
        $paidDates = [];
        $leaveTypeNames = [];

        foreach ($leaves as $leave) {
            $leaveStart = Carbon::parse($leave->startDate)->max($startDate)->startOfDay();
            $leaveEnd = Carbon::parse($leave->endDate)->min($endDate)->startOfDay();
            $isUnpaid = self::leaveIsUnpaid($leave);
            $typeName = trim((string) ($leave->leaveType?->name ?? ''));

            if ($typeName !== '' && ! in_array($typeName, $leaveTypeNames, true)) {
                $leaveTypeNames[] = $typeName;
            }

            $cursor = $leaveStart->copy();
            while ($cursor->lte($leaveEnd)) {
                $dateKey = $cursor->toDateString();
                $leaveDates[$dateKey] = true;

                if ($isUnpaid) {
                    $unpaidDates[$dateKey] = true;
                } else {
                    $paidDates[$dateKey] = true;
                }

                $cursor->addDay();
            }
        }

        return [
            'leaveDaysInPeriod' => count($leaveDates),
            'unpaidLeaveDaysInPeriod' => count($unpaidDates),
            'paidLeaveDaysInPeriod' => count($paidDates),
            'hasUnpaidLeave' => $unpaidDates !== [],
            'hasPaidLeave' => $paidDates !== [],
            'leaveTypeNames' => $leaveTypeNames,
        ];
    }

    /**
     * @param array{
     *   leaveDaysInPeriod: int,
     *   unpaidLeaveDaysInPeriod: int,
     *   paidLeaveDaysInPeriod: int,
     *   hasUnpaidLeave: bool,
     *   hasPaidLeave: bool,
     *   leaveTypeNames: list<string>
     * } $leaveSummary
     */
    private function resolveMissingStatus(array $leaveSummary, bool $isBaseRate, bool $isDailyRate): string
    {
        if ($leaveSummary['hasUnpaidLeave'] && ! $leaveSummary['hasPaidLeave']) {
            return 'leave_without_pay';
        }

        if ($leaveSummary['hasPaidLeave'] && $isBaseRate) {
            return 'paid_leave_missing_timesheets';
        }

        if ($isDailyRate) {
            return 'daily_rate_missing_entries';
        }

        if ($leaveSummary['hasPaidLeave']) {
            return 'paid_leave_missing_timesheets';
        }

        return 'missing_timesheets';
    }

    /**
     * @param array{
     *   leaveDaysInPeriod: int,
     *   unpaidLeaveDaysInPeriod: int,
     *   paidLeaveDaysInPeriod: int,
     *   hasUnpaidLeave: bool,
     *   hasPaidLeave: bool,
     *   leaveTypeNames: list<string>
     * } $leaveSummary
     */
    private function reasonForStatus(
        string $status,
        array $leaveSummary,
        bool $isBaseRate,
        bool $isDailyRate,
    ): string {
        $leaveLabel = $leaveSummary['leaveTypeNames'] !== []
            ? implode(', ', $leaveSummary['leaveTypeNames'])
            : 'leave';

        return match ($status) {
            'leave_without_pay' => sprintf(
                'On leave without pay (%d day%s) — should still appear on payroll with unpaid hours noted.',
                $leaveSummary['unpaidLeaveDaysInPeriod'],
                $leaveSummary['unpaidLeaveDaysInPeriod'] === 1 ? '' : 's',
            ),
            'paid_leave_missing_timesheets' => $isBaseRate
                ? sprintf(
                    'On paid %s (%d day%s) — base-rate employee should have scheduled leave timesheets for this period.',
                    $leaveLabel,
                    $leaveSummary['paidLeaveDaysInPeriod'],
                    $leaveSummary['paidLeaveDaysInPeriod'] === 1 ? '' : 's',
                )
                : sprintf(
                    'On paid %s (%d day%s) — employee should have timesheets for this period.',
                    $leaveLabel,
                    $leaveSummary['paidLeaveDaysInPeriod'],
                    $leaveSummary['paidLeaveDaysInPeriod'] === 1 ? '' : 's',
                ),
            'daily_rate_missing_entries' => 'Daily-rate employee has no day work entries or timesheets for this pay period.',
            default => 'Active employee assigned to this pay period group has no timesheets or day work for this period.',
        };
    }

    private function employeeDisplayName(?Employee $employee): ?string
    {
        if (! $employee) {
            return null;
        }

        $name = trim(sprintf(
            '%s %s',
            $employee->person?->firstName ?? '',
            $employee->person?->lastName ?? '',
        ));

        return $name !== '' ? $name : null;
    }

    private function employmentContractLabel(EmploymentDetail $employmentDetail): string
    {
        $title = $employmentDetail->jobTitle?->name
            ?: $employmentDetail->contractType?->name
            ?: 'Contract';
        $department = $employmentDetail->department?->name ?: 'No department';
        $payPeriodGroup = $employmentDetail->defaultPayPeriodGroup?->name ?: 'No pay period group';

        return "{$title} - {$department} - {$payPeriodGroup}";
    }

    public function resolveFrequencyId(string $payPeriodGroupId): ?int
    {
        $groupName = (string) (\App\Models\PayPeriodGroup::query()
            ->whereKey($payPeriodGroupId)
            ->value('name') ?? '');

        return $this->payrollRunFrequencyResolver->resolveFromGroupName($groupName);
    }
}
