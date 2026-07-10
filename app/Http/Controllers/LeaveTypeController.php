<?php

namespace App\Http\Controllers;

use App\Enums\LeaveAccrualMethod;
use App\Models\LeaveType;
use App\Models\LeaveTypePolicy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LeaveType::query()->with('policy');

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('isActive')) {
            $query->where('isActive', $request->boolean('isActive'));
        }

        $sortField = $request->input('sortBy', 'sortOrder');
        $sortDirection = $request->input('sortDirection', 'asc');
        $query->orderBy($sortField, $sortDirection)->orderBy('name');

        return response()->json($query->paginate((int) $request->input('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $leaveType = LeaveType::create([
            'name' => trim($validated['name']),
            'code' => strtoupper(trim($validated['code'])),
            'isPaid' => (bool) ($validated['isPaid'] ?? true),
            'affectsBalance' => (bool) ($validated['affectsBalance'] ?? true),
            'requiresCertification' => (bool) ($validated['requiresCertification'] ?? false),
            'isActive' => (bool) ($validated['isActive'] ?? true),
            'sortOrder' => (int) ($validated['sortOrder'] ?? 0),
        ]);

        LeaveTypePolicy::create([
            'leaveTypeId' => $leaveType->id,
            'annualEntitlementDays' => (float) ($validated['annualEntitlementDays'] ?? 0),
            'accrualMethod' => $validated['accrualMethod'] ?? LeaveAccrualMethod::Upfront->value,
            'isEnabled' => (bool) ($validated['policyEnabled'] ?? true),
        ]);

        return response()->json($leaveType->load('policy'), 201);
    }

    public function show(LeaveType $leaveType): JsonResponse
    {
        return response()->json($leaveType->load('policy'));
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validated = $request->validate($this->rules(partial: true, leaveType: $leaveType));

        $leaveTypeData = collect($validated)->only([
            'name', 'code', 'isPaid', 'affectsBalance', 'requiresCertification', 'isActive', 'sortOrder',
        ])->filter(fn ($value, $key) => array_key_exists($key, $validated))->all();

        if (isset($leaveTypeData['name'])) {
            $leaveTypeData['name'] = trim((string) $leaveTypeData['name']);
        }
        if (isset($leaveTypeData['code'])) {
            $leaveTypeData['code'] = strtoupper(trim((string) $leaveTypeData['code']));
        }

        if (!empty($leaveTypeData)) {
            $leaveType->update($leaveTypeData);
        }

        $policyFields = collect($validated)->only([
            'annualEntitlementDays', 'accrualMethod', 'policyEnabled',
        ]);

        if ($policyFields->isNotEmpty()) {
            $policy = LeaveTypePolicy::query()->firstOrCreate(
                ['leaveTypeId' => $leaveType->id],
                [
                    'annualEntitlementDays' => 0,
                    'accrualMethod' => LeaveAccrualMethod::None->value,
                    'isEnabled' => true,
                ],
            );

            $policyPayload = [];
            if (array_key_exists('annualEntitlementDays', $validated)) {
                $policyPayload['annualEntitlementDays'] = (float) $validated['annualEntitlementDays'];
            }
            if (array_key_exists('accrualMethod', $validated)) {
                $policyPayload['accrualMethod'] = $validated['accrualMethod'];
            }
            if (array_key_exists('policyEnabled', $validated)) {
                $policyPayload['isEnabled'] = (bool) $validated['policyEnabled'];
            }

            if (!empty($policyPayload)) {
                $policy->update($policyPayload);
            }
        }

        return response()->json($leaveType->fresh()->load('policy'));
    }

    public function destroy(LeaveType $leaveType): JsonResponse
    {
        if ($leaveType->leaves()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a leave type that has booked leave records.',
            ], 422);
        }

        $leaveType->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false, ?LeaveType $leaveType = null): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $codeRule = Rule::unique('leave_type', 'code');
        $nameRule = Rule::unique('leave_type', 'name');

        if ($leaveType) {
            $codeRule = $codeRule->ignore($leaveType->id);
            $nameRule = $nameRule->ignore($leaveType->id);
        }

        return [
            'name' => [$required, 'string', 'max:255', $nameRule],
            'code' => [$required, 'string', 'max:32', 'alpha_dash', $codeRule],
            'isPaid' => ['boolean'],
            'affectsBalance' => ['boolean'],
            'requiresCertification' => ['boolean'],
            'isActive' => ['boolean'],
            'sortOrder' => ['integer', 'min:0'],
            'annualEntitlementDays' => ['nullable', 'numeric', 'min:0'],
            'accrualMethod' => ['nullable', Rule::in(LeaveAccrualMethod::values())],
            'policyEnabled' => ['boolean'],
        ];
    }
}
