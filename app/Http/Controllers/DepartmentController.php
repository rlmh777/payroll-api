<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    /**
     * Display a listing of departments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Department::query();

        // Search by name
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ilike', "%{$search}%");
        }

        // Filter by parent department
        if ($request->has('parent_id')) {
            $query->where('parentId', $request->parent_id);
        }

        // Sorting
        $sortField = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');

        if (in_array($sortField, ['name'])) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('name', 'asc');
        }

        // Pagination
        $perPage = $request->get('per_page', 10);
        $departments = $query->paginate($perPage);

        return response()->json($departments);
    }

    /**
     * Store a newly created department.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:256',
                'parentId' => 'nullable|exists:department,id',
            ]);

            $department = Department::create($validatedData);
            return response()->json([
                'message' => 'Department created successfully',
                'data' => $department
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified department.
     */
    public function show(Department $department): JsonResponse
    {
        return response()->json($department, 200);
    }

    /**
     * Update the specified department.
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        try {
            // If request is empty, return early with current data
            if (empty($request->all())) {
                return response()->json([
                    'message' => 'No data provided for update',
                    'data' => $department
                ], 200);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:256',
                'parentId' => 'sometimes|nullable|exists:department,id',
            ]);

            // Prevent circular reference
            if (isset($validatedData['parentId']) && $validatedData['parentId'] == $department->id) {
                return response()->json([
                    'error' => 'A department cannot be its own parent'
                ], 422);
            }

            $department->update($validatedData);
            return response()->json([
                'message' => 'Department updated successfully',
                'data' => $department
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified department.
     */
    public function destroy(Department $department): JsonResponse
    {
        // Check if department has children
        if ($department->children()->exists()) {
            return response()->json([
                'error' => 'Cannot delete department with child departments'
            ], 422);
        }

        // Check if department has employment details
        if ($department->employmentDetails()->exists()) {
            return response()->json([
                'error' => 'Cannot delete department with associated employment details'
            ], 422);
        }

        $department->delete();
        return response()->json(['message' => 'Department deleted successfully.']);
    }
}