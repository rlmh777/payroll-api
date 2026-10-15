<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Enums\LeaveAccrualMethod;
use App\Models\EmploymentDetail;
use App\Models\EmploymentLeaveEntitlement;
use App\Modules\Hr\Services\Leave\LeaveEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmploymentLeaveEntitlementController extends Controller
{
    public function __construct(
        private readonly LeaveEntitlementService $entitlementService,
    ) {
    }

    public function index(EmploymentDetail $employmentDetails): JsonResponse
    {
        return response()->json([
            'data' => $this->entitlementService->contractEntitlementRows($employmentDetails)->values(),
        ]);
    }

    public function sync(Request $request, EmploymentDetail $employmentDetails): JsonResponse
    {
        $validated = $request->validate([
            'entitlements' => ['required', 'array'],
            'entitlements.*.leaveTypeId' => ['required', 'integer', 'exists:leave_type,id'],
            'entitlements.*.annualEntitlementDays' => ['nullable', 'numeric', 'min:0'],
            'entitlements.*.accrualMethod' => ['nullable', Rule::in(LeaveAccrualMethod::values())],
            'entitlements.*.useOrgDefault' => ['boolean'],
        ]);

        foreach ($validated['entitlements'] as $row) {
            $useOrgDefault = (bool) ($row['useOrgDefault'] ?? false);
            $annual = array_key_exists('annualEntitlementDays', $row) && !$useOrgDefault
                ? $row['annualEntitlementDays']
                : null;
            $method = array_key_exists('accrualMethod', $row) && !$useOrgDefault && !empty($row['accrualMethod'])
                ? $row['accrualMethod']
                : null;

            if ($useOrgDefault || ($annual === null && $method === null)) {
                EmploymentLeaveEntitlement::query()
                    ->where('employmentDetailId', $employmentDetails->id)
                    ->where('leaveTypeId', $row['leaveTypeId'])
                    ->delete();
                continue;
            }

            $existing = EmploymentLeaveEntitlement::query()
                ->where('employmentDetailId', $employmentDetails->id)
                ->where('leaveTypeId', $row['leaveTypeId'])
                ->first();

            if ($existing) {
                $existing->update([
                    'annualEntitlementDays' => $annual,
                    'accrualMethod' => $method,
                ]);
                continue;
            }

            EmploymentLeaveEntitlement::create([
                'id' => (string) Str::uuid(),
                'employmentDetailId' => $employmentDetails->id,
                'leaveTypeId' => $row['leaveTypeId'],
                'annualEntitlementDays' => $annual,
                'accrualMethod' => $method,
            ]);
        }

        return response()->json([
            'message' => 'Contract leave entitlements updated.',
            'data' => $this->entitlementService->contractEntitlementRows($employmentDetails)->values(),
        ]);
    }
}
