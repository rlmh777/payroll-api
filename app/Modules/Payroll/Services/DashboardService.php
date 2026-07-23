<?php

namespace App\Modules\Payroll\Services;

use App\Models\Payroll;
use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function build(?string $payrollRunId = null, int $trendMonths = 6): array
    {
        $trendMonths = max(3, min(24, $trendMonths));
        $currentRun = $this->resolveCurrentRun($payrollRunId);
        $asOf = $currentRun
            ? Carbon::parse($currentRun->payPeriodSchedule?->end_date ?? $currentRun->updated_at ?? now())
            : now();
        $ytdYear = (int) $asOf->year;

        $currentPayrollRows = $currentRun
            ? Payroll::query()->where('payroll_run_id', $currentRun->id)->get()
            : collect();

        $ytdPayrollRows = Payroll::query()
            ->whereHas('payrollRun', fn ($query) => $query->whereRaw('LOWER(status) = ?', ['posted']))
            ->whereYear('date', $ytdYear)
            ->whereDate('date', '<=', $asOf->toDateString())
            ->get();

        $currentGross = round((float) $currentPayrollRows->sum(fn ($row) => (float) ($row->taxableGross ?? $row->grossSalary ?? 0)), 2);
        $ytdGross = round((float) $ytdPayrollRows->sum(fn ($row) => (float) ($row->taxableGross ?? $row->grossSalary ?? 0)), 2);
        $currentNet = round((float) $currentPayrollRows->sum('netSalary'), 2);
        $currentEmployerCost = round((float) $currentPayrollRows->sum('employerCostTotal'), 2);
        $headcount = $currentPayrollRows->pluck('employeeId')->filter()->unique()->count();

        $overtimeCost = $currentRun
            ? round((float) PayrollEarningLine::query()
                ->join('payroll_earning_code', 'payroll_earning_code.id', '=', 'payroll_earning_line.payroll_earning_code_id')
                ->where('payroll_earning_line.payroll_run_id', $currentRun->id)
                ->whereRaw('UPPER(payroll_earning_code.code) = ?', ['OVERTIME'])
                ->sum('payroll_earning_line.amount'), 2)
            : 0.0;

        return [
            'meta' => [
                'currentPayrollRunId' => $currentRun?->id,
                'currentPeriod' => $currentRun ? [
                    'startDate' => optional($currentRun->payPeriodSchedule?->start_date)?->toDateString(),
                    'endDate' => optional($currentRun->payPeriodSchedule?->end_date)?->toDateString(),
                    'label' => $currentRun->payPeriodSchedule?->payPeriodGroup?->name,
                ] : null,
                'asOfDate' => $asOf->toDateString(),
                'ytdYear' => $ytdYear,
                'hasPostedPayroll' => $currentRun !== null,
            ],
            'kpis' => [
                'currentPayroll' => $currentGross,
                'ytdPayroll' => $ytdGross,
                'netPay' => $currentNet,
                'employerCost' => $currentEmployerCost,
                'headcountPaid' => $headcount,
                'overtimeCost' => $overtimeCost,
            ],
            'payrollTrend' => $this->payrollTrend($asOf, $trendMonths),
            'departmentShare' => $this->departmentShare($currentPayrollRows),
            'costByDepartment' => $this->costByDepartment($currentRun),
            'deductionsMix' => $this->deductionsMix($currentPayrollRows),
            'ytdVsCurrentByDepartment' => $this->ytdVsCurrentByDepartment($currentPayrollRows, $ytdPayrollRows),
            'overtimeHeatmap' => $this->overtimeHeatmap($currentRun),
        ];
    }

    private function resolveCurrentRun(?string $payrollRunId): ?PayrollRun
    {
        if ($payrollRunId) {
            return PayrollRun::query()
                ->with(['payPeriodSchedule.payPeriodGroup'])
                ->whereKey($payrollRunId)
                ->whereRaw('LOWER(status) = ?', ['posted'])
                ->first();
        }

        return PayrollRun::query()
            ->with(['payPeriodSchedule.payPeriodGroup'])
            ->whereRaw('LOWER(status) = ?', ['posted'])
            ->leftJoin('pay_period_schedule', 'pay_period_schedule.id', '=', 'payroll_runs.pay_period_schedule_id')
            ->orderByDesc('pay_period_schedule.end_date')
            ->orderByDesc('payroll_runs.updated_at')
            ->select('payroll_runs.*')
            ->first();
    }

    /**
     * @return array{months: list<string>, labels: list<string>, gross: list<float>, net: list<float>, employerCost: list<float>}
     */
    private function payrollTrend(Carbon $asOf, int $months): array
    {
        $start = $asOf->copy()->startOfMonth()->subMonths($months - 1);

        $rows = Payroll::query()
            ->whereHas('payrollRun', fn ($query) => $query->whereRaw('LOWER(status) = ?', ['posted']))
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $asOf->toDateString())
            ->selectRaw("
                to_char(date_trunc('month', date), 'YYYY-MM') as month_key,
                SUM(COALESCE(\"taxableGross\", \"grossSalary\", 0)) as gross_total,
                SUM(COALESCE(\"netSalary\", 0)) as net_total,
                SUM(COALESCE(\"employerCostTotal\", 0)) as employer_total
            ")
            ->groupByRaw("date_trunc('month', date)")
            ->orderByRaw("date_trunc('month', date)")
            ->get()
            ->keyBy('month_key');

        $monthKeys = [];
        $labels = [];
        $gross = [];
        $net = [];
        $employerCost = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $row = $rows->get($key);
            $monthKeys[] = $key;
            $labels[] = $month->format('M');
            $gross[] = round((float) ($row->gross_total ?? 0), 2);
            $net[] = round((float) ($row->net_total ?? 0), 2);
            $employerCost[] = round((float) ($row->employer_total ?? 0), 2);
        }

        return [
            'months' => $monthKeys,
            'labels' => $labels,
            'gross' => $gross,
            'net' => $net,
            'employerCost' => $employerCost,
        ];
    }

    /**
     * @param  Collection<int, Payroll>  $currentPayrollRows
     * @return list<array{departmentId: int|null, name: string, value: float}>
     */
    private function departmentShare(Collection $currentPayrollRows): array
    {
        if ($currentPayrollRows->isEmpty()) {
            return [];
        }

        $departmentIds = $currentPayrollRows->pluck('departmentId')->filter()->unique()->values();
        $names = $departmentIds->isEmpty()
            ? collect()
            : DB::table('department')->whereIn('id', $departmentIds)->pluck('name', 'id');

        return $currentPayrollRows
            ->groupBy(fn (Payroll $row) => (string) ($row->departmentId ?? 'unassigned'))
            ->map(function (Collection $group, string $key) use ($names) {
                $departmentId = $key === 'unassigned' ? null : (int) $key;

                return [
                    'departmentId' => $departmentId,
                    'name' => $departmentId !== null
                        ? (string) ($names->get($departmentId) ?? 'Unassigned')
                        : 'Unassigned',
                    'value' => round((float) $group->sum(fn ($row) => (float) ($row->taxableGross ?? $row->grossSalary ?? 0)), 2),
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    /**
     * @return array{departments: list<string>, regular: list<float>, overtime: list<float>, holiday: list<float>, allowances: list<float>}
     */
    private function costByDepartment(?PayrollRun $currentRun): array
    {
        if (! $currentRun) {
            return [
                'departments' => [],
                'regular' => [],
                'overtime' => [],
                'holiday' => [],
                'allowances' => [],
            ];
        }

        $rows = PayrollEarningLine::query()
            ->leftJoin('department', 'department.id', '=', 'payroll_earning_line.departmentId')
            ->join('payroll_earning_code', 'payroll_earning_code.id', '=', 'payroll_earning_line.payroll_earning_code_id')
            ->where('payroll_earning_line.payroll_run_id', $currentRun->id)
            ->whereRaw('UPPER(payroll_earning_code.code) IN (?, ?, ?, ?)', ['REGULAR', 'OVERTIME', 'HOLIDAY', 'ALLOWANCE'])
            ->select([
                'payroll_earning_line.departmentId',
                DB::raw('COALESCE(department.name, \'Unassigned\') as department_name'),
                DB::raw('UPPER(payroll_earning_code.code) as earning_code'),
                DB::raw('SUM(payroll_earning_line.amount) as total_amount'),
            ])
            ->groupBy(
                'payroll_earning_line.departmentId',
                DB::raw('COALESCE(department.name, \'Unassigned\')'),
                DB::raw('UPPER(payroll_earning_code.code)'),
            )
            ->orderBy('department_name')
            ->get();

        $byDept = [];
        foreach ($rows as $row) {
            $name = (string) $row->department_name;
            if (! isset($byDept[$name])) {
                $byDept[$name] = [
                    'regular' => 0.0,
                    'overtime' => 0.0,
                    'holiday' => 0.0,
                    'allowances' => 0.0,
                ];
            }

            $amount = round((float) $row->total_amount, 2);
            $code = strtoupper((string) $row->earning_code);
            if ($code === 'REGULAR') {
                $byDept[$name]['regular'] = $amount;
            } elseif ($code === 'OVERTIME') {
                $byDept[$name]['overtime'] = $amount;
            } elseif ($code === 'HOLIDAY') {
                $byDept[$name]['holiday'] = $amount;
            } elseif ($code === 'ALLOWANCE') {
                $byDept[$name]['allowances'] = $amount;
            }
        }

        $departments = array_keys($byDept);

        return [
            'departments' => $departments,
            'regular' => array_map(fn ($name) => $byDept[$name]['regular'], $departments),
            'overtime' => array_map(fn ($name) => $byDept[$name]['overtime'], $departments),
            'holiday' => array_map(fn ($name) => $byDept[$name]['holiday'], $departments),
            'allowances' => array_map(fn ($name) => $byDept[$name]['allowances'], $departments),
        ];
    }

    /**
     * @param  Collection<int, Payroll>  $currentPayrollRows
     * @return list<array{name: string, value: float}>
     */
    private function deductionsMix(Collection $currentPayrollRows): array
    {
        $tax = round((float) $currentPayrollRows->sum('incomeTaxAmount'), 2);
        $social = round((float) $currentPayrollRows->sum('employeeSocialSecurityAmount'), 2);
        $other = round((float) $currentPayrollRows->sum('totalDeductions'), 2);

        return array_values(array_filter([
            ['name' => 'Tax', 'value' => $tax],
            ['name' => 'Social', 'value' => $social],
            ['name' => 'Other', 'value' => $other],
        ], fn (array $item) => $item['value'] > 0));
    }

    /**
     * @param  Collection<int, Payroll>  $currentPayrollRows
     * @param  Collection<int, Payroll>  $ytdPayrollRows
     * @return array{departments: list<string>, current: list<float>, ytd: list<float>}
     */
    private function ytdVsCurrentByDepartment(Collection $currentPayrollRows, Collection $ytdPayrollRows): array
    {
        $departmentIds = $currentPayrollRows->pluck('departmentId')
            ->merge($ytdPayrollRows->pluck('departmentId'))
            ->filter()
            ->unique()
            ->values();

        $names = $departmentIds->isEmpty()
            ? collect()
            : DB::table('department')->whereIn('id', $departmentIds)->pluck('name', 'id');

        $sumByDept = function (Collection $rows) use ($names): array {
            $map = [];
            foreach ($rows->groupBy(fn (Payroll $row) => (string) ($row->departmentId ?? 'unassigned')) as $key => $group) {
                $departmentId = $key === 'unassigned' ? null : (int) $key;
                $name = $departmentId !== null
                    ? (string) ($names->get($departmentId) ?? 'Unassigned')
                    : 'Unassigned';
                $map[$name] = round((float) $group->sum(fn ($row) => (float) ($row->taxableGross ?? $row->grossSalary ?? 0)), 2);
            }

            return $map;
        };

        $currentMap = $sumByDept($currentPayrollRows);
        $ytdMap = $sumByDept($ytdPayrollRows);
        $departments = collect(array_unique([...array_keys($currentMap), ...array_keys($ytdMap)]))
            ->sort()
            ->values()
            ->all();

        return [
            'departments' => $departments,
            'current' => array_map(fn ($name) => $currentMap[$name] ?? 0.0, $departments),
            'ytd' => array_map(fn ($name) => $ytdMap[$name] ?? 0.0, $departments),
        ];
    }

    /**
     * @return array{weeks: list<string>, departments: list<string>, cells: list<array{0: int, 1: int, 2: float}>}
     */
    private function overtimeHeatmap(?PayrollRun $currentRun): array
    {
        if (! $currentRun?->payPeriodSchedule?->start_date || ! $currentRun->payPeriodSchedule?->end_date) {
            return ['weeks' => [], 'departments' => [], 'cells' => []];
        }

        $start = Carbon::parse($currentRun->payPeriodSchedule->start_date)->startOfDay();
        $end = Carbon::parse($currentRun->payPeriodSchedule->end_date)->endOfDay();

        $rows = Timesheet::query()
            ->leftJoin('department', 'department.id', '=', 'timesheet.departmentId')
            ->whereBetween('timesheet.date', [$start->toDateString(), $end->toDateString()])
            ->whereRaw('UPPER(COALESCE(timesheet."approvalStatus", \'\')) = ?', ['APPROVED'])
            ->where('timesheet.overtimeHours', '>', 0)
            ->select([
                'timesheet.date',
                'timesheet.departmentId',
                DB::raw('COALESCE(department.name, \'Unassigned\') as department_name'),
                DB::raw('SUM(timesheet."overtimeHours") as ot_hours'),
            ])
            ->groupBy('timesheet.date', 'timesheet.departmentId', DB::raw('COALESCE(department.name, \'Unassigned\')'))
            ->get();

        if ($rows->isEmpty()) {
            return ['weeks' => [], 'departments' => [], 'cells' => []];
        }

        $weekStarts = [];
        $cursor = $start->copy()->startOfWeek(Carbon::MONDAY);
        while ($cursor->lte($end)) {
            $weekStarts[] = $cursor->copy();
            $cursor->addWeek();
        }

        $weeks = [];
        foreach ($weekStarts as $index => $weekStart) {
            $weeks[] = 'W'.($index + 1);
        }

        $departments = $rows->pluck('department_name')->unique()->sort()->values()->all();
        $deptIndex = array_flip($departments);
        $cells = [];
        $matrix = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->date);
            $weekIndex = null;
            foreach ($weekStarts as $index => $weekStart) {
                if ($date->betweenIncluded($weekStart, $weekStart->copy()->endOfWeek(Carbon::SUNDAY))) {
                    $weekIndex = $index;
                    break;
                }
            }
            if ($weekIndex === null) {
                continue;
            }

            $dept = (string) $row->department_name;
            $key = $weekIndex.'|'.$dept;
            $matrix[$key] = round(($matrix[$key] ?? 0) + (float) $row->ot_hours, 2);
        }

        foreach ($matrix as $key => $hours) {
            [$weekIndex, $dept] = explode('|', $key, 2);
            $cells[] = [(int) $weekIndex, (int) $deptIndex[$dept], $hours];
        }

        return [
            'weeks' => $weeks,
            'departments' => $departments,
            'cells' => $cells,
        ];
    }
}
