<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Employee;
use App\Models\LoanType;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LoanController extends Controller
{
    public function index(Request $request)
    {
        $query = Loan::with([
            'employee',
            'loanType',
            'chartOfAccount'
        ]);

        // Filter by employee
        if ($request->has('employeeId')) {
            $query->where('employeeId', $request->input('employeeId'));
        }

        // Filter by loan type
        if ($request->has('loanTypeId')) {
            $query->where('loanTypeId', $request->input('loanTypeId'));
        }

        // Filter by chart of account
        if ($request->has('chartOfAccountId')) {
            $query->where('chartOfAccountId', $request->input('chartOfAccountId'));
        }

        // Filter by interest type
        if ($request->has('interestType')) {
            $query->where('interestType', $request->input('interestType'));
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
            'loanTypeId' => ['required', 'exists:loan_type,id'],
            'loanAmount' => ['required', 'numeric', 'min:0'],
            'interestType' => ['required', 'in:Compound Interest,Simple Interest'],
            'annualInterestRate' => ['required', 'numeric', 'min:0'],
            'loanPeriods' => ['required', 'integer', 'min:1'],
            'optionalExtraPayment' => ['required', 'numeric', 'min:0'],
            'chartOfAccountId' => ['required', 'uuid', 'exists:chart_of_account,id'],
            'note' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $loan = Loan::create($request->all());

        return response()->json($loan->load([
            'employee',
            'loanType',
            'chartOfAccount'
        ]), 201);
    }

    public function show(Loan $loan)
    {
        return $loan->load([
            'employee',
            'loanType',
            'chartOfAccount'
        ]);
    }

    public function update(Request $request, Loan $loan)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json([
                'message' => 'No data provided for update'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => ['sometimes', 'uuid', 'exists:employee,id'],
            'loanTypeId' => ['sometimes', 'exists:loan_type,id'],
            'loanAmount' => ['sometimes', 'numeric', 'min:0'],
            'interestType' => ['sometimes', 'in:Compound Interest,Simple Interest'],
            'annualInterestRate' => ['sometimes', 'numeric', 'min:0'],
            'loanPeriods' => ['sometimes', 'integer', 'min:1'],
            'optionalExtraPayment' => ['sometimes', 'numeric', 'min:0'],
            'chartOfAccountId' => ['sometimes', 'uuid', 'exists:chart_of_account,id'],
            'note' => ['sometimes', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $loan->update($request->all());

        return response()->json($loan->load([
            'employee',
            'loanType',
            'chartOfAccount'
        ]));
    }

    public function destroy(Loan $loan)
    {
        $loan->delete();
        return response()->json(null, 204);
    }
} 