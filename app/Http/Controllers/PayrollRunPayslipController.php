<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use App\Services\Payroll\PayrollRunPayslipService;
use App\Services\Payroll\PayrollRunProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class PayrollRunPayslipController extends Controller
{
    public function __construct(
        private readonly PayrollRunProcessingService $payrollRunProcessingService,
        private readonly PayrollRunPayslipService $payrollRunPayslipService,
    ) {
    }

    public function process(PayrollRun $payrollRun): JsonResponse
    {
        try {
            $result = $this->payrollRunProcessingService->process($payrollRun);

            return response()->json([
                'message' => 'Payroll run processed successfully.',
                'data' => $result,
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    public function payslips(Request $request, PayrollRun $payrollRun)
    {
        if (strtolower((string) $payrollRun->status) !== 'posted') {
            return response()->json([
                'message' => 'Payslips can only be generated for processed payroll runs.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validate([
            'sort' => ['nullable', 'in:last_name,department_last_name'],
            'employee_id' => ['nullable', 'uuid', 'exists:employee,id'],
            'department_id' => ['nullable', 'integer', 'exists:department,id'],
        ]);

        try {
            $payload = $this->payrollRunPayslipService->build(
                $payrollRun,
                $validated['sort'] ?? 'last_name',
                $validated['employee_id'] ?? null,
                isset($validated['department_id']) ? (int) $validated['department_id'] : null,
            );

            return response()->view('payrolls.payslips', $payload);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
