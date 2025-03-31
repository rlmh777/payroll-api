<?php

namespace App\Http\Controllers;

use App\Models\HistoricalEmployeeAllowance;
use App\Models\Employee;
use App\Models\Allowance;
use App\Models\ChartOfAccount;
use App\Models\Payroll;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HistoricalEmployeeAllowanceController extends Controller
{
    public function index(Request $request)
    {
        $query = HistoricalEmployeeAllowance::with([
            'employee',
            'allowance',
            'chartOfAccount',
            'payroll'
        ]);

        // Filter by employee
        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        // Filter by allowance
        if ($request->has('allowanceId')) {
            $query->where('allowanceId', $request->input('allowanceId'));
        }

        // Filter by payroll
        if ($request->has('payrollId')) {
            $query->where('payrollId', $request->input('payrollId'));
        }

        // Filter by chart of account
        if ($request->has('chartOfAccountId')) {
            $query->where('chartOfAccountId', $request->input('chartOfAccountId'));
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
            'amount' => ['required', 'numeric', 'min:0'],
            'note' => ['required', 'string'],
            'payrollId' => ['required', 'uuid', 'exists:payroll,id'],
            'allowanceId' => ['required', 'uuid', 'exists:allowance,id'],
            'chartOfAccountId' => ['required', 'uuid', 'exists:chart_of_account,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $historicalEmployeeAllowance = HistoricalEmployeeAllowance::create($request->all());

        return response()->json($historicalEmployeeAllowance->load([
            'employee',
            'allowance',
            'chartOfAccount',
            'payroll'
        ]), 201);
    }

    public function show(HistoricalEmployeeAllowance $historicalEmployeeAllowance)
    {
        return $historicalEmployeeAllowance->load([
            'employee',
            'allowance',
            'chartOfAccount',
            'payroll'
        ]);
    }

    public function update(Request $request, HistoricalEmployeeAllowance $historicalEmployeeAllowance)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['sometimes', 'string'],
            'payrollId' => ['sometimes', 'uuid', 'exists:payroll,id'],
            'allowanceId' => ['sometimes', 'uuid', 'exists:allowance,id'],
            'chartOfAccountId' => ['sometimes', 'uuid', 'exists:chart_of_account,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $historicalEmployeeAllowance->update($request->all());

        return response()->json($historicalEmployeeAllowance->load([
            'employee',
            'allowance',
            'chartOfAccount',
            'payroll'
        ]));
    }

    public function destroy(HistoricalEmployeeAllowance $historicalEmployeeAllowance)
    {
        $historicalEmployeeAllowance->delete();
        return response()->json(null, 204);
    }
} 