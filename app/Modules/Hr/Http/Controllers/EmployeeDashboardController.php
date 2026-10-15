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
