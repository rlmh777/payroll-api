<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\PayrollAccountMapping;
use App\Modules\Payroll\Services\PayrollAccountMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollAccountMappingController extends Controller
{
    public function __construct(
        private readonly PayrollAccountMappingService $payrollAccountMappingService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->payrollAccountMappingService->all($request->boolean('active_only')),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:payroll_account_mappings,code'],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $mapping = PayrollAccountMapping::create($validated);
        $this->payrollAccountMappingService->forgetCache();

        return response()->json([
            'message' => 'Account mapping created successfully',
            'data' => $mapping->load('account'),
        ], 201);
    }

    public function show(PayrollAccountMapping $payrollAccountMapping): JsonResponse
    {
        return response()->json($payrollAccountMapping->load('account'));
    }

    public function update(Request $request, PayrollAccountMapping $payrollAccountMapping): JsonResponse
    {
        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:64',
                Rule::unique('payroll_account_mappings', 'code')->ignore($payrollAccountMapping->id),
            ],
            'name' => ['sometimes', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper(trim($validated['code']));
        }

        $payrollAccountMapping->update($validated);
        $this->payrollAccountMappingService->forgetCache();

        return response()->json([
            'message' => 'Account mapping updated successfully',
            'data' => $payrollAccountMapping->fresh()->load('account'),
        ]);
    }

    public function destroy(PayrollAccountMapping $payrollAccountMapping): JsonResponse
    {
        $payrollAccountMapping->delete();
        $this->payrollAccountMappingService->forgetCache();

        return response()->json(['message' => 'Account mapping deleted successfully']);
    }
}
