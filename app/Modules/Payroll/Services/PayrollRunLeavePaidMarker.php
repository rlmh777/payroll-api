<?php

namespace App\Modules\Payroll\Services;

use App\Enums\LeaveStatusCode;
use App\Models\EmployeeLeave;
use App\Models\PayrollRun;
use Carbon\Carbon;

/**
 * After a run is posted, link "pay with this payroll" leave to the run so later
 * periods cannot pay the same leave days again.
 */
class PayrollRunLeavePaidMarker
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function markForProcessedRun(PayrollRun $payrollRun, array $rows): int
    {
        $schedule = $payrollRun->payPeriodSchedule;
        if (! $schedule?->start_date || ! $schedule?->end_date) {
            return 0;
        }

        $employeeIds = collect($rows)
            ->map(fn (array $row) => (string) ($row['employeeId'] ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($employeeIds === []) {
            return 0;
        }

        $startDate = Carbon::parse($schedule->start_date)->toDateString();
        $endDate = Carbon::parse($schedule->end_date)->toDateString();
        $runId = (string) $payrollRun->id;

        $leaves = EmployeeLeave::query()
            ->whereIn('employeeId', $employeeIds)
            ->whereHas('leaveStatus', fn ($query) => $query->whereIn('code', array_map(
                fn (LeaveStatusCode $code) => $code->value,
                LeaveStatusCode::activeAbsenceStatuses(),
            )))
            ->whereDate('startDate', '<=', $endDate)
            ->whereDate('endDate', '>=', $startDate)
            ->where(function ($query) {
                $query
                    ->where('paymentTreatment', 'paid_with_payroll')
                    ->orWhere(function ($inner) {
                        $inner->whereNull('paymentTreatment')
                            ->where(function ($multiplierQuery) {
                                $multiplierQuery->whereNull('multiplier')
                                    ->orWhere('multiplier', '>', 0);
                            });
                    });
            })
            ->where(function ($query) use ($runId) {
                $query->whereNull('paidInPayrollRunId')
                    ->orWhere('paidInPayrollRunId', $runId);
            })
            ->get();

        $marked = 0;
        foreach ($leaves as $leave) {
            if ((string) $leave->paidInPayrollRunId === $runId) {
                continue;
            }

            // Never overwrite advance-paid leave with this run id.
            if (strtolower((string) $leave->paymentTreatment) === 'already_paid') {
                continue;
            }

            $leave->paidInPayrollRunId = $runId;
            if ($leave->paymentTreatment === null || $leave->paymentTreatment === '') {
                $leave->paymentTreatment = 'paid_with_payroll';
            }
            $leave->save();
            $marked++;
        }

        return $marked;
    }
}
