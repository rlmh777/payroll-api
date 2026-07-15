<?php

namespace App\Modules\Payroll\Services;

use App\Models\PayrollRun;
use App\Models\Timesheet;
use App\Modules\Hr\Services\Attendance\TimesheetPayFields;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PayrollRunTimesheetPaidMarker
{
    public function __construct(
        private readonly PayrollTimesheetScopeService $payrollTimesheetScopeService,
        private readonly PayrollRunFrequencyResolver $payrollRunFrequencyResolver,
    ) {
    }

    /**
     * Mark approved timesheets covered by a processed payroll run as paid.
     *
     * @param list<array<string, mixed>> $rows
     * @return int Number of timesheet rows updated
     */
    public function markForProcessedRun(PayrollRun $payrollRun, array $rows = []): int
    {
        $payrollRun->loadMissing(['payPeriodSchedule']);
        $schedule = $payrollRun->payPeriodSchedule;

        if (!$schedule?->start_date || !$schedule?->end_date) {
            return 0;
        }

        $startDate = Carbon::parse($schedule->start_date)->startOfDay();
        $endDate = Carbon::parse($schedule->end_date)->startOfDay();
        $payPeriodGroupId = (string) ($schedule->pay_period_group_id ?? '');
        if ($payPeriodGroupId === '') {
            return 0;
        }

        $employeeIds = collect($rows)
            ->pluck('employeeId')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values();

        if ($employeeIds->isEmpty()) {
            $employeeIds = collect(
                $this->payrollTimesheetScopeService->employeeIds(
                    $startDate,
                    $endDate,
                    $payPeriodGroupId,
                    $this->payrollRunFrequencyResolver->resolveForRun($payrollRun, $schedule),
                ),
            );
        }

        if ($employeeIds->isEmpty()) {
            return 0;
        }

        $frequencyId = $this->payrollRunFrequencyResolver->resolveForRun($payrollRun, $schedule);

        $timesheets = $this->payrollTimesheetScopeService
            ->apply(
                Timesheet::query()->whereIn('employeeId', $employeeIds->all()),
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $frequencyId,
            )
            ->whereRaw('UPPER("approvalStatus") = ?', ['APPROVED'])
            ->get();

        return $this->markCollectionPaid($timesheets);
    }

    /**
     * @param Collection<int, Timesheet> $timesheets
     */
    public function markCollectionPaid(Collection $timesheets): int
    {
        $updated = 0;

        foreach ($timesheets as $timesheet) {
            if (strtoupper((string) ($timesheet->workingStatus ?? '')) === 'UNPAID') {
                continue;
            }

            $payFields = TimesheetPayFields::fromStoredHours(
                true,
                (float) ($timesheet->regularHours ?? 0),
                (float) ($timesheet->overtimeHours ?? 0),
                (float) ($timesheet->holidayHours ?? 0),
                (float) ($timesheet->hoursWorked ?? 0),
            );

            $alreadyPaid = (bool) ($timesheet->isPaid ?? false)
                && (float) ($timesheet->paidHours ?? 0) === (float) $payFields['paidHours']
                && (float) ($timesheet->unpaidHours ?? 0) === (float) $payFields['unpaidHours'];

            if ($alreadyPaid) {
                continue;
            }

            $timesheet->isPaid = $payFields['isPaid'];
            $timesheet->paidHours = $payFields['paidHours'];
            $timesheet->unpaidHours = $payFields['unpaidHours'];
            if ($timesheet->exists) {
                $timesheet->save();
            }
            $updated++;
        }

        return $updated;
    }
}
