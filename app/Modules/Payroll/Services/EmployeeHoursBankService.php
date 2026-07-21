<?php

namespace App\Modules\Payroll\Services;

use App\Enums\CompensationMethod;
use App\Models\EmployeeCompensation;
use App\Models\EmployeeHoursBank;
use App\Models\EmployeeHoursBankLedger;
use App\Models\PayrollRun;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Employment\EmployeeCompensationResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeHoursBankService
{
    public function __construct(
        private readonly EmployeeCompensationResolver $compensationResolver,
        private readonly PayrollTimesheetScopeService $payrollTimesheetScopeService,
    ) {
    }

    public function getOrCreateBank(string $employeeId): EmployeeHoursBank
    {
        $bank = EmployeeHoursBank::query()->where('employee_id', $employeeId)->first();
        if ($bank) {
            return $bank;
        }

        return EmployeeHoursBank::create([
            'id' => (string) Str::uuid(),
            'employee_id' => $employeeId,
            'balance_hours' => 0,
        ]);
    }

    public function balance(string $employeeId): float
    {
        return round((float) $this->getOrCreateBank($employeeId)->balance_hours, 4);
    }

    /**
     * @return array{
     *     balanceHours: float,
     *     ledger: list<array<string, mixed>>
     * }
     */
    public function summary(string $employeeId, int $ledgerLimit = 50): array
    {
        $bank = $this->getOrCreateBank($employeeId);
        $ledger = EmployeeHoursBankLedger::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->limit($ledgerLimit)
            ->get()
            ->map(fn (EmployeeHoursBankLedger $entry) => [
                'id' => $entry->id,
                'entryDate' => optional($entry->entry_date)->format('Y-m-d'),
                'weekStartDate' => optional($entry->week_start_date)->format('Y-m-d'),
                'payrollRunId' => $entry->payroll_run_id,
                'employeeLeaveId' => $entry->employee_leave_id,
                'entryType' => $entry->entry_type,
                'hoursDelta' => round((float) $entry->hours_delta, 4),
                'balanceAfter' => round((float) $entry->balance_after, 4),
                'expectedHours' => $entry->expected_hours !== null ? round((float) $entry->expected_hours, 4) : null,
                'workedHours' => $entry->worked_hours !== null ? round((float) $entry->worked_hours, 4) : null,
                'notes' => $entry->notes,
            ])
            ->values()
            ->all();

        return [
            'balanceHours' => round((float) $bank->balance_hours, 4),
            'ledger' => $ledger,
        ];
    }

    /**
     * Apply accrued worked hours against leave. Returns hours applied from bank.
     */
    public function applyToLeave(
        string $employeeId,
        string $employeeLeaveId,
        float $leaveHours,
        Carbon $entryDate,
        ?string $notes = null,
    ): array {
        $leaveHours = round(max(0, $leaveHours), 4);
        if ($leaveHours <= 0) {
            return [
                'appliedHours' => 0.0,
                'deficitHours' => 0.0,
                'balanceHours' => $this->balance($employeeId),
            ];
        }

        return DB::transaction(function () use ($employeeId, $employeeLeaveId, $leaveHours, $entryDate, $notes) {
            $bank = $this->getOrCreateBank($employeeId);
            $available = round(max(0, (float) $bank->balance_hours), 4);
            $applied = round(min($available, $leaveHours), 4);
            $deficit = round(max(0, $leaveHours - $applied), 4);

            if ($applied > 0) {
                $this->postLedgerEntry(
                    $bank,
                    $entryDate,
                    EmployeeHoursBankLedger::TYPE_LEAVE_APPLY,
                    -1 * $applied,
                    null,
                    null,
                    $employeeLeaveId,
                    null,
                    null,
                    $notes ?? 'Applied accrued worked hours to leave',
                );
            }

            if ($deficit > 0) {
                $this->postLedgerEntry(
                    $bank,
                    $entryDate,
                    EmployeeHoursBankLedger::TYPE_LEAVE_DEFICIT,
                    -1 * $deficit,
                    null,
                    null,
                    $employeeLeaveId,
                    null,
                    null,
                    $notes ?? 'Leave hours beyond accrued worked hours',
                );
            }

            return [
                'appliedHours' => $applied,
                'deficitHours' => $deficit,
                'balanceHours' => round((float) $bank->fresh()->balance_hours, 4),
            ];
        });
    }

    public function adjust(
        string $employeeId,
        float $hoursDelta,
        Carbon $entryDate,
        ?string $notes = null,
    ): EmployeeHoursBank {
        return DB::transaction(function () use ($employeeId, $hoursDelta, $entryDate, $notes) {
            $bank = $this->getOrCreateBank($employeeId);
            $this->postLedgerEntry(
                $bank,
                $entryDate,
                EmployeeHoursBankLedger::TYPE_ADJUSTMENT,
                round($hoursDelta, 4),
                null,
                null,
                null,
                null,
                null,
                $notes ?? 'Manual hours bank adjustment',
            );

            return $bank->fresh();
        });
    }

    /**
     * Sync weekly expected vs worked hours for a payroll run.
     * Surplus accrues; shortfall consumes bank then tracks deficit.
     *
     * @param list<string> $employeeIds
     * @return array<string, array{
     *     workedHours: float,
     *     expectedHours: float,
     *     bankHoursApplied: float,
     *     isEligible: bool,
     *     reason: string|null,
     *     balanceHours: float
     * }>
     */
    public function syncForPayrollRun(PayrollRun $payrollRun, array $employeeIds): array
    {
        $payrollRun->loadMissing(['payPeriodSchedule']);
        $schedule = $payrollRun->payPeriodSchedule;
        if (!$schedule?->start_date || !$schedule?->end_date) {
            return [];
        }

        $startDate = Carbon::parse($schedule->start_date)->startOfDay();
        $endDate = Carbon::parse($schedule->end_date)->startOfDay();
        $payPeriodGroupId = (string) $schedule->pay_period_group_id;

        // Clear prior sync for this run so re-distribution is idempotent.
        $this->revertPayrollRunSync($payrollRun->id, $employeeIds);

        $results = [];
        foreach ($employeeIds as $employeeId) {
            $results[(string) $employeeId] = $this->syncEmployeePeriod(
                (string) $employeeId,
                $payrollRun,
                $startDate,
                $endDate,
                $payPeriodGroupId,
            );
        }

        return $results;
    }

    /**
     * @return array{
     *     workedHours: float,
     *     expectedHours: float,
     *     bankHoursApplied: float,
     *     isEligible: bool,
     *     reason: string|null,
     *     balanceHours: float
     * }
     */
    private function syncEmployeePeriod(
        string $employeeId,
        PayrollRun $payrollRun,
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
    ): array {
        $compensation = $this->compensationResolver->compensationForDate(
            EmployeeCompensation::query()->where('employeeId', $employeeId)->get(),
            $endDate,
        );
        $method = $this->compensationResolver->method($compensation);
        $isBaseRate = in_array($method, [CompensationMethod::BaseNoOt, CompensationMethod::BaseOt], true);
        $standardWeeklyHours = $this->compensationResolver->standardWeeklyHours($compensation);

        $workedByWeek = $this->workedHoursByWeek(
            $employeeId,
            $startDate,
            $endDate,
            $payPeriodGroupId,
            $payrollRun->payrate_frequency_id,
        );

        $weeks = $this->weekStartsInRange($startDate, $endDate);
        $totalWorked = 0.0;
        $totalExpected = 0.0;
        $totalBankApplied = 0.0;
        $eligibleWeeks = 0;
        $weekCount = max(1, count($weeks));

        return DB::transaction(function () use (
            $employeeId,
            $payrollRun,
            $isBaseRate,
            $standardWeeklyHours,
            $workedByWeek,
            $weeks,
            $weekCount,
            &$totalWorked,
            &$totalExpected,
            &$totalBankApplied,
            &$eligibleWeeks,
        ) {
            $bank = $this->getOrCreateBank($employeeId);

            if (!$isBaseRate || $standardWeeklyHours <= 0) {
                foreach ($weeks as $weekStart) {
                    $key = $weekStart->format('Y-m-d');
                    $worked = round((float) ($workedByWeek[$key] ?? 0), 4);
                    $totalWorked = round($totalWorked + $worked, 4);
                }

                return [
                    'workedHours' => $totalWorked,
                    'expectedHours' => 0.0,
                    'bankHoursApplied' => 0.0,
                    'isEligible' => true,
                    'reason' => null,
                    'balanceHours' => round((float) $bank->balance_hours, 4),
                ];
            }

            foreach ($weeks as $weekStart) {
                $key = $weekStart->format('Y-m-d');
                $worked = round((float) ($workedByWeek[$key] ?? 0), 4);
                $expected = round($standardWeeklyHours, 4);
                $totalWorked = round($totalWorked + $worked, 4);
                $totalExpected = round($totalExpected + $expected, 4);

                $delta = round($worked - $expected, 4);
                if ($delta >= 0) {
                    if ($delta > 0) {
                        $this->postLedgerEntry(
                            $bank,
                            $weekStart->copy()->endOfWeek(Carbon::SUNDAY),
                            EmployeeHoursBankLedger::TYPE_ACCRUAL,
                            $delta,
                            $weekStart,
                            $payrollRun->id,
                            null,
                            $expected,
                            $worked,
                            'Surplus hours accrued (not used for pool calc this week)',
                        );
                    }
                    $eligibleWeeks++;
                    continue;
                }

                $shortfall = round(abs($delta), 4);
                $available = round(max(0, (float) $bank->balance_hours), 4);
                $applied = round(min($available, $shortfall), 4);
                $remaining = round($shortfall - $applied, 4);
                $totalBankApplied = round($totalBankApplied + $applied, 4);

                if ($applied > 0) {
                    $this->postLedgerEntry(
                        $bank,
                        $weekStart->copy()->endOfWeek(Carbon::SUNDAY),
                        EmployeeHoursBankLedger::TYPE_SHORTFALL,
                        -1 * $applied,
                        $weekStart,
                        $payrollRun->id,
                        null,
                        $expected,
                        $worked,
                        'Accrued hours applied to weekly shortfall',
                    );
                }

                if ($remaining > 0) {
                    $this->postLedgerEntry(
                        $bank,
                        $weekStart->copy()->endOfWeek(Carbon::SUNDAY),
                        EmployeeHoursBankLedger::TYPE_PAYROLL_SYNC,
                        -1 * $remaining,
                        $weekStart,
                        $payrollRun->id,
                        null,
                        $expected,
                        $worked,
                        'Uncovered weekly shortfall tracked against hours bank',
                    );
                    // week not fully covered
                } else {
                    $eligibleWeeks++;
                }
            }

            $isEligible = $eligibleWeeks === $weekCount;
            $reason = $isEligible
                ? null
                : 'Worked hours below expected for one or more weeks after applying accrued hours';

            return [
                'workedHours' => $totalWorked,
                'expectedHours' => $totalExpected,
                'bankHoursApplied' => $totalBankApplied,
                'isEligible' => $isEligible,
                'reason' => $reason,
                'balanceHours' => round((float) $bank->fresh()->balance_hours, 4),
            ];
        });
    }

    /**
     * @param list<string> $employeeIds
     */
    private function revertPayrollRunSync(string $payrollRunId, array $employeeIds): void
    {
        $entries = EmployeeHoursBankLedger::query()
            ->where('payroll_run_id', $payrollRunId)
            ->whereIn('entry_type', [
                EmployeeHoursBankLedger::TYPE_ACCRUAL,
                EmployeeHoursBankLedger::TYPE_SHORTFALL,
                EmployeeHoursBankLedger::TYPE_PAYROLL_SYNC,
            ])
            ->when($employeeIds !== [], fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->orderByDesc('created_at')
            ->get();

        foreach ($entries->groupBy('employee_id') as $employeeId => $employeeEntries) {
            $bank = $this->getOrCreateBank((string) $employeeId);
            $reversal = 0.0;
            foreach ($employeeEntries as $entry) {
                $reversal = round($reversal - (float) $entry->hours_delta, 4);
            }
            if (abs($reversal) > 0.0001) {
                $bank->balance_hours = round((float) $bank->balance_hours + $reversal, 4);
                $bank->save();
            }
            EmployeeHoursBankLedger::query()
                ->whereIn('id', $employeeEntries->pluck('id'))
                ->delete();
        }
    }

    /**
     * @return array<string, float> keyed by Monday Y-m-d
     */
    private function workedHoursByWeek(
        string $employeeId,
        Carbon $startDate,
        Carbon $endDate,
        string $payPeriodGroupId,
        ?int $payrateFrequencyId,
    ): array {
        $timesheets = $this->payrollTimesheetScopeService
            ->apply(
                Timesheet::query()->where('employeeId', $employeeId),
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $payrateFrequencyId,
            )
            ->whereRaw('UPPER("approvalStatus") = ?', ['APPROVED'])
            ->get(['date', 'regularHours', 'overtimeHours', 'holidayHours', 'hoursWorked']);

        $byWeek = [];
        foreach ($timesheets as $timesheet) {
            $date = Carbon::parse($timesheet->date)->startOfDay();
            $weekKey = $date->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
            $hours = round(
                (float) $timesheet->regularHours
                + (float) $timesheet->overtimeHours
                + (float) $timesheet->holidayHours,
                4,
            );
            if ($hours <= 0 && $timesheet->hoursWorked !== null) {
                $hours = round((float) $timesheet->hoursWorked, 4);
            }
            $byWeek[$weekKey] = round(($byWeek[$weekKey] ?? 0) + $hours, 4);
        }

        return $byWeek;
    }

    /**
     * @return list<Carbon>
     */
    private function weekStartsInRange(Carbon $startDate, Carbon $endDate): array
    {
        $cursor = $startDate->copy()->startOfWeek(Carbon::MONDAY);
        $last = $endDate->copy()->startOfWeek(Carbon::MONDAY);
        $weeks = [];
        while ($cursor->lte($last)) {
            $weeks[] = $cursor->copy();
            $cursor->addWeek();
        }

        return $weeks;
    }

    private function postLedgerEntry(
        EmployeeHoursBank $bank,
        Carbon $entryDate,
        string $entryType,
        float $hoursDelta,
        ?Carbon $weekStartDate,
        ?string $payrollRunId,
        ?string $employeeLeaveId,
        ?float $expectedHours,
        ?float $workedHours,
        ?string $notes,
    ): EmployeeHoursBankLedger {
        $balanceAfter = round((float) $bank->balance_hours + $hoursDelta, 4);
        $bank->balance_hours = $balanceAfter;
        $bank->save();

        return EmployeeHoursBankLedger::create([
            'id' => (string) Str::uuid(),
            'employee_id' => $bank->employee_id,
            'entry_date' => $entryDate->toDateString(),
            'week_start_date' => $weekStartDate?->toDateString(),
            'payroll_run_id' => $payrollRunId,
            'employee_leave_id' => $employeeLeaveId,
            'entry_type' => $entryType,
            'hours_delta' => $hoursDelta,
            'balance_after' => $balanceAfter,
            'expected_hours' => $expectedHours,
            'worked_hours' => $workedHours,
            'notes' => $notes,
        ]);
    }
}
