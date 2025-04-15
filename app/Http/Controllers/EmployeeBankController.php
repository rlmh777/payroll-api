<?php

namespace App\Http\Controllers;

use App\Models\EmployeeBank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeBankController extends Controller
{
    /**
     * Display a listing of employee banks.
     */
    public function index(Request $request)
    {
        $query = EmployeeBank::with(['employee', 'bank']);

        // Filter by employee
        if ($request->has('employee_id')) {
            $query->where('employeeId', $request->input('employee_id'));
        }

        // Filter by bank
        if ($request->has('bank_id')) {
            $query->where('bankId', $request->input('bank_id'));
        }

        // Search by account number or notes
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('accountNumber', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%");
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate
        $perPage = $request->input('per_page', 10);
        $employeeBanks = $query->paginate($perPage);

        return response()->json($employeeBanks);
    }

    /**
     * Store a newly created employee bank.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employeeId' => 'required|uuid|exists:employee,id',
            'bankId' => 'required|uuid|exists:bank,id',
            'accountNumber' => 'required|string|max:255',
            'notes' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employeeBank = EmployeeBank::create($request->all());

        return response()->json([
            'message' => 'Employee bank created successfully',
            'data' => $employeeBank->load(['employee', 'bank'])
        ], 201);
    }

    /**
     * Display the specified employee bank.
     */
    public function show(EmployeeBank $employeeBank)
    {
        return response()->json($employeeBank->load(['employee', 'bank']));
    }

    /**
     * Update the specified employee bank.
     */
    public function update(Request $request, EmployeeBank $employeeBank)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'employeeId' => 'uuid|exists:employee,id',
            'bankId' => 'uuid|exists:bank,id',
            'accountNumber' => 'string|max:255',
            'notes' => 'string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employeeBank->update($request->all());

        return response()->json([
            'message' => 'Employee bank updated successfully',
            'data' => $employeeBank->load(['employee', 'bank'])
        ]);
    }

    /**
     * Remove the specified employee bank.
     */
    public function destroy(EmployeeBank $employeeBank)
    {
        $employeeBank->delete();

        return response()->json([
            'message' => 'Employee bank deleted successfully'
        ]);
    }
} 