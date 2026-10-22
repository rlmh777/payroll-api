<?php

namespace App\Modules\Payroll\Services;

use App\Models\EmployeeLeave;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayrollRunProcessingService
{
    public function __construct(
        private readonly PayrollRunCalculationService $payrollRunCalculationService,
        private readonly PoolDistributionService $poolDistributionService,
        private readonly PayrollRunEarningLineBuilderService $payrollRunEarningLineBuilderService,
        private readonly PayrollRunTimesheetPaidMarker $payrollRunTimesheetPaidMarker,
        private readonly PayrollRunLeavePaidMarker $payrollRunLeavePaidMarker,
        private readonly TimesheetPayrollLockService $timesheetPayrollLockService,
    ) {
    }

    /**
     * @return array{
     *     payrollRunId: string,
     *     status: string,
     *     employeeCount: int,
     *     asOfDate: string,
     *     totals: array<string, float>,
     *     timesheetsMarkedPaid: int
     * }
     */
    public function process(PayrollRun $payrollRun): array
    {
        if ($payrollRun->status === 'posted') {
            throw new InvalidArgumentException('This payroll run has already been processed.');
        }

        return DB::transaction(function () use ($payrollRun) {
            $this->poolDistributionService->refreshSharePoolsFromEmployeePoints($payrollRun);
            $summary = $this->payrollRunCalculationService->calculate($payrollRun, true, true);
            $rows = $summary['rows'] ?? [];

            if ($rows === []) {
                throw new InvalidArgumentException('No employees are in scope for this payroll run.');
            }

            $this->payrollRunEarningLineBuilderService->syncForRun($payrollRun, $summary);

            $payrollRun->update(['status' => 'posted']);
            $payrollRun->refresh();

            $timesheetsMarkedPaid = $this->payrollRunTimesheetPaidMarker->markForProcessedRun(
                $payrollRun,
                $rows,
            );
            $this->payrollRunLeavePaidMarker->markForProcessedRun($payrollRun, $rows);

            return [
                'payrollRunId' => (string) $payrollRun->id,
                'status' => (string) $payrollRun->status,
                'employeeCount' => count($rows),
                'asOfDate' => (string) ($summary['asOfDate'] ?? ''),
                'totals' => $summary['totals'] ?? [],
                'timesheetsMarkedPaid' => $timesheetsMarkedPaid,
            ];
        });
    }

    /**
     * Return a processed run to draft so timesheets, payments, and pools can be corrected
     * and the run processed again.
     *
     * @return array{payrollRunId: string, status: string}
     */
    public function reopen(PayrollRun $payrollRun): array
    {
        if (strtolower((string) $payrollRun->status) !== 'posted') {
            throw new InvalidArgumentException('Only a processed payroll run can be reopened.');
        }

        $this->assertNoLaterPostedRun($payrollRun);

        return DB::transaction(function () use ($payrollRun) {
            $payrollRun->loadMissing('payPeriodSchedule');

            EmployeeLeave::query()
                ->where('paidInPayrollRunId', $payrollRun->id)
                ->update(['paidInPayrollRunId' => null]);

            $this->rewindTimesheetLock($payrollRun);

            $payrollRun->update([
                'status' => 'draft',
                'timesheet_lock_applied_at' => null,
            ]);
            $payrollRun->refresh();

            return [
                'payrollRunId' => (string) $payrollRun->id,
                'status' => (string) $payrollRun->status,
            ];
        });
    }

    private function assertNoLaterPostedRun(PayrollRun $payrollRun): void
    {
        $payrollRun->loadMissing('payPeriodSchedule');
        $schedule = $payrollRun->payPeriodSchedule;

        if (!$schedule?->end_date || !$schedule->pay_period_group_id) {
            return;
        }

        $laterPosted = PayrollRun::query()
            ->where('id', '!=', $payrollRun->id)
            ->whereRaw('LOWER(status) = ?', ['posted'])
            ->whereHas('payPeriodSchedule', function ($query) use ($schedule) {
                $query
                    ->where('pay_period_group_id', $schedule->pay_period_group_id)
                    ->whereDate('end_date', '>', Carbon::parse($schedule->end_date)->toDateString());
            })
            ->exists();

        if ($laterPosted) {
            throw new InvalidArgumentException(
                'A later payroll run in this pay period group is already posted. Reopen that run before this one.',
            );
        }
    }

    private function rewindTimesheetLock(PayrollRun $payrollRun): void
    {
        $schedule = $payrollRun->payPeriodSchedule;

        if (!$schedule?->end_date || $payrollRun->timesheet_lock_applied_at === null) {
            return;
        }

        $proposed = $this->timesheetPayrollLockService->proposedLockBeforeDate($schedule);
        $setting = PayrollSetting::query()->lockForUpdate()->first() ?? PayrollSetting::current();
        $current = $setting->timesheetLockBeforeDate?->toDateString();

        if ($current !== $proposed) {
            return;
        }

        $replacement = null;
        $otherRuns = PayrollRun::query()
            ->where('id', '!=', $payrollRun->id)
            ->whereRaw('LOWER(status) = ?', ['posted'])
            ->whereNotNull('timesheet_lock_applied_at')
            ->with('payPeriodSchedule')
            ->get();

        foreach ($otherRuns as $run) {
            $otherSchedule = $run->payPeriodSchedule;
            if (!$otherSchedule?->end_date) {
                continue;
            }

            $otherProposed = $this->timesheetPayrollLockService->proposedLockBeforeDate($otherSchedule);
            if ($replacement === null || $otherProposed > $replacement) {
                $replacement = $otherProposed;
            }
        }

        $setting->update([
            'timesheetLockBeforeDate' => $replacement,
        ]);
    }
}
