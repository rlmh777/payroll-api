<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\EmployeePoolPoint;
use App\Models\PayrollRun;
use App\Models\PayrollRunPoolDistribution;
use App\Modules\Payroll\Services\PoolDistributionService;
use App\Modules\Payroll\Services\PayrollRunFrequencyResolver;
use App\Modules\Payroll\Services\PayrollTimesheetScopeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollRunPoolController extends Controller
{
    public function __construct(
        private readonly PoolDistributionService $poolDistributionService,
        private readonly PayrollTimesheetScopeService $payrollTimesheetScopeService,
        private readonly PayrollRunFrequencyResolver $payrollRunFrequencyResolver,
    ) {
    }

    public function totals(PayrollRun $payrollRun): JsonResponse
    {
        return response()->json([
            'payrollRunId' => $payrollRun->id,
            'totals' => $this->poolDistributionService->totalsForRun($payrollRun),
        ]);
    }

    public function saveTotals(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        if ($this->isProcessed($payrollRun)) {
            return response()->json(['message' => 'Cannot update pool totals on a processed payroll run.'], 422);
        }

        $validated = $request->validate([
            'totals' => ['required', 'array'],
            'totals.*.poolDistributionTypeId' => ['required', 'integer', 'exists:pool_distribution_type,id'],
            'totals.*.totalAmount' => ['required', 'numeric', 'min:0'],
            'totals.*.notes' => ['nullable', 'string'],
        ]);

        $totals = $this->poolDistributionService->saveTotals($payrollRun, $validated['totals']);

        return response()->json([
            'message' => 'Pool totals saved',
            'totals' => $totals,
        ]);
    }

    public function saveShares(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        if ($this->isProcessed($payrollRun)) {
            return response()->json(['message' => 'Cannot update pool shares on a processed payroll run.'], 422);
        }

        $validated = $request->validate([
            'poolDistributionTypeId' => ['required', 'integer', 'exists:pool_distribution_type,id'],
            'shares' => ['required', 'array', 'min:1'],
            'shares.*.employeeCode' => ['required', 'string', 'max:64'],
            'shares.*.shares' => ['required', 'numeric', 'gt:0'],
            'shares.*.amount' => ['nullable', 'numeric', 'min:0'],
            'mode' => ['nullable', 'in:replace,amend'],
            'totalAmount' => ['nullable', 'numeric', 'min:0'],
            'sourceName' => ['nullable', 'string', 'max:255'],
        ]);

        $mode = $validated['mode'] ?? 'replace';
        $result = $this->poolDistributionService->replaceShares(
            $payrollRun,
            (int) $validated['poolDistributionTypeId'],
            $validated['shares'],
            $mode,
            isset($validated['totalAmount']) ? (float) $validated['totalAmount'] : null,
            $validated['sourceName'] ?? null,
        );

        $message = $mode === 'amend'
            ? 'Added this file to the employees already loaded. Its total is split only across the employees in this file.'
            : 'Replaced the employees loaded for this pool. This file’s total is split only across the employees in this file.';

        return response()->json([
            'message' => $message,
            ...$result,
            'totals' => $this->poolDistributionService->totalsForRun($payrollRun),
        ]);
    }

    public function distribute(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        if ($this->isProcessed($payrollRun)) {
            return response()->json(['message' => 'Cannot redistribute pools on a processed payroll run.'], 422);
        }

        $employeeIds = $this->resolveEmployeeIds($payrollRun);
        $result = $this->poolDistributionService->distribute($payrollRun, $employeeIds);

        return response()->json([
            'message' => 'Pool amounts distributed',
            ...$result,
        ]);
    }

    public function distributions(PayrollRun $payrollRun, Request $request): JsonResponse
    {
        $query = PayrollRunPoolDistribution::query()
            ->with(['poolDistributionType', 'employee.person', 'department'])
            ->where('payroll_run_id', $payrollRun->id);

        if ($request->filled('pool_distribution_type_id')) {
            $query->where('pool_distribution_type_id', $request->integer('pool_distribution_type_id'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }

        $rows = $query->orderBy('pool_distribution_type_id')->orderBy('employee_id')->get();

        return response()->json([
            'data' => $rows->map(fn (PayrollRunPoolDistribution $row) => $this->presentDistribution($row)),
        ]);
    }

    public function updateDistribution(
        Request $request,
        PayrollRun $payrollRun,
        PayrollRunPoolDistribution $distribution,
    ): JsonResponse {
        if ($this->isProcessed($payrollRun)) {
            return response()->json(['message' => 'Cannot correct a processed payroll run.'], 422);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'paidShares' => ['nullable', 'numeric', 'min:0'],
            'paidPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $updated = $this->poolDistributionService->correctDistribution($payrollRun, $distribution, $validated);
        $poolTotal = (float) PayrollRunPoolDistribution::query()
            ->where('payroll_run_id', $payrollRun->id)
            ->where('pool_distribution_type_id', $updated->pool_distribution_type_id)
            ->sum('amount');

        return response()->json([
            'message' => 'Employee distribution updated.',
            'distribution' => $this->presentDistribution($updated),
            'poolDistributionTypeId' => (int) $updated->pool_distribution_type_id,
            'poolTotal' => round($poolTotal, 2),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDistribution(PayrollRunPoolDistribution $row): array
    {
        $employee = $row->employee;
        $name = trim(collect([
            $employee?->firstName ?? $employee?->person?->firstName,
            $employee?->lastName ?? $employee?->person?->lastName,
        ])->filter()->implode(' '));

        return [
            'id' => $row->id,
            'poolDistributionTypeId' => $row->pool_distribution_type_id,
            'poolName' => $row->poolDistributionType?->name,
            'poolCode' => $row->poolDistributionType?->code,
            'employeeId' => $row->employee_id,
            'employeeName' => $name !== '' ? $name : ($employee?->code ?? $row->employee_id),
            'employeeCode' => $employee?->code,
            'departmentId' => $row->department_id,
            'departmentName' => $row->department?->name,
            'departmentPercent' => $row->department_percent !== null ? (float) $row->department_percent : null,
            'departmentAmount' => $row->department_amount !== null ? (float) $row->department_amount : null,
            'workedThisPeriod' => $row->worked_this_period,
            'points' => (float) $row->points,
            'weight' => (float) $row->weight,
            'paidShares' => $row->paid_shares !== null ? (float) $row->paid_shares : null,
            'paidPercent' => $row->paid_percent !== null ? (float) $row->paid_percent : null,
            'amount' => (float) $row->amount,
            'isEligible' => (bool) $row->is_eligible,
            'eligibilityReason' => $row->eligibility_reason,
            'manuallyCorrected' => (bool) $row->manually_corrected,
        ];
    }

    private function isProcessed(PayrollRun $payrollRun): bool
    {
        $status = strtoupper((string) ($payrollRun->status ?? ''));

        return in_array($status, ['PROCESSED', 'POSTED', 'PAID', 'COMPLETED'], true);
    }

    /**
     * @return list<string>
     */
    private function resolveEmployeeIds(PayrollRun $payrollRun): array
    {
        $payrollRun->loadMissing(['payPeriodSchedule']);
        $schedule = $payrollRun->payPeriodSchedule;
        if (!$schedule?->start_date || !$schedule?->end_date) {
            return [];
        }

        $startDate = Carbon::parse($schedule->start_date)->startOfDay();
        $endDate = Carbon::parse($schedule->end_date)->startOfDay();
        $payPeriodGroupId = (string) $schedule->pay_period_group_id;
        $frequencyId = $this->payrollRunFrequencyResolver->resolveForRun($payrollRun, $schedule);

        $fromTimesheets = collect(
            $this->payrollTimesheetScopeService->employeeIds(
                $startDate,
                $endDate,
                $payPeriodGroupId,
                $frequencyId,
            ),
        );

        $fromPoints = EmployeePoolPoint::query()
            ->whereDate('effective_date', '<=', $endDate->toDateString())
            ->where(function ($query) use ($endDate) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $endDate->toDateString());
            })
            ->pluck('employee_id');

        return $fromTimesheets
            ->merge($fromPoints)
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}
