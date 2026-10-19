<?php

namespace App\Modules\Payroll\Services;

use App\Models\EmployeeDefaultDeduction;
use App\Models\HistoricalEmployeeDeduction;
use App\Models\Payroll;
use App\Models\PayrollEarningLine;
use App\Models\PayrollRun;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class RegistrySummaryByOfficeDepartmentReportService
{
    public const PERIOD_DATES = 'dates';

    public const PERIOD_PAYROLL_RUN = 'payroll_run';

    public const PROCESS_TYPE = 'Regular';

    public function __construct(
        private readonly PayrollRunOccurrenceMatcher $occurrenceMatcher,
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

        $payrolls = Payroll::query()
            ->with(['department.parent', 'payrollRun.payPeriodSchedule'])
            ->whereHas('payrollRun', function ($query) {
                $query->whereRaw("LOWER(COALESCE(status, '')) = 'posted'");
            })
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereHas('payrollRun.payPeriodSchedule', function ($schedule) use ($startDate, $endDate) {
                    $schedule->whereDate('start_date', '<=', $endDate->toDateString())
                        ->whereDate('end_date', '>=', $startDate->toDateString());
                })->orWhereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
            })
            ->get();

        return $this->present($payrolls, self::PERIOD_DATES, $startDate, $endDate, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFromPayrollRun(PayrollRun $payrollRun): array
    {
        $payrollRun->loadMissing('payPeriodSchedule');

        if (strtolower((string) $payrollRun->status) !== 'posted') {
            throw new InvalidArgumentException('Registry summary can only be generated for posted payroll.');
        }

        $payrolls = Payroll::query()
            ->with(['department.parent', 'payrollRun.payPeriodSchedule'])
            ->where('payroll_run_id', $payrollRun->id)
            ->get();

        $schedule = $payrollRun->payPeriodSchedule;
        $startDate = Carbon::parse($schedule?->start_date ?? $payrolls->min('date') ?? Carbon::today())->startOfDay();
        $endDate = Carbon::parse($schedule?->end_date ?? $payrolls->max('date') ?? $startDate)->startOfDay();

        return $this->present($payrolls, self::PERIOD_PAYROLL_RUN, $startDate, $endDate, $payrollRun);
    }

    /**
     * @param Collection<int, Payroll> $payrolls
     * @return array<string, mixed>
     */
    private function present(
        Collection $payrolls,
        string $period,
        Carbon $startDate,
        Carbon $endDate,
        ?PayrollRun $payrollRun,
    ): array {
        $earningLines = $this->earningLinesByPayroll($payrolls);
        $deductions = $this->deductionSplits($payrolls);
        $groups = [];

        foreach ($payrolls as $payroll) {
            $amounts = $this->amountsForPayroll(
                $payroll,
                $earningLines->get((string) $payroll->id, collect()),
                $deductions[(string) $payroll->id] ?? ['pretax' => 0.0, 'sick' => 0.0],
            );
            $departmentId = $payroll->departmentId !== null ? (int) $payroll->departmentId : 0;
            $runId = (string) ($payroll->payroll_run_id ?? '');
            $key = $runId.'|'.$departmentId;
            $departmentName = trim((string) ($payroll->department?->name ?? ''));
            $branchName = trim((string) ($payroll->department?->parent?->name ?? ''));
            if ($departmentName === '') {
                $departmentName = 'Unassigned';
            }
            if ($branchName === '') {
                $branchName = $departmentName;
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'payrollRunId' => $runId,
                    'payrollNumber' => (int) ($payroll->payrollRun?->payroll_number ?? $payrollRun?->payroll_number ?? 0),
                    'branch' => $branchName,
                    'departmentId' => $payroll->departmentId !== null ? (int) $payroll->departmentId : null,
                    'department' => $departmentName,
                    'processType' => self::PROCESS_TYPE,
                    ...$this->emptyAmounts(),
                ];
            }

            foreach (array_keys($this->emptyAmounts()) as $amountKey) {
                $groups[$key][$amountKey] = round($groups[$key][$amountKey] + $amounts[$amountKey], 2);
            }
        }

        $rows = array_values($groups);
        usort($rows, function (array $left, array $right) {
            return [
                $left['payrollNumber'],
                mb_strtolower($left['branch']),
                mb_strtolower($left['department']),
            ] <=> [
                $right['payrollNumber'],
                mb_strtolower($right['branch']),
                mb_strtolower($right['department']),
            ];
        });

        return [
            'title' => 'Registry Summary By Office & Department Report',
            'period' => $period,
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'periodLabel' => $this->formatRange($startDate, $endDate),
            'payrollRunId' => $payrollRun?->id,
            'payrollNumber' => $payrollRun?->payroll_number,
            'rows' => $rows,
            'totals' => $this->sumAmounts($rows),
        ];
    }

    /**
     * @param Collection<int, Payroll> $payrolls
     * @return Collection<string, Collection<int, PayrollEarningLine>>
     */
    private function earningLinesByPayroll(Collection $payrolls): Collection
    {
        $ids = $payrolls->pluck('id')->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return PayrollEarningLine::query()
            ->with('earningCode')
            ->whereIn('payroll_id', $ids->all())
            ->get()
            ->groupBy(fn (PayrollEarningLine $line) => (string) $line->payroll_id);
    }

    /**
     * @param Collection<int, Payroll> $payrolls
     * @return array<string, array{pretax: float, sick: float}>
     */
    private function deductionSplits(Collection $payrolls): array
    {
        if ($payrolls->isEmpty()) {
            return [];
        }

        $employeeIds = $payrolls->pluck('employeeId')->filter()->unique()->values();
        $runIds = $payrolls->pluck('payroll_run_id')->filter()->unique()->values();

        $defaults = EmployeeDefaultDeduction::query()
            ->with('deductionType')
            ->whereIn('employeeId', $employeeIds->all())
            ->get()
            ->groupBy(fn (EmployeeDefaultDeduction $row) => (string) $row->employeeId);

        $historical = HistoricalEmployeeDeduction::query()
            ->with('deductionType')
            ->whereIn('payroll_run_id', $runIds->all())
            ->get()
            ->groupBy(fn (HistoricalEmployeeDeduction $row) => (string) $row->payroll_run_id.'|'.(string) $row->employee_id);

        $splits = [];
        foreach ($payrolls as $payroll) {
            $context = $this->occurrenceMatcher->contextForRunId((string) $payroll->payroll_run_id);
            $pretax = 0.0;
            $sick = 0.0;

            foreach ($defaults->get((string) $payroll->employeeId, collect()) as $default) {
                if (! $this->occurrenceMatcher->matchesAssignment($default, $context)) {
                    continue;
                }
                $this->addDeduction($pretax, $sick, $default->deductionType?->name, $default->deductionType?->category, (float) $default->amount);
            }

            $historyKey = (string) $payroll->payroll_run_id.'|'.(string) $payroll->employeeId;
            foreach ($historical->get($historyKey, collect()) as $record) {
                $this->addDeduction($pretax, $sick, $record->deductionType?->name, $record->deductionType?->category, (float) $record->amount);
            }

            $stored = round((float) $payroll->totalDeductions, 2);
            $pretax = round(min($pretax, max(0, $stored)), 2);
            $sick = round(min($sick, max(0, $stored - $pretax)), 2);

            $splits[(string) $payroll->id] = [
                'pretax' => $pretax,
                'sick' => $sick,
            ];
        }

        return $splits;
    }

    private function addDeduction(float &$pretax, float &$sick, mixed $name, mixed $category, float $amount): void
    {
        $kind = $this->deductionKind($name, $category);
        if ($kind === 'pretax') {
            $pretax = round($pretax + $amount, 2);
        } elseif ($kind === 'sick') {
            $sick = round($sick + $amount, 2);
        }
    }

    private function deductionKind(mixed $name, mixed $category): string
    {
        $categoryText = strtoupper(trim((string) ($category ?? '')));
        $blob = strtolower(trim((string) ($name ?? '').' '.(string) ($category ?? '')));

        if (str_contains($blob, 'sick')) {
            return 'sick';
        }

        if (
            in_array($categoryText, ['PRETAX', 'PRE_TAX', 'PRE-TAX'], true)
            || str_contains($blob, 'pretax')
            || str_contains($blob, 'pre-tax')
            || str_contains($blob, 'pre tax')
            || str_contains($blob, 'before tax')
        ) {
            return 'pretax';
        }

        return 'post';
    }

    /**
     * @param Collection<int, PayrollEarningLine> $lines
     * @param array{pretax: float, sick: float} $deductions
     * @return array<string, float>
     */
    private function amountsForPayroll(Payroll $payroll, Collection $lines, array $deductions): array
    {
        $wage = 0.0;
        $overtime = 0.0;
        $other = 0.0;

        foreach ($lines as $line) {
            if ($line->is_taxable === false) {
                continue;
            }
            $amount = (float) $line->amount;
            $bucket = PayrollRegistryReportService::earningBucket(
                $line->earningCode?->source,
                $line->earningCode?->pay_multiplier,
            );
            if ($bucket === 'regular') {
                $wage += $amount;
            } elseif ($bucket === 'overtime' || $bucket === 'doubletime') {
                $overtime += $amount;
            } else {
                $other += $amount;
            }
        }

        if ($lines->isEmpty()) {
            $wage = (float) ($payroll->taxableGross ?? $payroll->grossSalary ?? 0);
            $tipsAndBonus = round((float) $payroll->tipsAmount + (float) $payroll->bonusAmount, 2);
            if ($tipsAndBonus > 0 && $wage >= $tipsAndBonus) {
                $wage = round($wage - $tipsAndBonus, 2);
                $other = $tipsAndBonus;
            }
        }

        $grossTarget = round((float) ($payroll->taxableGross ?? $payroll->grossSalary ?? ($wage + $overtime + $other)), 2);
        $other = round($other + ($grossTarget - ($wage + $overtime + $other)), 2);
        if ($other < 0) {
            $wage = round($wage + $other, 2);
            $other = 0.0;
        }
        $wage = round($wage, 2);
        $overtime = round($overtime, 2);
        $other = round($other, 2);
        $gross = round($wage + $overtime + $other, 2);

        $pretax = round((float) $deductions['pretax'], 2);
        $sick = round((float) $deductions['sick'], 2);
        $storedDeductions = round((float) $payroll->totalDeductions, 2);
        $postTax = round($storedDeductions - $pretax - $sick, 2);
        $tax = round((float) $payroll->incomeTaxAmount, 2);
        $ssEmployee = round((float) $payroll->employeeSocialSecurityAmount, 2);
        $ssEmployer = round((float) $payroll->employerSocialSecurityAmount, 2);
        $net = round((float) $payroll->netSalary, 2);
        $nonTax = round($net - ($gross - $pretax - $sick - $postTax - $tax - $ssEmployee), 2);

        return [
            'wage' => $wage,
            'overtime' => $overtime,
            'pretaxDeduction' => $pretax,
            'sickLeaveDeduction' => $sick,
            'otherEarnings' => $other,
            'grossPay' => $gross,
            'taxes' => $tax,
            'ssEmployee' => $ssEmployee,
            'ssEmployer' => $ssEmployer,
            'postTaxDeduction' => $postTax,
            'nonTaxPayout' => $nonTax,
            'netSalary' => $net,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, float>
     */
    private function sumAmounts(array $rows): array
    {
        $totals = $this->emptyAmounts();
        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + (float) ($row[$key] ?? 0), 2);
            }
        }

        return $totals;
    }

    /**
     * @return array<string, float>
     */
    private function emptyAmounts(): array
    {
        return [
            'wage' => 0.0,
            'overtime' => 0.0,
            'pretaxDeduction' => 0.0,
            'sickLeaveDeduction' => 0.0,
            'otherEarnings' => 0.0,
            'grossPay' => 0.0,
            'taxes' => 0.0,
            'ssEmployee' => 0.0,
            'ssEmployer' => 0.0,
            'postTaxDeduction' => 0.0,
            'nonTaxPayout' => 0.0,
            'netSalary' => 0.0,
        ];
    }

    private function formatRange(Carbon $startDate, Carbon $endDate): string
    {
        return $startDate->format('j-M-Y').' - '.$endDate->format('j-M-Y');
    }
}
