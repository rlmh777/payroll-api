<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Employee;
use App\Modules\Payroll\Services\EmployeeHoursBankService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeHoursBankController extends Controller
{
    public function __construct(
        private readonly EmployeeHoursBankService $hoursBankService,
    ) {
    }

    public function show(Employee $employee, Request $request): JsonResponse
    {
        $summary = $this->hoursBankService->summary(
            (string) $employee->id,
            (int) $request->get('ledger_limit', 50),
        );

        return response()->json([
            'employeeId' => $employee->id,
            ...$summary,
        ]);
    }

    public function adjust(Employee $employee, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hours_delta' => ['required', 'numeric'],
            'entry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $bank = $this->hoursBankService->adjust(
            (string) $employee->id,
            (float) $validated['hours_delta'],
            Carbon::parse($validated['entry_date'] ?? now())->startOfDay(),
            $validated['notes'] ?? null,
        );

        return response()->json([
            'message' => 'Hours bank adjusted successfully',
            'balanceHours' => round((float) $bank->balance_hours, 4),
            'data' => $this->hoursBankService->summary((string) $employee->id),
        ]);
    }

    public function applyToLeave(Employee $employee, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_leave_id' => ['required', 'uuid', 'exists:employee_leave,id'],
            'leave_hours' => ['required', 'numeric', 'min:0'],
            'entry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $result = $this->hoursBankService->applyToLeave(
            (string) $employee->id,
            (string) $validated['employee_leave_id'],
            (float) $validated['leave_hours'],
            Carbon::parse($validated['entry_date'] ?? now())->startOfDay(),
            $validated['notes'] ?? null,
        );

        return response()->json([
            'message' => 'Hours bank applied to leave',
            ...$result,
        ]);
    }
}
