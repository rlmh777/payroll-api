<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Modules\Hr\Services\EmployeeDashboardService;
use App\Modules\Payroll\Services\PayrollRunPayslipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class EmployeeDashboardController extends Controller
{
    public function __construct(
        private readonly EmployeeDashboardService $employeeDashboardService,
        private readonly PayrollRunPayslipService $payrollRunPayslipService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'week_start' => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->employeeDashboardService->build($user, $validated['week_start'] ?? null),
        );
    }

    public function leaves(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'leave_type_id' => ['nullable', 'integer'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        if (
            ! empty($validated['from_date'])
            && ! empty($validated['to_date'])
            && $validated['to_date'] < $validated['from_date']
        ) {
            return response()->json([
                'message' => 'The end date must be on or after the start date.',
            ], 422);
        }

        return response()->json([
            'data' => $this->employeeDashboardService->takenLeaves($user, $validated),
        ]);
    }

    public function payslip(Request $request, PayrollRun $payrollRun)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $employee = $user->employee;
        if (! $employee) {
            return response()->json([
                'message' => 'No employee record is linked to your user account.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $payload = $this->payrollRunPayslipService->build(
                $payrollRun,
                'last_name',
                (string) $employee->id,
            );

            return response()->view('payrolls.payslips', $payload);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
