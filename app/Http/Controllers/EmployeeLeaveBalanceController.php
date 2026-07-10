<?php

namespace App\Http\Controllers;

use App\Services\Leave\LeaveEntitlementService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeLeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveEntitlementService $entitlementService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'asOf' => ['nullable', 'date'],
        ]);

        $asOf = isset($validated['asOf'])
            ? Carbon::parse($validated['asOf'])->startOfDay()
            : Carbon::today()->startOfDay();

        return response()->json([
            'data' => $this->entitlementService->balancesForEmployee($validated['employeeId'], $asOf),
            'asOf' => $asOf->toDateString(),
        ]);
    }
}
