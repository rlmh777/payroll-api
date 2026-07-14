<?php

namespace App\Modules\Payroll\Services;

use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SalaryReviewReportService
{
    private const GRATUITY_ACCOUNT_NAME = 'gratuity';

    private const CALCULATED_GRATUITY_COLUMN = '30% Gratuity';

    private const GROSS_COLUMN = 'Gross';

    /**
     * @var list<string>
     */
    private const PREFERRED_ACCOUNT_ORDER = [
        'Monthly Allowance',
        'Total Base Wage',
        'Gratuity',
        'Total Allowance',
        'Regular Holiday Pay',
        'Tips',
    ];

    public function __construct(
        private readonly PayrollRunCalculationService $payrollRunCalculationService,
        private readonly PayrollRunEarningLineBuilderService $payrollRunEarningLineBuilderService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Carbon $startDate, Carbon $endDate): array
    {
        if ($endDate->lt($startDate)) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }

        $this->ensureEarningLinesForPostedRuns($startDate, $endDate);

        $aggregates = PayrollEarningLine::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_earning_line.payroll_run_id')
            ->join('pay_period_schedule', 'pay_period_schedule.id', '=', 'payroll_runs.pay_period_schedule_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'payroll_earning_line.accountId')
            ->leftJoin('department', 'department.id', '=', 'payroll_earning_line.departmentId')
            ->leftJoin('employee', 'employee.id', '=', 'payroll_earning_line.employeeId')
            ->leftJoin('person', 'person.id', '=', 'employee.person_id')
            ->whereRaw('LOWER(payroll_runs.status) = ?', ['posted'])
            ->whereDate('pay_period_schedule.start_date', '<=', $endDate->toDateString())
            ->whereDate('pay_period_schedule.end_date', '>=', $startDate->toDateString())
            ->where('payroll_earning_line.amount', '>', 0)
            ->select([
                'payroll_earning_line.departmentId',
                DB::raw('COALESCE(department.name, \'Unassigned\') as department_name'),
                'payroll_earning_line.employeeId',
                DB::raw("NULLIF(TRIM(CONCAT_WS(' ', person.\"firstName\", person.\"middleName\", person.\"lastName\")), '') as employee_name"),
                'employee.code as employee_code',
                'person.lastName as employee_last_name',
                'person.firstName as employee_first_name',
                'payroll_earning_line.accountId',
                DB::raw('COALESCE(NULLIF(TRIM(accounts.name), \'\'), NULLIF(TRIM(accounts.description), \'\'), \'Unassigned Account\') as account_name'),
                DB::raw('SUM(payroll_earning_line.amount) as total_amount'),
            ])
            ->groupBy(
                'payroll_earning_line.departmentId',
                DB::raw('COALESCE(department.name, \'Unassigned\')'),
                'payroll_earning_line.employeeId',
                DB::raw("NULLIF(TRIM(CONCAT_WS(' ', person.\"firstName\", person.\"middleName\", person.\"lastName\")), '')"),
                'employee.code',
                'person.lastName',
                'person.firstName',
                'payroll_earning_line.accountId',
                DB::raw('COALESCE(NULLIF(TRIM(accounts.name), \'\'), NULLIF(TRIM(accounts.description), \'\'), \'Unassigned Account\')'),
            )
            ->get();

        if ($aggregates->isEmpty()) {
            return [
                'startDate' => $startDate->toDateString(),
                'endDate' => $endDate->toDateString(),
                'departments' => [],
            ];
        }

        $departments = $aggregates
            ->groupBy(fn ($row) => (string) ($row->departmentId ?? 'unassigned'))
            ->map(function (Collection $departmentRows, string $departmentKey) {
                $firstRow = $departmentRows->first();
                $accountNames = $departmentRows
                    ->pluck('account_name')
                    ->map(fn ($name) => (string) $name)
                    ->unique()
                    ->values();

                $columns = $this->buildColumns($accountNames);

                $employeeRows = $departmentRows
                    ->groupBy('employeeId')
                    ->map(function (Collection $employeeAccountRows) use ($columns) {
                        $employeeRow = $employeeAccountRows->first();
                        $employeeName = trim((string) ($employeeRow->employee_name ?? ''));
                        if ($employeeName === '') {
                            $employeeName = 'Unknown employee';
                        }

                        $values = [];
                        foreach ($columns as $column) {
                            if ($column === self::CALCULATED_GRATUITY_COLUMN || $column === self::GROSS_COLUMN) {
                                continue;
                            }

                            $values[$column] = 0.0;
                        }

                        foreach ($employeeAccountRows as $accountRow) {
                            $accountName = (string) $accountRow->account_name;
                            if (!array_key_exists($accountName, $values)) {
                                $values[$accountName] = 0.0;
                            }

                            $values[$accountName] = round(
                                $values[$accountName] + (float) $accountRow->total_amount,
                                2,
                            );
                        }

                        $values = $this->appendCalculatedColumns($values, $columns);

                        return [
                            'employeeId' => (string) $employeeRow->employeeId,
                            'employeeCode' => $employeeRow->employee_code,
                            'employeeName' => $employeeName,
                            'values' => $values,
                        ];
                    })
                    ->sortBy([
                        ['employeeName', 'asc'],
                    ])
                    ->values();

                $totals = [];
                foreach ($columns as $column) {
                    $totals[$column] = round(
                        (float) $employeeRows->sum(fn (array $row) => (float) ($row['values'][$column] ?? 0)),
                        2,
                    );
                }

                return [
                    'departmentId' => $departmentKey === 'unassigned' ? null : (int) $departmentKey,
                    'departmentName' => (string) ($firstRow->department_name ?? 'Unassigned'),
                    'columns' => $columns,
                    'rows' => $employeeRows->all(),
                    'totals' => $totals,
                ];
            })
            ->sortBy('departmentName')
            ->values()
            ->all();

        return [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'departments' => $departments,
        ];
    }

    /**
     * @param Collection<int, string> $accountNames
     * @return list<string>
     */
    private function buildColumns(Collection $accountNames): array
    {
        $ordered = collect(self::PREFERRED_ACCOUNT_ORDER)
            ->filter(fn (string $name) => $accountNames->contains($name))
            ->values();

        $remaining = $accountNames
            ->diff($ordered)
            ->sort()
            ->values();

        $columns = $ordered
            ->merge($remaining)
            ->values()
            ->all();

        $gratuityColumn = $this->findGratuityColumn($columns);
        if ($gratuityColumn !== null) {
            $index = array_search($gratuityColumn, $columns, true);
            array_splice($columns, $index + 1, 0, [self::CALCULATED_GRATUITY_COLUMN]);
        }

        $columns[] = self::GROSS_COLUMN;

        return $columns;
    }

    /**
     * @param list<string> $columns
     * @param array<string, float> $values
     * @return array<string, float>
     */
    private function appendCalculatedColumns(array $values, array $columns): array
    {
        $gratuityColumn = $this->findGratuityColumn(array_keys($values));
        if ($gratuityColumn !== null) {
            $values[self::CALCULATED_GRATUITY_COLUMN] = round((float) ($values[$gratuityColumn] ?? 0) * 0.30, 2);
        } elseif (in_array(self::CALCULATED_GRATUITY_COLUMN, $columns, true)) {
            $values[self::CALCULATED_GRATUITY_COLUMN] = 0.0;
        }

        $gross = 0.0;
        foreach ($values as $column => $amount) {
            if ($column === self::GROSS_COLUMN) {
                continue;
            }

            $gross = round($gross + (float) $amount, 2);
        }

        $values[self::GROSS_COLUMN] = $gross;

        foreach ($columns as $column) {
            $values[$column] ??= 0.0;
        }

        return $values;
    }

    /**
     * @param list<string> $columns
     */
    private function findGratuityColumn(array $columns): ?string
    {
        foreach ($columns as $column) {
            if (strtolower(trim($column)) === self::GRATUITY_ACCOUNT_NAME) {
                return $column;
            }
        }

        return null;
    }

    private function ensureEarningLinesForPostedRuns(Carbon $startDate, Carbon $endDate): void
    {
        $runs = PayrollRun::query()
            ->with('payPeriodSchedule')
            ->whereRaw('LOWER(payroll_runs.status) = ?', ['posted'])
            ->whereHas('payPeriodSchedule', function ($query) use ($startDate, $endDate) {
                $query->whereDate('start_date', '<=', $endDate->toDateString())
                    ->whereDate('end_date', '>=', $startDate->toDateString());
            })
            ->get();

        foreach ($runs as $run) {
            if ($run->earningLines()->exists()) {
                continue;
            }

            $summary = $this->payrollRunCalculationService->calculate($run, false, true);
            $this->payrollRunEarningLineBuilderService->syncForRun($run, $summary);
        }
    }
}
