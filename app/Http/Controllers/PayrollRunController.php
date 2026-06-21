<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use App\Models\PayPeriodSchedule;
use App\Services\AiSqlGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollRunController extends Controller
{
    private AiSqlGeneratorService $aiService;

    public function __construct(AiSqlGeneratorService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function index()
    {
        return response()->json(
            PayrollRun::query()
                ->with(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency'])
                ->latest('created_at')
                ->paginate()
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

        if (empty($data['payrate_frequency_id'])) {
            $schedule = PayPeriodSchedule::find($data['pay_period_schedule_id']);
            if ($schedule?->payrate_frequency_id) {
                $data['payrate_frequency_id'] = $schedule->payrate_frequency_id;
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
            unset($fields['id'], $fields['created_at'], $fields['updated_at']);

            if (empty($fields['payrate_frequency_id']) && $currentSchedule->payrate_frequency_id) {
                $fields['payrate_frequency_id'] = $currentSchedule->payrate_frequency_id;
            }

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
            $payrollRun->load(['payPeriodSchedule.payPeriodGroup', 'payrateFrequency'])
        );
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
