<?php

namespace App\Http\Controllers;

use App\Models\EmployeeStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeStatusController extends Controller
{
    /**
     * Display a listing of employee statuses.
     */
    public function index(Request $request)
    {
        $query = EmployeeStatus::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Sort
        $sortBy = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate
        $perPage = $request->input('per_page', 10);
        $employeeStatuses = $query->paginate($perPage);

        return response()->json($employeeStatuses);
    }

    /**
     * Store a newly created employee status.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:employee_status,name'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employeeStatus = EmployeeStatus::create($request->all());

        return response()->json([
            'message' => 'Employee status created successfully',
            'data' => $employeeStatus
        ], 201);
    }

    /**
     * Display the specified employee status.
     */
    public function show(EmployeeStatus $employeeStatus)
    {
        return response()->json($employeeStatus);
    }

    /**
     * Update the specified employee status.
     */
    public function update(Request $request, EmployeeStatus $employeeStatus)
    {
        if ($request->isMethod('put') && empty($request->all())) {
            return response()->json(['message' => 'No data provided for update'], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:employee_status,name,' . $employeeStatus->id
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employeeStatus->update($request->all());

        return response()->json([
            'message' => 'Employee status updated successfully',
            'data' => $employeeStatus
        ]);
    }

    /**
     * Remove the specified employee status.
     */
    public function destroy(EmployeeStatus $employeeStatus)
    {
        // Check if there are any associated employee details
        if ($employeeStatus->employeeDetails()->exists()) {
            return response()->json([
                'message' => 'Cannot delete employee status with associated employee details'
            ], 422);
        }

        $employeeStatus->delete();

        return response()->json([
            'message' => 'Employee status deleted successfully'
        ]);
    }
} 