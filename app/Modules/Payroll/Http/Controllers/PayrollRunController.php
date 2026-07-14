<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\PayrollRun;
use App\Models\PayPeriodSchedule;
use App\Services\AiSqlGeneratorService;
use App\Modules\Payroll\Services\PayrollRunCalculationService;
use App\Modules\Payroll\Services\PayrollRunFrequencyResolver;
use App\Modules\Payroll\Services\PayrollRunJournalEntryReportService;
use App\Modules\Payroll\Services\PayrollJournalDepartmentsReportService;
use App\Modules\Payroll\Services\PayrollSummaryByDepartmentReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PayrollRunController extends Controller
{
    public function __construct(
        private readonly AiSqlGeneratorService $aiService,
        private readonly PayrollRunCalculationService $payrollRunCalculationService,
        private readonly PayrollRunFrequencyResolver $payrollRunFrequencyResolver,
        private readonly PayrollRunJournalEntryReportService $payrollRunJournalEntryReportService,
        private readonly PayrollJournalDepartmentsReportService $payrollJournalDepartmentsReportService,
        private readonly PayrollSummaryByDepartmentReportService $payrollSummaryByDepartmentReportService,
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            PayrollRun::query()
                ->with(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency'])
                ->latest('created_at')
                ->paginate($validated['per_page'] ?? 15)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'pay_period_schedule_id' => ['required', 'uuid', 'exists:pay_period_schedule,id'],
            'payrate_frequency_id' => ['nullable', 'integer', 'exists:payrate_frequency,id'],
            'status' => ['sometimes', 'in:draft,posted'],
        ]);

        if (!isset($data['status'])) {
            $data['status'] = 'draft';
        }

        if (!isset($data['payrate_frequency_id'])) {
            $schedule = PayPeriodSchedule::query()
                ->with('payPeriodGroup')
                ->find($data['pay_period_schedule_id']);

            $resolvedFrequencyId = $this->payrollRunFrequencyResolver->resolveForRun(
                new PayrollRun($data),
                $schedule,
            );

            if ($resolvedFrequencyId !== null) {
                $data['payrate_frequency_id'] = $resolvedFrequencyId;
            }
        }

        try {
            DB::beginTransaction();

            $payrollRun = PayrollRun::create($data);

            $payrollRun->load('payPeriodSchedule.payPeriodGroup');

            $this->generateNextPayPeriodSchedule($payrollRun);

            DB::commit();

            return response()->json(
                $payrollRun->load(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency']),
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating payroll run', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            return response()->json([
                'error' => 'Failed to create payroll run: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Generate the next pay period schedule automatically
     */
    private function generateNextPayPeriodSchedule(PayrollRun $payrollRun): void
    {
        try {
            $currentSchedule = $payrollRun->payPeriodSchedule;
            if (!$currentSchedule) {
                return;
            }

            $payPeriodGroup = $currentSchedule->payPeriodGroup;
            if (!$payPeriodGroup || !$payPeriodGroup->rules) {
                Log::info('Cannot generate next pay period schedule: no rules in pay period group', [
                    'payroll_run_id' => $payrollRun->id,
                    'pay_period_group_id' => $payPeriodGroup?->id,
                ]);
                return;
            }

            $existingSchedules = PayPeriodSchedule::where('pay_period_group_id', $payPeriodGroup->id)
                ->orderBy('start_date', 'desc')
                ->limit(5)
                ->get()
                ->toArray();

            if (empty($existingSchedules)) {
                Log::warning('No existing schedules found for pattern analysis', [
                    'pay_period_group_id' => $payPeriodGroup->id,
                ]);
                return;
            }

            $result = $this->aiService->generateNextPayPeriodSchedule(
                $existingSchedules,
                $payPeriodGroup->rules,
                $payPeriodGroup->id
            );

            if (!$result['success'] || !isset($result['fields'])) {
                Log::error('Failed to generate next pay period schedule', [
                    'error' => $result['error'] ?? 'Unknown error',
                    'pay_period_group_id' => $payPeriodGroup->id,
                ]);
                return;
            }

            $fields = $result['fields'];
            unset($fields['id'], $fields['created_at'], $fields['updated_at'], $fields['payrate_frequency_id']);

            PayPeriodSchedule::create($fields);

            Log::info('Next pay period schedule generated automatically', [
                'pay_period_group_id' => $payPeriodGroup->id,
                'fields' => $fields,
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating next pay period schedule', [
                'error' => $e->getMessage(),
                'payroll_run_id' => $payrollRun->id,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function show(PayrollRun $payrollRun)
    {
        return response()->json(
            $payrollRun->load([
                'payPeriodSchedule.payPeriodGroup',
                'payrateFrequency',
                'payrolls.employee',
                'payrolls.department',
                'payrolls.earningLines.earningCode',
            ])
        );
    }

    public function employeeSummary(PayrollRun $payrollRun)
    {
        return response()->json(
            $this->payrollRunCalculationService->calculate($payrollRun),
        );
    }

    public function journalEntriesReport(PayrollRun $payrollRun)
    {
        try {
            return response()->json(
                $this->payrollRunJournalEntryReportService->build($payrollRun),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function payrollSummaryByDepartmentReport(PayrollRun $payrollRun)
    {
        try {
            return response()->json(
                $this->payrollSummaryByDepartmentReportService->build($payrollRun),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function payrollJournalDepartmentsReport(PayrollRun $payrollRun)
    {
        try {
            return response()->json(
                $this->payrollJournalDepartmentsReportService->build($payrollRun),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function update(Request $request, PayrollRun $payrollRun)
    {
        $data = $request->validate([
            'pay_period_schedule_id' => ['sometimes', 'required', 'uuid', 'exists:pay_period_schedule,id'],
            'payrate_frequency_id' => ['nullable', 'integer', 'exists:payrate_frequency,id'],
            'status' => ['sometimes', 'required', 'in:draft,posted'],
        ]);

        $payrollRun->update($data);

        return response()->json(
            $payrollRun->load(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency'])
        );
    }

    public function destroy(PayrollRun $payrollRun)
    {
        $payrollRun->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
