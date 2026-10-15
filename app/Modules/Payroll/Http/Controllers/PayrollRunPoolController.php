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
            'data' => $rows->map(fn (PayrollRunPoolDistribution $row) => [
                'id' => $row->id,
                'poolDistributionTypeId' => $row->pool_distribution_type_id,
                'poolName' => $row->poolDistributionType?->name,
                'employeeId' => $row->employee_id,
                'employeeName' => trim(collect([
                    $row->employee?->firstName,
                    $row->employee?->lastName,
                ])->filter()->implode(' ')),
                'employeeCode' => $row->employee?->code,
                'departmentId' => $row->department_id,
                'departmentName' => $row->department?->name,
                'departmentPercent' => $row->department_percent !== null ? (float) $row->department_percent : null,
                'departmentAmount' => $row->department_amount !== null ? (float) $row->department_amount : null,
                'workedThisPeriod' => $row->worked_this_period,
                'points' => (float) $row->points,
                'weight' => (float) $row->weight,
                'amount' => (float) $row->amount,
                'isEligible' => (bool) $row->is_eligible,
                'eligibilityReason' => $row->eligibility_reason,
            ]),
        ]);
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
