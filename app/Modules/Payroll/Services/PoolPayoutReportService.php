<?php

namespace App\Modules\Payroll\Services;

use App\Models\Employee;
use App\Models\PayrollRunPoolDistribution;
use Carbon\Carbon;

class PoolPayoutReportService
{
    /**
     * @return array{startDate:string, endDate:string, rows:list<array<string, mixed>>, totals:array{amount:float, employees:int}}
     */
    public function build(Carbon $startDate, Carbon $endDate): array
    {
        $rows = PayrollRunPoolDistribution::query()
            ->with([
                'payrollRun.payPeriodSchedule',
                'poolDistributionType',
                'employee.person',
            ])
            ->where(function ($query) {
                $query->whereNotNull('paid_shares')
                    ->orWhereNotNull('paid_percent');
            })
            ->where('amount', '>', 0)
            ->whereHas('payrollRun.payPeriodSchedule', function ($query) use ($startDate, $endDate) {
                $query->whereDate('end_date', '>=', $startDate->toDateString())
                    ->whereDate('start_date', '<=', $endDate->toDateString());
            })
            ->get()
            ->map(fn (PayrollRunPoolDistribution $row) => $this->present($row))
            ->sortBy([
                ['periodEnd', 'asc'],
                ['poolName', 'asc'],
                ['employeeName', 'asc'],
            ])
            ->values();

        return [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'rows' => $rows->all(),
            'totals' => [
                'amount' => round((float) $rows->sum('amount'), 2),
                'employees' => $rows->pluck('employeeId')->unique()->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PayrollRunPoolDistribution $row): array
    {
        $schedule = $row->payrollRun?->payPeriodSchedule;
        $employee = $row->employee;

        return [
            'payrollRunId' => $row->payroll_run_id,
            'payrollNumber' => $row->payrollRun?->payrollNumberFormatted,
            'status' => $row->payrollRun?->status,
            'periodStart' => $schedule?->start_date?->toDateString(),
            'periodEnd' => $schedule?->end_date?->toDateString(),
            'poolName' => $row->poolDistributionType?->name,
            'poolCode' => $row->poolDistributionType?->code,
            'employeeId' => $row->employee_id,
            'employeeCode' => $employee?->code,
            'internalCode1' => $employee?->internalId1,
            'employeeName' => $this->employeeName($employee),
            'paidShares' => $row->paid_shares !== null ? round((float) $row->paid_shares, 4) : null,
            'paidPercent' => $row->paid_percent !== null ? round((float) $row->paid_percent, 4) : null,
            'amount' => round((float) $row->amount, 2),
        ];
    }

    private function employeeName(?Employee $employee): string
    {
        if (!$employee) {
            return '';
        }

        $name = trim(collect([
            $employee->firstName ?? $employee->person?->firstName,
            $employee->lastName ?? $employee->person?->lastName,
        ])->filter()->implode(' '));

        return $name !== '' ? $name : (string) ($employee->code ?? '');
    }
}
