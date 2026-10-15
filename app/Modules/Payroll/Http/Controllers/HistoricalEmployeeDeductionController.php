<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\HistoricalEmployeeDeduction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HistoricalEmployeeDeductionController extends Controller
{
    private const RELATIONS = [
        'employee',
        'vendor',
        'payrollRun',
        'chartOfAccount',
        'deductionType',
    ];

    public function index(Request $request)
    {
        $query = HistoricalEmployeeDeduction::with(self::RELATIONS);

        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        if ($request->has('paymentToId')) {
            $query->where('paymentToId', $request->input('paymentToId'));
        }

        if ($request->has('payroll_run_id')) {
            $query->where('payroll_run_id', $request->input('payroll_run_id'));
        }

        if ($request->has('accountId')) {
            $query->where('accountId', $request->input('accountId'));
        }

        if ($request->has('deductionTypeId')) {
            $query->where('deductionTypeId', $request->input('deductionTypeId'));
        }

        if ($request->has('sortBy')) {
            $sortDirection = $request->input('sortDirection', 'asc');
            $query->orderBy($request->input('sortBy'), $sortDirection);
        } else {
            $query->orderBy('priority', 'asc')->orderBy('created_at', 'desc');
        }

        return $query->paginate($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => ['required', 'uuid', 'exists:employee,id'],
            'paymentToId' => ['required', 'uuid', 'exists:vendor,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'note' => ['required', 'string'],
            'payroll_run_id' => ['required', 'uuid', 'exists:payroll_runs,id'],
            'accountId' => ['required', 'uuid', 'exists:accounts,id'],
            'deductionTypeId' => ['required', 'exists:deduction_type,id'],
            'carryForwardShortfall' => ['sometimes', 'numeric', 'min:0'],
            'priority' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $historicalEmployeeDeduction = HistoricalEmployeeDeduction::create($validator->validated());

        return response()->json($historicalEmployeeDeduction->load(self::RELATIONS), 201);
    }

    public function show(HistoricalEmployeeDeduction $historicalEmployeeDeduction)
    {
        return $historicalEmployeeDeduction->load(self::RELATIONS);
    }

    public function update(Request $request, HistoricalEmployeeDeduction $historicalEmployeeDeduction)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'paymentToId' => ['sometimes', 'uuid', 'exists:vendor,id'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['sometimes', 'string'],
            'payroll_run_id' => ['sometimes', 'uuid', 'exists:payroll_runs,id'],
            'accountId' => ['sometimes', 'uuid', 'exists:accounts,id'],
            'deductionTypeId' => ['sometimes', 'exists:deduction_type,id'],
            'carryForwardShortfall' => ['sometimes', 'numeric', 'min:0'],
            'priority' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $historicalEmployeeDeduction->update($validator->validated());

        return response()->json($historicalEmployeeDeduction->load(self::RELATIONS));
    }

    public function destroy(HistoricalEmployeeDeduction $historicalEmployeeDeduction)
    {
        $historicalEmployeeDeduction->delete();

        return response()->json(null, 204);
    }
}
