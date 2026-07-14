<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\EmployeeDefaultAllowance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeDefaultAllowanceController extends Controller
{
    /**
     * Display a listing of employee default allowances.
     */
    public function index(Request $request)
    {
        $query = EmployeeDefaultAllowance::with(['employee', 'allowance', 'payrateFrequency', 'chartOfAccount']);

        // Filter by employee
        if ($request->has('employee_id')) {
            $query->where('employeeId', $request->input('employee_id'));
        }

        // Filter by allowance
        if ($request->has('allowance_id')) {
            $query->where('allowanceId', $request->input('allowance_id'));
        }

        // Filter by frequency
        if ($request->has('frequency_id')) {
            $query->where('frequencyId', $request->input('frequency_id'));
        }

        // Filter by chart of account
        if ($request->has('account_id')) {
            $query->where('accountId', $request->input('account_id'));
        }

        // Search by note
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('note', 'ilike', "%{$search}%");
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate
        $perPage = $request->input('per_page', 10);
        $allowances = $query->paginate($perPage);

        return response()->json($allowances);
    }

    /**
     * Store a newly created employee default allowance.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => 'required|uuid|exists:employee,id',
            'allowanceId' => 'required|uuid|exists:allowance,id',
            'frequencyId' => 'required|exists:payrate_frequency,id',
            'accountId' => 'required|uuid|exists:accounts,id',
            'note' => 'nullable|string|max:1024',
            'amount' => 'required|numeric|min:0|max:999999999999.99'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $allowance = EmployeeDefaultAllowance::create($request->all());

        return response()->json([
            'message' => 'Employee default allowance created successfully',
            'data' => $allowance->load(['employee', 'allowance', 'payrateFrequency', 'chartOfAccount'])
        ], 201);
    }

    /**
     * Display the specified employee default allowance.
     */
    public function show(EmployeeDefaultAllowance $employeeDefaultAllowance)
    {
        return response()->json($employeeDefaultAllowance->load(['employee', 'allowance', 'payrateFrequency', 'chartOfAccount']));
    }

    /**
     * Update the specified employee default allowance.
     */
    public function update(Request $request, EmployeeDefaultAllowance $employeeDefaultAllowance)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => 'uuid|exists:employee,id',
            'allowanceId' => 'uuid|exists:allowance,id',
            'frequencyId' => 'exists:payrate_frequency,id',
            'accountId' => 'uuid|exists:accounts,id',
            'note' => 'nullable|string|max:1024',
            'amount' => 'numeric|min:0|max:999999999999.99'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employeeDefaultAllowance->update($request->all());

        return response()->json([
            'message' => 'Employee default allowance updated successfully',
            'data' => $employeeDefaultAllowance->load(['employee', 'allowance', 'payrateFrequency', 'chartOfAccount'])
        ]);
    }

    /**
     * Remove the specified employee default allowance.
     */
    public function destroy(EmployeeDefaultAllowance $employeeDefaultAllowance)
    {
        $employeeDefaultAllowance->delete();

        return response()->json([
            'message' => 'Employee default allowance deleted successfully'
        ]);
    }
}

