<?php

namespace App\Http\Controllers;

use App\Models\HistoricalEmployeeDeduction;
use App\Models\Employee;
use App\Models\Vendor;
use App\Models\Payroll;
use App\Models\ChartOfAccount;
use App\Models\DeductionType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HistoricalEmployeeDeductionController extends Controller
{
    public function index(Request $request)
    {
        $query = HistoricalEmployeeDeduction::with([
            'employee',
            'vendor',
            'payroll',
            'chartOfAccount',
            'deductionType'
        ]);

        // Filter by employee
        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        // Filter by payment to vendor
        if ($request->has('paymentToId')) {
            $query->where('paymentToId', $request->input('paymentToId'));
        }

        // Filter by payroll
        if ($request->has('payrollId')) {
            $query->where('payrollId', $request->input('payrollId'));
        }

        // Filter by chart of account
        if ($request->has('chartOfAccountId')) {
            $query->where('chartOfAccountId', $request->input('chartOfAccountId'));
        }

        // Filter by deduction type
        if ($request->has('deductionTypeId')) {
            $query->where('deductionTypeId', $request->input('deductionTypeId'));
        }

        // Sort
        if ($request->has('sortBy')) {
            $sortDirection = $request->input('sortDirection', 'asc');
            $query->orderBy($request->input('sortBy'), $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
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
            'payrollId' => ['required', 'uuid', 'exists:payroll,id'],
            'chartOfAccountId' => ['required', 'uuid', 'exists:chart_of_account,id'],
            'deductionTypeId' => ['required', 'exists:deduction_type,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $historicalEmployeeDeduction = HistoricalEmployeeDeduction::create($request->all());

        return response()->json($historicalEmployeeDeduction->load([
            'employee',
            'vendor',
            'payroll',
            'chartOfAccount',
            'deductionType'
        ]), 201);
    }

    public function show(HistoricalEmployeeDeduction $historicalEmployeeDeduction)
    {
        return $historicalEmployeeDeduction->load([
            'employee',
            'vendor',
            'payroll',
            'chartOfAccount',
            'deductionType'
        ]);
    }

    public function update(Request $request, HistoricalEmployeeDeduction $historicalEmployeeDeduction)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'paymentToId' => ['sometimes', 'uuid', 'exists:vendor,id'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['sometimes', 'string'],
            'payrollId' => ['sometimes', 'uuid', 'exists:payroll,id'],
            'chartOfAccountId' => ['sometimes', 'uuid', 'exists:chart_of_account,id'],
            'deductionTypeId' => ['sometimes', 'exists:deduction_type,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $historicalEmployeeDeduction->update($request->all());

        return response()->json($historicalEmployeeDeduction->load([
            'employee',
            'vendor',
            'payroll',
            'chartOfAccount',
            'deductionType'
        ]));
    }

    public function destroy(HistoricalEmployeeDeduction $historicalEmployeeDeduction)
    {
        $historicalEmployeeDeduction->delete();
        return response()->json(null, 204);
    }
}