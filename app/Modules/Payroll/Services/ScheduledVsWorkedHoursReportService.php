<?php

namespace App\Modules\Payroll\Services;

use App\Models\Payroll;
use App\Models\PayrollEarningCode;
use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use App\Models\PayrollRunPoolDistribution;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ScheduledVsWorkedHoursReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRun $payrollRun): array
    {
        if (strtolower((string) $payrollRun->status) !== 'posted') {
            throw new InvalidArgumentException('Scheduled vs worked hours report is only available for processed payroll runs.');
        }

        $payrollRun->load(['payPeriodSchedule.payPeriodGroup']);
        $schedule = $payrollRun->payPeriodSchedule;

        if (!$schedule?->start_date || !$schedule?->end_date) {
            throw new InvalidArgumentException('Pay period schedule is missing for this payroll run.');
        }

        $payrolls = Payroll::query()
            ->with(['employee.person'])
            ->where('payroll_run_id', $payrollRun->id)
            ->orderBy('employeeId')
            ->get(['id', 'employeeId']);

        if ($payrolls->isEmpty()) {
            throw new InvalidArgumentException('No payroll data is available for this payroll run.');
        }

        $employeeIds = $payrolls->pluck('employeeId')->map(fn ($id) => (string) $id)->values();
        $payrollIds = $payrolls->pluck('id')->map(fn ($id) => (string) $id)->values();

        $timesheetAggByEmployee = $this->loadTimesheetAggregates(
            $employeeIds,
            $schedule->start_date->toDateString(),
            $schedule->end_date->toDateString(),
        );
        $earningAggByEmployee = $this->loadEarningAggregates($payrollRun->id);
        $poolAggByEmployee = $this->loadPoolAggregates($payrollRun->id);

        $rows = $payrolls->map(function (Payroll $payroll) use ($timesheetAggByEmployee, $earningAggByEmployee, $poolAggByEmployee) {
            $employeeId = (string) $payroll->employeeId;
            $employee = $payroll->employee;
            $person = $employee?->person;

            $employeeName = trim((string) implode(' ', array_filter([
                $person?->firstName ?? null,
                $person?->middleName ?? null,
                $person?->lastName ?? null,
            ])));
            if ($employeeName === '') {
                $employeeName = 'Unknown employee';
            }

            $timesheet = $timesheetAggByEmployee->get($employeeId, [
                'scheduledHours' => 0.0,
                'workedHours' => 0.0,
                'overtimeHours' => 0.0,
            ]);
            $earnings = $earningAggByEmployee->get($employeeId, [
                'workedAmount' => 0.0,
                'overtimeAmount' => 0.0,
                'otherEarningsAmount' => 0.0,
            ]);
            $pools = $poolAggByEmployee->get($employeeId, [
                'tips' => 0.0,
                'shares' => 0.0,
                'specialAssignments' => 0.0,
            ]);

            // Keep scheduled amount as a practical pay estimate based on worked pay over scheduled hours.
            $scheduledHours = round((float) ($timesheet['scheduledHours'] ?? 0), 2);
            $workedHours = round((float) ($timesheet['workedHours'] ?? 0), 2);
            $workedAmount = round((float) ($earnings['workedAmount'] ?? 0), 2);
            $scheduledAmount = $scheduledHours > 0
                ? round(($workedAmount / max(0.01, $workedHours)) * $scheduledHours, 2)
                : 0.0;

            $specialAssignments = round(
                (float) ($pools['specialAssignments'] ?? 0)
                + (float) ($earnings['otherEarningsAmount'] ?? 0),
                2,
            );

            return [
                'employeeId' => $employeeId,
                'employeeCode' => $employee?->code,
                'employeeName' => $employeeName,
                'scheduledHours' => $scheduledHours,
                'scheduledAmount' => $scheduledAmount,
                'workedHours' => $workedHours,
                'workedAmount' => $workedAmount,
                'overtimeHours' => round((float) ($timesheet['overtimeHours'] ?? 0), 2),
                'overtimeAmount' => round((float) ($earnings['overtimeAmount'] ?? 0), 2),
                'tips' => round((float) ($pools['tips'] ?? 0), 2),
                'shares' => round((float) ($pools['shares'] ?? 0), 2),
                'specialAssignments' => $specialAssignments,
            ];
        })->sortBy([
            ['employeeCode', 'asc'],
            ['employeeName', 'asc'],
        ])->values();

        return [
            'payrollRunId' => (string) $payrollRun->id,
            'payPeriodGroupName' => $schedule->payPeriodGroup?->name,
            'payPeriodStartDate' => $schedule->start_date->toDateString(),
            'payPeriodEndDate' => $schedule->end_date->toDateString(),
            'rows' => $rows->all(),
            'totals' => [
                'scheduledHours' => round((float) $rows->sum('scheduledHours'), 2),
                'scheduledAmount' => round((float) $rows->sum('scheduledAmount'), 2),
                'workedHours' => round((float) $rows->sum('workedHours'), 2),
                'workedAmount' => round((float) $rows->sum('workedAmount'), 2),
                'overtimeHours' => round((float) $rows->sum('overtimeHours'), 2),
                'overtimeAmount' => round((float) $rows->sum('overtimeAmount'), 2),
                'tips' => round((float) $rows->sum('tips'), 2),
                'shares' => round((float) $rows->sum('shares'), 2),
                'specialAssignments' => round((float) $rows->sum('specialAssignments'), 2),
            ],
        ];
    }

    /**
     * @param Collection<int, string> $employeeIds
     * @return Collection<string, array<string, float>>
     */
    private function loadTimesheetAggregates(Collection $employeeIds, string $startDate, string $endDate): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return \App\Models\Timesheet::query()
            ->whereIn('employeeId', $employeeIds)
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->selectRaw(
                '"employeeId", '.
                'SUM(COALESCE("hoursWorked", 0) + COALESCE("unpaidHours", 0)) as scheduled_hours, '.
                'SUM(COALESCE("hoursWorked", 0)) as worked_hours, '.
                'SUM(COALESCE("overtimeHours", 0)) as overtime_hours'
            )
            ->groupBy('employeeId')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->employeeId => [
                    'scheduledHours' => round((float) ($row->scheduled_hours ?? 0), 2),
                    'workedHours' => round((float) ($row->worked_hours ?? 0), 2),
                    'overtimeHours' => round((float) ($row->overtime_hours ?? 0), 2),
                ],
            ]);
    }

    /**
     * @return Collection<string, array<string, float>>
     */
    private function loadEarningAggregates(string $payrollRunId): Collection
    {
        $codesById = PayrollEarningCode::query()
            ->get(['id', 'code'])
            ->pluck('code', 'id');

        $rows = PayrollEarningLine::query()
            ->where('payroll_run_id', $payrollRunId)
            ->selectRaw('"employeeId", payroll_earning_code_id, source_type, SUM(amount) as total_amount')
            ->groupBy('employeeId', 'payroll_earning_code_id', 'source_type')
            ->get();

        $byEmployee = [];
        foreach ($rows as $row) {
            $employeeId = (string) $row->employeeId;
            $code = strtoupper((string) ($codesById->get((int) $row->payroll_earning_code_id) ?? ''));
            $amount = round((float) ($row->total_amount ?? 0), 2);
            $sourceType = strtoupper((string) ($row->source_type ?? ''));

            $byEmployee[$employeeId] ??= [
                'workedAmount' => 0.0,
                'overtimeAmount' => 0.0,
                'otherEarningsAmount' => 0.0,
            ];

            if (in_array($code, ['REGULAR', 'HOLIDAY'], true)) {
                $byEmployee[$employeeId]['workedAmount'] = round($byEmployee[$employeeId]['workedAmount'] + $amount, 2);
                continue;
            }

            if ($code === 'OVERTIME') {
                $byEmployee[$employeeId]['overtimeAmount'] = round($byEmployee[$employeeId]['overtimeAmount'] + $amount, 2);
                continue;
            }

            if ($sourceType !== 'POOL') {
                $byEmployee[$employeeId]['otherEarningsAmount'] = round(
                    $byEmployee[$employeeId]['otherEarningsAmount'] + $amount,
                    2
                );
            }
        }

        return collect($byEmployee);
    }

    /**
     * @return Collection<string, array<string, float>>
     */
    private function loadPoolAggregates(string $payrollRunId): Collection
    {
        $rows = PayrollRunPoolDistribution::query()
            ->with('poolDistributionType:id,code')
            ->where('payroll_run_id', $payrollRunId)
            ->where('amount', '>', 0)
            ->get();

        $byEmployee = [];
        foreach ($rows as $row) {
            $employeeId = (string) $row->employee_id;
            $code = strtoupper((string) ($row->poolDistributionType?->code ?? ''));
            $amount = round((float) ($row->amount ?? 0), 2);

            $byEmployee[$employeeId] ??= [
                'tips' => 0.0,
                'shares' => 0.0,
                'specialAssignments' => 0.0,
            ];

            if ($code === 'TIPS') {
                $byEmployee[$employeeId]['tips'] = round($byEmployee[$employeeId]['tips'] + $amount, 2);
            } elseif ($code === 'SHARES') {
                $byEmployee[$employeeId]['shares'] = round($byEmployee[$employeeId]['shares'] + $amount, 2);
            } else {
                $byEmployee[$employeeId]['specialAssignments'] = round($byEmployee[$employeeId]['specialAssignments'] + $amount, 2);
            }
        }

        return collect($byEmployee);
    }
}

