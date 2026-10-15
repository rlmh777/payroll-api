<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Models\Employee;
use App\Modules\Hr\Services\EmployeeNameSearch;
use App\Modules\Hr\Services\Leave\LeaveEntitlementService;
use App\Modules\Hr\Services\Leave\LeaveSupervisorAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeLeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveEntitlementService $entitlementService,
        private readonly LeaveSupervisorAuthorizationService $authorization,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'asOf' => ['nullable', 'date'],
        ]);

        if (!$this->authorization->canViewEmployeeLeaveBalances($user, $validated['employeeId'])) {
            return response()->json([
                'message' => 'You can only view leave balances for yourself or your subordinates.',
            ], 403);
        }

        $asOf = isset($validated['asOf'])
            ? Carbon::parse($validated['asOf'])->startOfDay()
            : Carbon::today()->startOfDay();

        return response()->json([
            'data' => $this->entitlementService->balancesForEmployee($validated['employeeId'], $asOf),
            'asOf' => $asOf->toDateString(),
        ]);
    }

    public function selectableEmployees(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (!$this->authorization->canAccessLeaveList($user)) {
            return response()->json([
                'message' => 'Leave entitlement is only available to supervisors with subordinates, or leave administrators.',
                'data' => [],
            ], 403);
        }

        $query = Employee::query()->with(['person']);

        $visibleIds = $this->authorization->visibleEmployeeIdsForLeaveList($user);
        if ($visibleIds !== null) {
            if ($visibleIds->isEmpty()) {
                return response()->json(['data' => []]);
            }
            $query->whereIn('id', $visibleIds->all());
        }

        if ($request->filled('search')) {
            EmployeeNameSearch::apply($query, $request->input('search'));
        }

        $employees = $query
            ->orderByPersonName('lastName', 'asc')
            ->limit(200)
            ->get()
            ->map(function (Employee $employee) {
                $lastName = trim((string) ($employee->lastName ?? ''));
                $firstName = trim((string) ($employee->firstName ?? ''));
                $middleName = trim((string) ($employee->middleName ?? ''));
                if ($lastName !== '' && ($firstName !== '' || $middleName !== '')) {
                    $given = trim(implode(' ', array_filter([$firstName, $middleName])));
                    $name = "{$lastName}, {$given}";
                } else {
                    $name = trim(implode(' ', array_filter([$lastName, $firstName, $middleName])))
                        ?: (string) ($employee->code ?? 'Employee');
                }

                return [
                    'id' => $employee->id,
                    'code' => $employee->code,
                    'name' => $name,
                    'firstName' => $firstName,
                    'middleName' => $middleName,
                    'lastName' => $lastName,
                ];
            })
            ->values();

        return response()->json(['data' => $employees]);
    }
}
