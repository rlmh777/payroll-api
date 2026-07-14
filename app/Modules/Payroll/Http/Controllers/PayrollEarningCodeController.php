<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\PayrollEarningCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollEarningCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PayrollEarningCode::query()->with('account')->orderBy('sort_order');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:payroll_earning_code,code'],
            'name' => ['required', 'string', 'max:128'],
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'is_taxable' => ['boolean'],
            'is_ss_subject' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $code = PayrollEarningCode::create($validated);

        return response()->json([
            'message' => 'Payroll earning code created successfully',
            'data' => $code->load('account'),
        ], 201);
    }

    public function show(PayrollEarningCode $payrollEarningCode): JsonResponse
    {
        return response()->json($payrollEarningCode->load('account'));
    }

    public function update(Request $request, PayrollEarningCode $payrollEarningCode): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:32', Rule::unique('payroll_earning_code', 'code')->ignore($payrollEarningCode->id)],
            'name' => ['sometimes', 'string', 'max:128'],
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'is_taxable' => ['boolean'],
            'is_ss_subject' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $payrollEarningCode->update($validated);

        return response()->json([
            'message' => 'Payroll earning code updated successfully',
            'data' => $payrollEarningCode->fresh()->load('account'),
        ]);
    }

    public function destroy(PayrollEarningCode $payrollEarningCode): JsonResponse
    {
        if ($payrollEarningCode->earningLines()->exists()) {
            return response()->json([
                'message' => 'Cannot delete earning code used on payroll lines',
            ], 422);
        }

        $payrollEarningCode->delete();

        return response()->json(['message' => 'Payroll earning code deleted successfully']);
    }
}
